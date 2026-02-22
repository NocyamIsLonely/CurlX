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
     * Gera pessoa brasileira via 4devs
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
     * !!! Not tested
     * Gera pessoa (EUA) via randomuser.me
     *   $p = $Tools->generatePersonUS();
     *   echo $p['first_name'];
     *   echo $p['email'];
     */
    public function generatePersonUS(): array
    {
        $r = $this->http->get('https://randomuser.me/api/?nat=us');
        $data = $r->jsonSafe();
        
        $user = $data['results'][0] ?? null;
        if (!$user) {
            return [];
        }
        
        return [
            'gender'     => $user['gender'] ?? '',
            'title'      => $user['name']['title'] ?? '',
            'first_name' => $user['name']['first'] ?? '',
            'last_name'  => $user['name']['last'] ?? '',
            'name'       => trim(($user['name']['first'] ?? '') . ' ' . ($user['name']['last'] ?? '')),
            'email'      => $user['email'] ?? '',
            'username'   => $user['login']['username'] ?? '',
            'password'   => $user['login']['password'] ?? '',
            'dob'        => $user['dob']['date'] ?? '',
            'age'        => $user['dob']['age'] ?? '',
            'phone'      => $user['phone'] ?? '',
            'cell'       => $user['cell'] ?? '',
            'street'     => trim(($user['location']['street']['number'] ?? '') . ' ' . ($user['location']['street']['name'] ?? '')),
            'city'       => $user['location']['city'] ?? '',
            'state'      => $user['location']['state'] ?? '',
            'country'    => $user['location']['country'] ?? '',
            'postcode'   => (string)($user['location']['postcode'] ?? ''),
            'latitude'   => $user['location']['coordinates']['latitude'] ?? '',
            'longitude'  => $user['location']['coordinates']['longitude'] ?? '',
            'picture'    => $user['picture']['large'] ?? '',
            'nat'        => $user['nat'] ?? 'US',
        ];
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

    /**
     *   $Tools->generateUUID() // '550e8400-e29b-41d4-a716-446655440000'
     */
    public function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     *   $Tools->randomUserAgent()             // desktop Chrome/Firefox/Edge/Safari
     *   $Tools->randomUserAgent('mobile')     // Android e iPhone
     *   $Tools->randomUserAgent('chrome')     // só Chrome (desktop)
     *   $Tools->randomUserAgent('firefox')    // só Firefox (desktop)
     *   $Tools->randomUserAgent('safari')     // só Safari (desktop/mac)
     *   $Tools->randomUserAgent('edge')       // só Edge
     *   $Tools->randomUserAgent('all')        // tudo junto
     *   $Tools->randomUserAgent('desktop', 'meus_uas.txt') // carrega de arquivo
     */
    public function randomUserAgent(string $device = 'desktop', string $file = ''): string
    {
        if ($file !== '' && file_exists($file)) {
            $lines = array_filter(
                file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
                fn($l) => !str_starts_with(trim($l), '#') && str_contains($l, 'Mozilla')
            );
            if (!empty($lines)) {
                $pool = array_values($lines);
                if ($device === 'mobile') {
                    $mobile = array_values(array_filter($pool, fn($ua) =>
                        str_contains($ua, 'Android') || str_contains($ua, 'iPhone') || str_contains($ua, 'Mobile')
                    ));
                    if (!empty($mobile)) return trim($mobile[array_rand($mobile)]);
                }
                return trim($pool[array_rand($pool)]);
            }
        }

        $chrome_win = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ];

        $chrome_mac = [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ];

        $firefox = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:123.0) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
        ];

        $safari = [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        ];

        $edge = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
        ];

        $mobile_android = [
            'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 13; SM-S908B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 12; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        ];

        $mobile_iphone = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_7_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        ];

        $desktop = array_merge($chrome_win, $chrome_mac, $firefox, $safari, $edge);
        $mobile   = array_merge($mobile_android, $mobile_iphone);

        return match($device) {
            'mobile'  => $mobile[array_rand($mobile)],
            'chrome'  => array_merge($chrome_win, $chrome_mac)[array_rand(array_merge($chrome_win, $chrome_mac))],
            'firefox' => $firefox[array_rand($firefox)],
            'safari'  => $safari[array_rand($safari)],
            'edge'    => $edge[array_rand($edge)],
            'android' => $mobile_android[array_rand($mobile_android)],
            'iphone'  => $mobile_iphone[array_rand($mobile_iphone)],
            'all'     => array_merge($desktop, $mobile)[array_rand(array_merge($desktop, $mobile))],
            default    => $desktop[array_rand($desktop)],
        };
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
