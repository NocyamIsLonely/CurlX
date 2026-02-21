<?php

declare(strict_types=1);

class CurlXBypass
{
    private CurlX  $http;
    private string $service;
    private string $apiKey;

    private const SERVICES = ['2captcha', 'capmonster', 'capsolver', 'anticaptcha'];
    private const ENDPOINTS = [
        '2captcha'   => ['in' => 'https://2captcha.com/in.php',      'res' => 'https://2captcha.com/res.php'],
        'capmonster' => ['in' => 'https://api.capmonster.cloud/createTask', 'res' => 'https://api.capmonster.cloud/getTaskResult'],
        'capsolver'  => ['in' => 'https://api.capsolver.com/createTask',    'res' => 'https://api.capsolver.com/getTaskResult'],
        'anticaptcha'=> ['in' => 'https://api.anti-captcha.com/createTask', 'res' => 'https://api.anti-captcha.com/getTaskResult'],
    ];

    public function __construct(CurlX $http, string $service, string $apiKey)
    {
        $service = strtolower($service);

        if (!in_array($service, self::SERVICES, true)) {
            throw new CurlXException("Serviço inválido. Use: " . implode(', ', self::SERVICES));
        }

        $this->http    = $http;
        $this->service = $service;
        $this->apiKey  = $apiKey;
    }

    // CLOUDFLARE

    public function solveCloudflare(string $url, array $headers = []): CookieJar
    {
        $page    = $this->http->get($url, $headers);
        $siteKey = $page->match('/data-sitekey=["\']([^"\']+)["\']/i');

        if (!$siteKey) {
            $siteKey = $page->match('/turnstile.*?sitekey["\s:=]+["\']([^"\']+)["\']/i');
        }

        if (!$siteKey) {
            throw new CurlXException("sitekey ausente: $url");
        }

        $token = $this->turnstile($siteKey, $url);
        $cookie = new CookieJar('cf_' . md5($url));

        $this->http->post($url, [
            'cf-turnstile-response' => $token,
        ], array_merge($headers, [
            'content-type: application/x-www-form-urlencoded',
        ]), $cookie);

        return $cookie;
    }

    // TURNSTILE (Cloudflare)

    public function turnstile(string $siteKey, string $url): string
    {
        return match($this->service) {
            '2captcha'    => $this->solve2captcha('turnstile', $siteKey, $url),
            'capmonster'  => $this->solveTaskBased('TurnstileTaskProxyless', $siteKey, $url),
            'capsolver'   => $this->solveTaskBased('AntiTurnstileTaskProxyLess', $siteKey, $url),
            'anticaptcha' => $this->solveTaskBased('TurnstileTask', $siteKey, $url),
        };
    }

    // RECAPTCHA V2
    
    public function recaptchaV2(string $siteKey, string $url, bool $invisible = false): string
    {
        return match($this->service) {
            '2captcha'    => $this->solve2captcha('userrecaptcha', $siteKey, $url, ['invisible' => $invisible ? 1 : 0]),
            'capmonster'  => $this->solveTaskBased($invisible ? 'RecaptchaV2TaskProxyless' : 'RecaptchaV2TaskProxyless', $siteKey, $url),
            'capsolver'   => $this->solveTaskBased('ReCaptchaV2TaskProxyLess', $siteKey, $url),
            'anticaptcha' => $this->solveTaskBased('RecaptchaV2TaskProxyless', $siteKey, $url),
        };
    }

    // RECAPTCHA V3

    /**
     *   $token = $Bypass->recaptchaV3('SITE_KEY', 'https://site.com/', 'submit', 0.3);
     */
    public function recaptchaV3(string $siteKey, string $url, string $action = 'verify', float $minScore = 0.7): string
    {
        return match($this->service) {
            '2captcha'    => $this->solve2captcha('userrecaptcha', $siteKey, $url, [
                'version' => 'v3',
                'action'  => $action,
                'min_score' => $minScore,
            ]),
            'capmonster'  => $this->solveTaskBased('RecaptchaV3TaskProxyless', $siteKey, $url, [
                'minScore'  => $minScore,
                'pageAction'=> $action,
            ]),
            'capsolver'   => $this->solveTaskBased('ReCaptchaV3TaskProxyLess', $siteKey, $url, [
                'minScore'  => $minScore,
                'pageAction'=> $action,
            ]),
            'anticaptcha' => $this->solveTaskBased('RecaptchaV3TaskProxyless', $siteKey, $url, [
                'minScore'  => $minScore,
                'pageAction'=> $action,
            ]),
        };
    }

    // HCAPTCHA
    /**
     *   $token = $Bypass->hcaptcha('SITE_KEY', 'https://site.com/');
     */
    public function hcaptcha(string $siteKey, string $url): string
    {
        return match($this->service) {
            '2captcha'    => $this->solve2captcha('hcaptcha', $siteKey, $url),
            'capmonster'  => $this->solveTaskBased('HCaptchaTaskProxyless', $siteKey, $url),
            'capsolver'   => $this->solveTaskBased('HCaptchaTaskProxyLess', $siteKey, $url),
            'anticaptcha' => $this->solveTaskBased('HCaptchaTaskProxyless', $siteKey, $url),
        };
    }

    // IMAGE TO TEXT

    /**
     *   $text = $Bypass->imageToText('/cu/captcha.jpg');
     *   $text = $Bypass->imageToText($base64String);
     */
    public function imageToText(string $imageOrPath): string
    {
        if (file_exists($imageOrPath)) {
            $base64 = base64_encode(file_get_contents($imageOrPath));
        } else {
            $base64 = $imageOrPath;
        }

        return match($this->service) {
            '2captcha'    => $this->imageToText2captcha($base64),
            'capmonster'  => $this->solveTaskBased('ImageToTextTask', '', '', ['body' => $base64]),
            'capsolver'   => $this->solveTaskBased('ImageToTextTask', '', '', ['body' => $base64]),
            'anticaptcha' => $this->solveTaskBased('ImageToTextTask', '', '', ['body' => $base64]),
        };
    }

    // GET SALDO

    /**
     *   echo $Bypass->balance();
     */
    public function balance(): string
    {
        return match($this->service) {
            '2captcha'    => $this->balance2captcha(),
            'capmonster'  => $this->balanceTaskBased('capmonster'),
            'capsolver'   => $this->balanceTaskBased('capsolver'),
            'anticaptcha' => $this->balanceTaskBased('anticaptcha'),
        };
    }

    private function solve2captcha(string $method, string $siteKey, string $url, array $extra = []): string
    {
        $params = array_merge([
            'key'       => $this->apiKey,
            'method'    => $method,
            'googlekey' => $siteKey,
            'pageurl'   => $url,
            'json'      => 1,
        ], $extra);

        $r    = $this->http->post(self::ENDPOINTS['2captcha']['in'], http_build_query($params));
        $data = $r->jsonSafe();

        if (!$data || ($data['status'] ?? 0) != 1) {
            throw new CurlXException("2captcha erro ao criar tarefa: " . ($data['request'] ?? $r->body));
        }

        $taskId = $data['request'];

        return $this->poll2captcha($taskId);
    }

    private function imageToText2captcha(string $base64): string
    {
        $r = $this->http->post(self::ENDPOINTS['2captcha']['in'], http_build_query([
            'key'    => $this->apiKey,
            'method' => 'base64',
            'body'   => $base64,
            'json'   => 1,
        ]));

        $data = $r->jsonSafe();

        if (!$data || ($data['status'] ?? 0) != 1) {
            throw new CurlXException("2captcha imageToText erro: " . ($data['request'] ?? $r->body));
        }

        return $this->poll2captcha($data['request']);
    }

    private function poll2captcha(string $taskId, int $maxAttempts = 30, int $intervalMs = 5000): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            usleep($intervalMs * 1000);

            $r    = $this->http->get(self::ENDPOINTS['2captcha']['res'] . "?key={$this->apiKey}&action=get&id={$taskId}&json=1");
            $data = $r->jsonSafe();

            if (!$data) continue;

            if (($data['status'] ?? 0) == 1) {
                return $data['request'];
            }

            if (($data['request'] ?? '') !== 'CAPCHA_NOT_READY') {
                throw new CurlXException("2captcha erro: " . $data['request']);
            }
        }

        throw new CurlXException("2captcha timeout: tarefa não resolvida em tempo útil");
    }

    private function balance2captcha(): string
    {
        $r    = $this->http->get(self::ENDPOINTS['2captcha']['res'] . "?key={$this->apiKey}&action=getbalance&json=1");
        $data = $r->jsonSafe();
        return $data['request'] ?? '0';
    }


    private function solveTaskBased(string $taskType, string $siteKey, string $url, array $extra = []): string
    {
        $task = array_merge([
            'type'        => $taskType,
            'websiteURL'  => $url,
            'websiteKey'  => $siteKey,
        ], $extra);
        $task = array_filter($task, fn($v) => $v !== '');

        $payload = [
            'clientKey' => $this->apiKey,
            'task'      => $task,
        ];

        $endpoint = self::ENDPOINTS[$this->service]['in'];
        $r        = $this->http->post($endpoint, $payload);
        $data     = $r->jsonSafe();

        $errorId = $data['errorId'] ?? 1;
        $taskId  = $data['taskId'] ?? null;

        if ($errorId !== 0 || !$taskId) {
            throw new CurlXException("{$this->service} erro ao criar tarefa: " . ($data['errorDescription'] ?? $r->body));
        }

        return $this->pollTaskBased($taskId);
    }

    private function pollTaskBased(string|int $taskId, int $maxAttempts = 30, int $intervalMs = 5000): string
    {
        $endpoint = self::ENDPOINTS[$this->service]['res'];

        for ($i = 0; $i < $maxAttempts; $i++) {
            usleep($intervalMs * 1000);

            $r    = $this->http->post($endpoint, [
                'clientKey' => $this->apiKey,
                'taskId'    => $taskId,
            ]);
            $data = $r->jsonSafe();

            if (!$data) continue;

            $status = $data['status'] ?? '';

            if ($status === 'ready') {
                $solution = $data['solution'] ?? [];
                return $solution['token']
                    ?? $solution['gRecaptchaResponse']
                    ?? $solution['text']
                    ?? throw new CurlXException("{$this->service}: solução não encontrada");
            }

            if ($data['errorId'] ?? 0) {
                throw new CurlXException("{$this->service} erro: " . ($data['errorDescription'] ?? 'unknown'));
            }
        }

        throw new CurlXException("{$this->service} timeout: tarefa {$taskId} não resolvida em tempo útil");
    }

    private function balanceTaskBased(string $service): string
    {
        $r    = $this->http->post(str_replace('createTask', 'getBalance', self::ENDPOINTS[$service]['in']), [
            'clientKey' => $this->apiKey,
        ]);
        $data = $r->jsonSafe();
        return (string) ($data['balance'] ?? '0');
    }
}
