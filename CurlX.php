<?php


/**
 * CurlX — Based on devblack/curlx
 * https://github.com/devblack/curlx
 * rewritten by Nocyam
*/

declare(strict_types=1);

class CurlXException extends RuntimeException {}

class CookieJar
{
    private string $path;

    public function __construct(string $name = '', string $dir = '')
    {
        $dir  = $dir ?: sys_get_temp_dir();
        $name = $name ?: ('curlx_' . uniqid());
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        $this->path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '.txt';

        if (!file_exists($this->path)) {
            if (false === @touch($this->path)) {
                throw new CurlXException("Cannot create cookie file: {$this->path}");
            }
            chmod($this->path, 0644);
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function delete(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    public function clear(): void
    {
        file_put_contents($this->path, '');
    }

    public function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $cookies = [];
        $lines   = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) >= 7) {
                $cookies[$parts[5]] = $parts[6];
            }
        }

        return $cookies;
    }

    public function __toString(): string
    {
        return $this->path;
    }
}

// Response

class CurlXResponse
{
    public readonly string      $body;
    public readonly int         $status;
    public readonly array       $headers;
    public readonly float       $time;
    public readonly string|null $error;
    public readonly array       $history;

    public function __construct(
        string      $body,
        int         $status,
        array       $headers,
        float       $time,
        string|null $error   = null,
        array       $history = []
    ) {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
        $this->time    = $time;
        $this->error   = $error;
        $this->history = $history;
    }

    // Status

    /** true para status 2xx */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Alias de ok() */
    public function isSuccess(): bool
    {
        return $this->ok();
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
    
    public function finalUrl(): string
    {
        return end($this->history) ?: '';
    }

    // JSON

    public function json(bool $assoc = true): mixed
    {
        $decoded = json_decode($this->body, $assoc);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CurlXException('Response is not valid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    public function jsonSafe(bool $assoc = true): mixed
    {
        $decoded = json_decode($this->body, $assoc);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     *   $r->jsonGet('id')
     *   $r->jsonGet('token', '')
     */
    public function jsonGet(string $key, mixed $default = null): mixed
    {
        $data = $this->jsonSafe();
        if (!is_array($data)) {
            return $default;
        }
        return $data[$key] ?? $default;
    }

    public function isJson(): bool
    {
        json_decode($this->body);
        return json_last_error() === JSON_ERROR_NONE;
    }

    // Extração de texto 

    public function between(string $start, string $end): string
    {
        $str = explode($start, $this->body, 2);

        if (!isset($str[1])) {
            return '';
        }

        $str = explode($end, $str[1], 2);
        return $str[0];
    }

    /**
     *   $r->betweenAll('<a href="', '"')
     */
    public function betweenAll(string $start, string $end): array
    {
        $results = [];
        $subject = $this->body;

        while (true) {
            $parts = explode($start, $subject, 2);

            if (!isset($parts[1])) {
                break;
            }

            $parts2 = explode($end, $parts[1], 2);

            if (!isset($parts2[0])) {
                break;
            }

            $results[] = $parts2[0];
            $subject   = $parts2[1] ?? '';
        }

        return $results;
    }

    public function match(string $pattern): string
    {
        preg_match($pattern, $this->body, $m);
        return $m[1] ?? '';
    }

    public function matchAll(string $pattern): array
    {
        preg_match_all($pattern, $this->body, $m);
        return $m[1] ?? [];
    }

    public function contains(string $needle, bool $caseSensitive = true): bool
    {
        return $caseSensitive
            ? str_contains($this->body, $needle)
            : str_contains(strtolower($this->body), strtolower($needle));
    }

    // Debug

    public function dump(): static
    {
        $isCli = PHP_SAPI === 'cli';
        $nl    = $isCli ? "\n" : "<br>";
        $sep   = str_repeat('─', 60);

        echo $sep . $nl;
        echo "Status  : {$this->status}" . $nl;
        echo "Time    : {$this->time}s" . $nl;
        echo "Headers :" . $nl;

        foreach ($this->headers as $k => $v) {
            echo "  $k: $v" . $nl;
        }

        echo "Body    :" . $nl;
        echo mb_substr($this->body, 0, 800) . (mb_strlen($this->body) > 800 ? '...' : '') . $nl;
        echo $sep . $nl;

        return $this;
    }

    public function __toString(): string
    {
        return $this->body;
    }
}

// CurlX

class CurlX
{
    private array $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
    ];

    private array $extraOpts     = [];
    private array $globalHeaders = [];
    private int   $retryCount    = 0;
    private int   $retryDelay    = 1000;
    private array $retryOn       = [429, 500, 502, 503, 504];
    private bool  $debugMode     = false;


    public function setOpt(array $opts): static
    {
        $this->extraOpts = array_replace($this->extraOpts, $opts);
        return $this;
    }

    public function setUserAgent(string $ua): static
    {
        $this->extraOpts[CURLOPT_USERAGENT] = $ua;
        return $this;
    }

    public function setTimeout(int $connect = 30, int $total = 60): static
    {
        $this->extraOpts[CURLOPT_CONNECTTIMEOUT] = $connect;
        $this->extraOpts[CURLOPT_TIMEOUT]        = $total;
        return $this;
    }
    
    public function setHeaders(array $headers): static
    {
        $this->globalHeaders = $headers;
        return $this;
    }

    public function debug(bool $enable = true): static
    {
        $this->debugMode = $enable;
        return $this;
    }

    /**
     *   $CurlX->retry(3);               // 3 tentativas, 1s entre elas
     *   $CurlX->retry(3, 2000);         // 3 tentativas, 2s entre elas
     *   $CurlX->retry(3, 1000, [500]);  // só retry em status 500
     */
    public function retry(int $times, int $delayMs = 1000, array $onStatus = []): static
    {
        $this->retryCount = $times;
        $this->retryDelay = $delayMs;
        if (!empty($onStatus)) {
            $this->retryOn = $onStatus;
        }
        return $this;
    }


    public function get(
        string                $url,
        array                 $headers = [],
        string|CookieJar|null $cookie  = null,
        array|null            $proxy   = null
    ): CurlXResponse {
        return $this->requestWithRetry('GET', $url, null, $headers, $cookie, $proxy);
    }

    public function post(
        string                $url,
        string|array|null     $data    = null,
        array                 $headers = [],
        string|CookieJar|null $cookie  = null,
        array|null            $proxy   = null
    ): CurlXResponse {
        return $this->requestWithRetry('POST', $url, $data, $headers, $cookie, $proxy);
    }

    public function put(
        string                $url,
        string|array|null     $data    = null,
        array                 $headers = [],
        string|CookieJar|null $cookie  = null,
        array|null            $proxy   = null
    ): CurlXResponse {
        return $this->requestWithRetry('PUT', $url, $data, $headers, $cookie, $proxy);
    }

    public function patch(
        string                $url,
        string|array|null     $data    = null,
        array                 $headers = [],
        string|CookieJar|null $cookie  = null,
        array|null            $proxy   = null
    ): CurlXResponse {
        return $this->requestWithRetry('PATCH', $url, $data, $headers, $cookie, $proxy);
    }

    public function delete(
        string                $url,
        array                 $headers = [],
        string|CookieJar|null $cookie  = null,
        array|null            $proxy   = null
    ): CurlXResponse {
        return $this->requestWithRetry('DELETE', $url, null, $headers, $cookie, $proxy);
    }

    public function custom(
        string                $url,
        string                $method,
        string|array|null     $data    = null,
        array                 $headers = [],
        string|CookieJar|null $cookie  = null,
        array|null            $proxy   = null
    ): CurlXResponse {
        return $this->requestWithRetry(strtoupper($method), $url, $data, $headers, $cookie, $proxy);
    }

    public function multipart(
        string                $url,
        array                 $fields,
        array                 $headers = [],
        string|CookieJar|null $cookie  = null,
        array|null            $proxy   = null
    ): CurlXResponse {
        $postFields = [];

        foreach ($fields as $key => $value) {
            if (is_string($value) && file_exists($value)) {
                $postFields[$key] = new CURLFile($value);
            } else {
                $postFields[$key] = $value;
            }
        }

        $headers = array_values(array_filter($headers, fn($h) =>
            !str_starts_with(strtolower($h), 'content-type:')
        ));

        return $this->requestWithRetry('POST', $url, $postFields, $headers, $cookie, $proxy);
    }

    public function parallel(array $requests): array
    {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($requests as $i => $req) {
            $method  = $req[0];
            $url     = $req[1];
            $data    = $req[2] ?? null;
            $headers = $req[3] ?? [];
            $cookie  = $req[4] ?? null;
            $proxy   = $req[5] ?? null;

            $ch = $this->buildHandle($method, $url, $data, $headers, $cookie, $proxy);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $responses = [];

        foreach ($handles as $i => $ch) {
            $raw   = curl_multi_getcontent($ch);
            $info  = curl_getinfo($ch);
            $error = curl_error($ch) ?: null;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($raw === false || $raw === null) {
                $responses[$i] = new CurlXResponse('', 0, [], 0.0, $error ?? 'parallel request failed');
                continue;
            }

            [$parsedHeaders, $body] = $this->splitResponse($raw, (int) $info['header_size']);

            $responses[$i] = new CurlXResponse(
                body:    $body,
                status:  (int) $info['http_code'],
                headers: $parsedHeaders,
                time:    (float) $info['total_time'],
                error:   $error
            );
        }

        curl_multi_close($mh);

        ksort($responses);
        return $responses;
    }

    public function waitFor(
        string                $url,
        string                $needle,
        array                 $headers    = [],
        int                   $maxRetries = 20,
        int                   $intervalMs = 3000,
        string|CookieJar|null $cookie     = null
    ): CurlXResponse|null {
        for ($i = 0; $i < $maxRetries; $i++) {
            $r = $this->get($url, $headers, $cookie);

            if ($r->contains($needle)) {
                return $r;
            }

            if ($i < $maxRetries - 1) {
                usleep($intervalMs * 1000);
            }
        }

        return null;
    }

    private function requestWithRetry(
        string                $method,
        string                $url,
        string|array|null     $data,
        array                 $headers,
        string|CookieJar|null $cookie,
        array|null            $proxy
    ): CurlXResponse {
        $attempts = $this->retryCount + 1;
        $last     = null;

        for ($i = 0; $i < $attempts; $i++) {
            $last = $this->request($method, $url, $data, $headers, $cookie, $proxy);

            if (!in_array($last->status, $this->retryOn, true)) {
                break;
            }

            if ($i < $attempts - 1) {
                usleep($this->retryDelay * 1000);
            }
        }

        return $last;
    }

    private function request(
        string                $method,
        string                $url,
        string|array|null     $data,
        array                 $headers,
        string|CookieJar|null $cookie,
        array|null            $proxy
    ): CurlXResponse {
        $ch    = $this->buildHandle($method, $url, $data, $headers, $cookie, $proxy);
        $raw   = curl_exec($ch);
        $error = curl_error($ch) ?: null;
        $info  = curl_getinfo($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new CurlXException("cURL error: " . ($error ?? 'unknown'));
        }

        [$parsedHeaders, $body] = $this->splitResponse((string) $raw, (int) $info['header_size']);

        $response = new CurlXResponse(
            body:    $body,
            status:  (int) $info['http_code'],
            headers: $parsedHeaders,
            time:    (float) $info['total_time'],
            error:   $error,
            history: array_filter([$info['redirect_url'] ?? ''])
        );

        if ($this->debugMode) {
            $this->printDebug($method, $url, $response);
        }

        return $response;
    }

    private function buildHandle(
        string                $method,
        string                $url,
        string|array|null     $data,
        array                 $headers,
        string|CookieJar|null $cookie,
        array|null            $proxy
    ): \CurlHandle {
        $ch   = curl_init();
        $opts = $this->defaults;

        $opts[CURLOPT_URL]           = $url;
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($data !== null && $data !== '') {
            if (is_array($data)) {
                $hasFile = !empty(array_filter($data, fn($v) => $v instanceof CURLFile));

                if ($hasFile) {
                    $opts[CURLOPT_POSTFIELDS] = $data;
                } else {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                    $headers = $this->ensureHeader($headers, 'Content-Type', 'application/json');
                }
            } else {
                $opts[CURLOPT_POSTFIELDS] = $data;
            }
        }

        // Merge headers globais + locais (locais têm prioridade)
        $merged = $this->mergeHeaders($this->globalHeaders, $headers);
        if (!empty($merged)) {
            $opts[CURLOPT_HTTPHEADER] = $merged;
        }
        if ($cookie !== null) {
            $cookiePath = $cookie instanceof CookieJar
                ? $cookie->getPath()
                : $this->resolveCookiePath((string) $cookie);

            $opts[CURLOPT_COOKIEJAR]  = $cookiePath;
            $opts[CURLOPT_COOKIEFILE] = $cookiePath;
        }
        if ($proxy !== null) {
            $this->applyProxy($opts, $proxy);
        }

        $opts = array_replace($opts, $this->extraOpts);

        curl_setopt_array($ch, $opts);

        return $ch;
    }

    private function splitResponse(string $raw, int $headerSize): array
    {
        $headerBlock = substr($raw, 0, $headerSize);
        $body        = substr($raw, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return [$headers, $body];
    }

    private function ensureHeader(array $headers, string $name, string $value): array
    {
        $nameLower = strtolower($name);
        foreach ($headers as $h) {
            if (str_starts_with(strtolower($h), $nameLower . ':')) {
                return $headers;
            }
        }
        $headers[] = "$name: $value";
        return $headers;
    }

    private function mergeHeaders(array $global, array $local): array
    {
        $merged = $global;

        foreach ($local as $localHeader) {
            $localName = strtolower(explode(':', $localHeader, 2)[0]);
            $merged    = array_filter($merged, fn($h) =>
                strtolower(explode(':', $h, 2)[0]) !== $localName
            );
            $merged[] = $localHeader;
        }

        return array_values($merged);
    }

    private function resolveCookiePath(string $name): string
    {
        if (str_contains($name, DIRECTORY_SEPARATOR) || str_ends_with($name, '.txt')) {
            if (!file_exists($name)) {
                touch($name);
                chmod($name, 0644);
            }
            return $name;
        }

        $path = getcwd() . DIRECTORY_SEPARATOR . $name . '.txt';
        if (!file_exists($path)) {
            touch($path);
            chmod($path, 0644);
        }
        return $path;
    }

    private function applyProxy(array &$opts, array $proxy): void
    {
        $method = strtolower($proxy['method'] ?? 'tunnel');
        $server = $proxy['server'] ?? '';
        $auth   = $proxy['auth']   ?? '';

        if ($method === 'tunnel') {
            $opts[CURLOPT_PROXY]     = $server;
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        } elseif (in_array($method, ['socks4', 'socks5'], true)) {
            $opts[CURLOPT_PROXY]     = $server;
            $opts[CURLOPT_PROXYTYPE] = $method === 'socks5' ? CURLPROXY_SOCKS5 : CURLPROXY_SOCKS4;
        } else {
            $opts[CURLOPT_PROXY] = $server;
        }

        if ($auth !== '') {
            $opts[CURLOPT_PROXYUSERPWD] = $auth;
        }
    }

    private function printDebug(string $method, string $url, CurlXResponse $r): void
    {
        $isCli = PHP_SAPI === 'cli';
        $nl    = $isCli ? "\n" : "<br>";
        $sep   = str_repeat('─', 60);

        echo $sep . $nl;
        echo "[CurlX] $method $url" . $nl;
        echo "Status : {$r->status}" . $nl;
        echo "Time   : {$r->time}s" . $nl;
        echo "Body   : " . mb_substr($r->body, 0, 500) . (mb_strlen($r->body) > 500 ? '...' : '') . $nl;
        echo $sep . $nl;
    }
}
