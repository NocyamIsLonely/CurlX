# CurlXBypass

Suporta: **2Captcha**, **CapMonster**, **CapSolver**, **AntiCaptcha**

---

## Instalação

```php
require_once 'vendor/autoload.php';

$Bypass = new CurlXBypass($CurlX, 'capsolver', 'SUA_CHAVE_AQUI');
// Serviços disponíveis: '2captcha', 'capmonster', 'capsolver', 'anticaptcha'
```

---

## Cloudflare Turnstile

Resolve o Cloudflare automaticamente e retorna um `CookieJar` com o `cf_clearance` pronto pra usar nas requisições seguintes

```php
// Resolve e já retorna o cookie
$cf = $Bypass->solveCloudflare('https://site.com/', $headers);

// Usa o cookie em todas as requisições seguintes
$r = $CurlX->get('https://site.com/pagina', $headers, $cf);
$r = $CurlX->post('https://site.com/checkout', $data, $headers, $cf);
```

Só o Turnstile (token) sem o cookie:

```php
$token = $Bypass->turnstile('SITE_KEY', 'https://site.com/');
```

---

## reCAPTCHA v2

```php
$token = $Bypass->recaptchaV2('SITE_KEY', 'https://site.com/');

// Usa o token no campo do formulário
$r = $CurlX->post('https://site.com/login', [
    'user'                  => 'email@email.com',
    'pass'                  => 'senha',
    'g-recaptcha-response'  => $token,
]);
```

reCAPTCHA v2 invisível:

```php
$token = $Bypass->recaptchaV2('SITE_KEY', 'https://site.com/', invisible: true);
```

---

## reCAPTCHA v3

```php
$token = $Bypass->recaptchaV3('SITE_KEY', 'https://site.com/');

// Com action e score mínimo customizados
$token = $Bypass->recaptchaV3('SITE_KEY', 'https://site.com/', action: 'submit', minScore: 0.7);
```

---

## hCaptcha

```php
$token = $Bypass->hcaptcha('SITE_KEY', 'https://site.com/');

$r = $CurlX->post('https://site.com/form', [
    'campo'              => 'valor',
    'h-captcha-response' => $token,
]);
```

---

## Image to Text

Aceita caminho de arquivo ou string base64

```php
// Caminho de arquivo
$text = $Bypass->imageToText('/root/captcha.jpg');

// Base64
$text = $Bypass->imageToText($base64String);

// ex: baixa a imagem do captcha e resolve
$img  = $CurlX->get('https://site.com/captcha.jpg', $headers, $cookie);
$text = $Bypass->imageToText(base64_encode($img->body));
```

---

## Saldo

```php
echo $Bypass->balance(); // '4.23'
```

---

## Exemplo completo com Cloudflare

```php
require_once 'vendor/autoload.php';

$Bypass  = new CurlXBypass($CurlX, 'capsolver', 'SUA_CHAVE');

// Resolve Cloudflare e pega cookie
$cf = $Bypass->solveCloudflare('https://site.com/', []);

// Usa o cookie em todas as requisições
$checkout = $CurlX->get('https://site.com/checkout/', [], $cf);
$nonce    = $checkout->between('nonce":"', '"');

// Faz o POST normalmente
$pay = $CurlX->post('https://site.com/checkout/', $post, [], $cf);
echo $pay->body;
```

## Exemplo com reCAPTCHA v2

```php
require_once 'vendor/autoload.php';

$Bypass = new CurlXBypass($CurlX, '2captcha', 'SUA_CHAVE');
$cookie = new CookieJar('site');

// Pega o sitekey da página
$page    = $CurlX->get('https://site.com/login', [], $cookie);
$siteKey = $page->match('/data-sitekey=["\']([^"\']+)["\']/i');

// Resolve
$token = $Bypass->recaptchaV2($siteKey, 'https://site.com/login');

// Envia o formulário com o token
$r = $CurlX->post('https://site.com/login', http_build_query([
    'email'                => 'user@email.com',
    'senha'                => '123456',
    'g-recaptcha-response' => $token,
]), [], $cookie);

echo $r->body;
```

---

## Serviços suportados

| Serviço      | Turnstile | reCAPTCHA v2 | reCAPTCHA v3 | hCaptcha | ImageToText |
|--------------|:---------:|:------------:|:------------:|:--------:|:-----------:|
| 2captcha     | ✓         | ✓            | ✓            | ✓        | ✓           |
| capmonster   | ✓         | ✓            | ✓            | ✓        | ✓           |
| capsolver    | ✓         | ✓            | ✓            | ✓        | ✓           |
| anticaptcha  | ✓         | ✓            | ✓            | ✓        | ✓           |
