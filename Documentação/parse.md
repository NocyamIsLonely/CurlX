# CurlXParser

Parser de HTML, Extrai formulários, campos, captchas, links, variáveis JS...

---

## Inicialização


```php
require_once 'vendor/autoload.php';
$Parser = new CurlXParser($CurlX);
```

Parser não precisa de nenhuma configuração, é stateless, você passa o HTML diretamente em cada método.

---

## Formulários

### `form(string $html, int $index = 0)`

Extrai todos os campos de um formulário como array associativo. Captura inputs, textareas e selects.

```php
$checkout = $CurlX->get('https://site.com/checkout/', [], $cookie);
$fields   = $Parser->form($checkout->body);

$nonce   = $fields['woocommerce-process-checkout-nonce'];
$post_id = $fields['_wfacp_post_id'];
$token   = $fields['_token'];
```

Se a página tiver múltiplos formulários, use o índice:

```php
$form1 = $Parser->form($html, 0); // primeiro formulário
$form2 = $Parser->form($html, 1); // segundo formulário
```

### `forms(string $html)`

Retorna todos os formulários da página como array de arrays.

```php
$forms = $Parser->forms($html);
foreach ($forms as $i => $form) {
    echo "Formulário $i: " . implode(', ', array_keys($form)) . "\n";
}
```

### `hidden(string $html)`

Extrai apenas os campos hidden

```php
$hidden  = $Parser->hidden($checkout->body);
$nonce   = $hidden['woocommerce-process-checkout-nonce'];
$post_id = $hidden['_wfacp_post_id'];
```

### `field(string $html, string $name)`

Extrai o valor de um campo específico pelo name

```php
$nonce = $Parser->field($checkout->body, 'woocommerce-process-checkout-nonce');
$token = $Parser->field($html, '_token');
```

---

## Captcha

### `detectCaptcha(string $html)`

Retorna `null` se não encontrar nenhum captcha.

```php
$captcha = $Parser->detectCaptcha($checkout->body);

if ($captcha) {
    echo $captcha['type'];    // 'recaptcha_v2', 'recaptcha_v3', 'hcaptcha', 'turnstile'
    echo $captcha['sitekey']; // '6Ldtnw0oAAAA...'
    $token = match($captcha['type']) {
        'recaptcha_v2' => $Bypass->recaptchaV2($captcha['sitekey'], $url),
        'recaptcha_v3' => $Bypass->recaptchaV3($captcha['sitekey'], $url, $captcha['action'] ?? 'verify'),
        'hcaptcha'     => $Bypass->hcaptcha($captcha['sitekey'], $url),
        'turnstile'    => $Bypass->turnstile($captcha['sitekey'], $url),
        default        => throw new Exception("Captcha não suportado: {$captcha['type']}")
    };
}
```

---

## Links

### `links(string $html, string $filter = '')`

Extrai todos os links da página, Aceita filtro opcional por string

```php
$links = $Parser->links($html);
// ['https://site.com/page1', 'https://site.com/page2', ...]

// Só links que contém 'produto'
$produtos = $Parser->links($html, 'produto');

// Só links de checkout
$checkouts = $Parser->links($html, 'checkout');
```

### `link(string $html, string $needle)`

Extrai o href de um link específico pelo texto ou parte da URL

```php
$url = $Parser->link($html, 'Próxima página');
$url = $Parser->link($html, 'checkout');
```

---

## Meta tags

### `meta(string $html, string $name)`

Extrai o `content` de uma meta tag pelo `name` ou `property`

```php
$csrf  = $Parser->meta($html, 'csrf-token');
$title = $Parser->meta($html, 'og:title');
$desc  = $Parser->meta($html, 'og:description');
```

---

## Variáveis JavaScript

### `jsVar(string $html, string $varName)`

Extrai o valor de uma variável JavaScript declarada no HTML

```php
$session = $Parser->jsVar($checkout->body, 'pagseguro_connect_3d_session');

$apiKey  = $Parser->jsVar($html, 'PUBLIC_KEY');
$userId  = $Parser->jsVar($html, 'user_id');
```

---

## Tabelas

### `table(string $html, int $index = 0)`

Extrai uma tabela HTML como array de arrays. Cada linha vira um array com os valores das células

```php
$rows = $Parser->table($html);

foreach ($rows as $row) {
    echo $row[0] . ' | ' . $row[1] . "\n";
}

// Second 
$rows2 = $Parser->table($html, 1);
```

---

## Seletor CSS

### `find(string $html, string $selector)`

Encontra elementos por seletor CSS, Suporta: `tag`, `.classe`, `#id`, `tag.classe`, `tag#id`.
Retorna array com o HTML interno de cada elemento encontrado

```php
$items  = $Parser->find($html, '.product-title');
$box    = $Parser->find($html, 'div#checkout');
$errors = $Parser->find($html, '.error-message');

foreach ($items as $item) {
    echo strip_tags($item) . "\n";
}
```

---

## Atributo de tag

### `attr(string $tag, string $attr)`

Extrai o valor de um atributo de uma tag HTML, suporta aspas simples, duplas e ausência de aspas

```php
$tag   = '<input name="token" value="abc123" type="hidden">';
$value = $Parser->attr($tag, 'value'); // 'abc123'
$name  = $Parser->attr($tag, 'name');  // 'token'
```