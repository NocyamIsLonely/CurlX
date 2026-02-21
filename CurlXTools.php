<?php

declare(strict_types=1);

class CurlXTools
{
    private CurlX $http;

    public function __construct(CurlX $http)
    {
        $this->http = $http;
    }

    public function parseCard(string $input): ?array
    {
        $clean = preg_replace('/[\s:;|,=>\\/]+/', '|', trim($input));

        if (!preg_match('/([0-9]{15,16})\|([0-9]{1,2})\|([0-9]{2,4})\|([0-9]{3,4})/', $clean, $m)) {
            return null;
        }

        $ano = $m[3];

        return [
            'cc'     => $m[1],
            'mes'    => str_pad($m[2], 2, '0', STR_PAD_LEFT),
            'ano'    => $ano,
            'ano2'   => strlen($ano) === 4 ? substr($ano, -2) : $ano,
            'ano4'   => strlen($ano) === 2 ? '20' . $ano : $ano,
            'cvv'    => $m[4],
            'full'   => "{$m[1]}|{$m[2]}|{$ano}|{$m[4]}",
            'bin'    => substr($m[1], 0, 6),
            'brand'  => $this->detectBrand($m[1]),
        ];
    }

    /**
     *   $Tools->isLuhnValid('4556010011223344') // true
     */
    public function isLuhnValid(string $cc): bool
    {
        $cc  = preg_replace('/\D/', '', $cc);
        $sum = 0;
        $len = strlen($cc);
        $par = $len % 2;

        for ($i = 0; $i < $len; $i++) {
            $d = (int) $cc[$i];
            if ($i % 2 === $par) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }

        return ($sum % 10) === 0;
    }

    public function detectBrand(string $cc): string
    {
        $cc = preg_replace('/\D/', '', $cc);

        $patterns = [
            'amex'      => '/^3[47]/',
            'elo'       => '/^(4011|4312|4389|4514|4576|5041|5066|5067|509|6277|6362|6363|650|6516|6550)/',
            'hipercard' => '/^(3841|60)/',
            'discover'  => '/^6(?:011|5)/',
            'mastercard'=> '/^5[1-5]|^2(2[2-9][1-9]|[3-6]|7[01])/',
            'visa'      => '/^4/',
        ];

        foreach ($patterns as $brand => $pattern) {
            if (preg_match($pattern, $cc)) {
                return $brand;
            }
        }

        return 'unknown';
    }

    public function bin(string $cc): array
    {
        $bin = substr(preg_replace('/\D/', '', $cc), 0, 6);

        $r = $this->http->post(
            'https://app.fluidpay.com/api/lookup/bin/pub_2HT17PrC7sOCvNp1qwb9XBhb1RO',
            ['type' => 'tokenizer', 'type_id' => '230685b9-61e6-4dc4-8cb2-18ef6fd93146', 'bin' => $bin],
            ['Authorization: pub_2HT17PrC7sOCvNp1qwb9XBhb1RO']
        );

        if ($r->jsonGet('status') !== 'success') {
            return ['success' => false, 'message' => 'BIN não encontrada'];
        }

        $d = $r->jsonGet('data', []);

        return [
            'success' => true,
            'vendor'  => $d['card_brand']         ?? 'Desconhecido',
            'bank'    => $d['issuing_bank']        ?? 'Desconhecido',
            'level'   => $d['card_level_generic']  ?? 'Desconhecido',
            'type'    => strtoupper($d['card_type'] ?? 'CREDIT'),
            'country' => $d['country']             ?? 'US',
            'full'    => strtoupper(
                ($d['card_brand'] ?? '?') . ' - ' .
                ($d['issuing_bank'] ?? '?') . ' - ' .
                ($d['card_level_generic'] ?? '?') . ' - ' .
                ($d['country'] ?? '?')
            ),
        ];
    }

    // GERAÇÃO DE DADOS

    /**
     *   $Tools->generateCPF()        // '12345678909'
     *   $Tools->generateCPF(true)    // '123.456.789-09'
     */
    public function generateCPF(bool $format = false): string
    {
        $n = array_map(fn() => rand(0, 9), range(1, 9));

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $n[$c] * (($t + 1) - $c);
            }
            $n[] = ((10 * $d) % 11) % 10;
        }

        $cpf = implode('', $n);

        return $format
            ? substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
            : $cpf;
    }

    /**
     *   $Tools->generateCNPJ()       // '12345678000195'
     *   $Tools->generateCNPJ(true)   // '12.345.678/0001-95'
     */
    public function generateCNPJ(bool $format = false): string
    {
        $n = array_map(fn() => rand(0, 9), range(1, 8));
        $n = array_merge($n, [0, 0, 0, 1]);

        $calc = function(array $nums, int $len): int {
            $weights = range($len + 1, 2);
            $sum     = 0;
            for ($i = 0; $i < $len; $i++) {
                $sum += $nums[$i] * $weights[$i];
            }
            $rem = $sum % 11;
            return $rem < 2 ? 0 : 11 - $rem;
        };

        $n[] = $calc($n, 12);
        $n[] = $calc($n, 13);

        $cnpj = implode('', $n);

        return $format
            ? substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2)
            : $cnpj;
    }

    /**
     *   $p = $Tools->generatePerson();
     *   echo $p['nome'];
     *   echo $p['cpf'];
     *   echo $p['endereco'];
     */
    public function generatePerson(): array
    {
        $r = $this->http->post(
            'https://www.4devs.com.br/ferramentas_online.php',
            'acao=gerar_pessoa&sexo=I&pontuacao=N&idade=0&cep_estado=&txt_qtde=1&cep_cidade=',
            ['referer: https://www.4devs.com.br/gerador_de_pessoas']
        );

        $data = $r->jsonSafe();
        return $data[0] ?? [];
    }

    /**
     *   $Tools->randomEmail()           // 'user83921748263@gmail.com'
     *   $Tools->randomEmail('nocyam')   // 'nocyam83921748263@hotmail.com'
     */
    public function randomEmail(string $prefix = 'user'): string
    {
        $domains = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com'];
        return $prefix . rand(1000, 9999) . time() . '@' . $domains[array_rand($domains)];
    }

    /**
     *   $Tools->randomString()     // 'aB3kR7mX9z'
     *   $Tools->randomString(16)   // string de 16 chars
     *   $Tools->randomString(8, 'num')    // só números
     *   $Tools->randomString(8, 'lower')  // só minúsculas
     *   $Tools->randomString(8, 'upper')  // só maiúsculas
     *   $Tools->randomString(8, 'alpha')  // letras
     *   $Tools->randomString(8, 'alnum')  // padrão: letras + números
     */
    public function randomString(int $length = 10, string $type = 'alnum'): string
    {
        $chars = match($type) {
            'num'   => '0123456789',
            'lower' => 'abcdefghijklmnopqrstuvwxyz',
            'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'alpha' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            default => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
        };

        $result = '';
        $max    = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[rand(0, $max)];
        }
        return $result;
    }

    // PROXY 
    /**
     *   $Tools->formatProxy('192.168.1.1:8080')
     *   $Tools->formatProxy('192.168.1.1:8080:user:pass')
     *   $Tools->formatProxy('192.168.1.1:1080', 'socks5')
     */
    public function formatProxy(string $proxyStr, string $method = 'tunnel'): ?array
    {
        $parts = explode(':', $proxyStr);

        if (count($parts) === 2) {
            return ['method' => $method, 'server' => "{$parts[0]}:{$parts[1]}"];
        }

        if (count($parts) === 4) {
            return [
                'method' => $method,
                'server' => "{$parts[0]}:{$parts[1]}",
                'auth'   => "{$parts[2]}:{$parts[3]}",
            ];
        }

        return null;
    }

    /**
     * Suporta ip:porta e ip:porta:user:pass.
     *   $proxy = $Tools->randomProxy('proxies.txt');
     *   $CurlX->get($url, [], null, $proxy);
     */
    public function randomProxy(string $file, string $method = 'tunnel'): ?array
    {
        if (!file_exists($file)) {
            return null;
        }

        $lines = array_filter(
            file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            fn($l) => !str_starts_with(trim($l), '#')
        );

        if (empty($lines)) {
            return null;
        }

        return $this->formatProxy(trim($lines[array_rand($lines)]), $method);
    }

    // LOGS

    /**
     * cx2/log save
     *   $Tools->saveLog('hits.txt', "LIVE: $full | $binInfo");
     */
    public function saveLog(string $filename, string $content): void
    {
        file_put_contents($filename, $content . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function saveLogTs(string $filename, string $content): void
    {
        $ts = date('Y-m-d H:i:s');
        $this->saveLog($filename, "[$ts] $content");
    }

    public function countLog(string $filename): int
    {
        if (!file_exists($filename)) {
            return 0;
        }
        return count(file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    // AUXILIAR GETSTR

    /**
     * Prefira USAR $r->between() quando estiver no objeto de resposta
     */
    public function getStr(string $string, string $start, string $end): string
    {
        $str = explode($start, $string, 2);
        if (!isset($str[1])) return '';
        $str = explode($end, $str[1], 2);
        return $str[0];
    }

    /**
     *   $texto = $Tools->cleanHtml($r->body);
     */
    public function cleanHtml(string $html): string
    {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<[^>]*>/', ' ', $html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/\s+/', ' ', $html);
        return trim($html);
    }

    /**
     *   $Tools->queryToArray('a=1&b=2')   // ['a' => '1', 'b' => '2']
     *   $Tools->arrayToQuery(['a' => '1']) // 'a=1'
     */
    public function queryToArray(string $query): array
    {
        parse_str($query, $result);
        return $result;
    }

    public function arrayToQuery(array $data): string
    {
        return http_build_query($data);
    }
}
