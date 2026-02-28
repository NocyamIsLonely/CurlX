# CurlX

Biblioteca HTTP para PHP — simples de usar, sem dependências, PHP 8.1+.

---

## Instalação

```php
require_once 'CurlX.php';
$CurlX = new CurlX();
```

---

### GET

```php
$r = $CurlX->get('https://api.exemplo.com/users');

echo $r->body;        // body como string
echo $r->status;      // 200
echo $r->time;        // tempo em segundos
var_dump($r->ok());   // bool(true) para 2xx
```

### POST com form data

```php
$r = $CurlX->post('https://api.exemplo.com/login', 'user=joao&pass=123');
```

### POST com JSON (array → JSON automático)

```php
$r = $CurlX->post('https://api.exemplo.com/users', [
    'name'  => 'João',
    'email' => 'joao@email.com',
]);
```

> Quando `$data` é um array, o `Content-Type: application/json` é adicionado automaticamente.

---

## Headers

```php
$headers = [
    'user-agent: Mozilla/5.0...',
    'accept: application/json',
    'authorization: Bearer ' . $token,
];

$r = $CurlX->get('https://api.exemplo.com/', $headers);
```

### Headers globais

Aplicados em todas as requisições. Headers locais têm prioridade:

```php
$CurlX->setHeaders([
    'user-agent: Mozilla/5.0...',
    'accept-language: pt-BR',
]);

// Essas requisições já usam os headers globais automaticamente
$r1 = $CurlX->get('https://site1.com/');
$r2 = $CurlX->get('https://site2.com/');
```

---

## Cookies

### String simples

```php
// Cria/usa session.txt no diretório atual
$r = $CurlX->get('https://site.com/', [], 'session');
```

### CookieJar (recomendado)

```php
$cookie = new CookieJar('minha_sessao');
$cookie = new CookieJar('renner', __DIR__); // diretório específico

$CurlX->get('https://site.com/', [], $cookie);
$CurlX->post('https://site.com/login', $data, [], $cookie);

$cookie->read();   // retorna os cookies como array associativo
$cookie->clear();  // limpa sem apagar o arquivo
$cookie->delete(); // apaga o arquivo
```

---

## Proxy

```php
// HTTP tunnel
$proxy = ['method' => 'tunnel', 'server' => '47.254.145.99:3128'];

// SOCKS5
$proxy = ['method' => 'socks5', 'server' => '127.0.0.1:1080'];

// Custom com autenticação (Luminati, Apify, IPVanish...)
$proxy = [
    'method' => 'custom',
    'server' => 'http://zproxy.lum-superproxy.io:22225',
    'auth'   => 'usuario:senha',
];

$r = $CurlX->get('https://api.exemplo.com/', $headers, $cookie, $proxy);
```

---

## Todos os métodos HTTP

```php
$CurlX->get($url, $headers, $cookie, $proxy);

$CurlX->post($url, $data, $headers, $cookie, $proxy);

$CurlX->put($url, $data, $headers, $cookie, $proxy);

$CurlX->patch($url, $data, $headers, $cookie, $proxy);

$CurlX->delete($url, $headers, $cookie, $proxy);

// Método customizado (HEAD, OPTIONS, etc.)
$CurlX->custom($url, 'HEAD', null, $headers, $cookie, $proxy);
```

Todos os parâmetros depois de `$url` são **opcionais**.

---

## Upload de arquivos (multipart)

```php
$r = $CurlX->multipart('https://api.exemplo.com/upload', [
    'descricao' => 'Minha foto',
    'arquivo'   => '/caminho/foto.jpg',  // detecta arquivo pelo caminho
], $headers, $cookie);
```

---

## Aguardar resposta (waitFor)

Repete o GET até o body conter `$needle` ou esgotar as tentativas.

```php
$r = $CurlX->waitFor(
    'https://api.mail.tm/messages',  // URL
    'sucess',                  // string a aguardar no body
    $headers,                        // headers
    20,                              // máximo de tentativas (padrão: 20)
    3000,                            // intervalo em ms (padrão: 3000)
    $cookie                          // cookie (opcional)
);

if ($r) {
    $status = $r->between('status":"', '"');
    echo $status;
} else {
    echo 'status não capturado';
}
```

---

## Retry automático

Retenta automaticamente em caso de falha ou status específico:

```php
$CurlX->retry(3);               // 3 tentativas, 1s entre elas
$CurlX->retry(3, 2000);         // 3 tentativas, 2s entre elas
$CurlX->retry(3, 1000, [500]);  // só retenta em status 500

$r = $CurlX->get('https://api.exemplo.com/'); // já usa retry
```

Por padrão retenta nos status: 429, 500, 502, 503, 504.

---

## Configuração global

```php
$CurlX->setTimeout(10, 30);          // connect, total (segundos)
$CurlX->setUserAgent('MeuBot/1.0');  // user-agent global
$CurlX->setOpt([                     // qualquer opção cURL nativa
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS      => 3,
]);
```

Todos os métodos de configuração retornam `$this` e podem ser encadeados:

```php
$CurlX->setTimeout(10, 30)->setUserAgent('Bot/1.0')->retry(3);
```

---

## Objeto de resposta

### Propriedades

| Propriedade  | Tipo      | Descrição                                |
|--------------|-----------|------------------------------------------|
| `$r->body`   | `string`  | Corpo da resposta                        |
| `$r->status` | `int`     | Código HTTP (200, 404, etc.)             |
| `$r->headers`| `array`   | Headers da resposta (associativo)        |
| `$r->time`   | `float`   | Tempo total em segundos                  |
| `$r->error`  | `?string` | Erro cURL, ou `null`                     |
| `$r->history`| `array`   | URLs de redirecionamento                 |

### Status

| Método                | Retorno  | Descrição                        |
|-----------------------|----------|----------------------------------|
| `$r->ok()`            | `bool`   | true para 2xx                    |
| `$r->isSuccess()`     | `bool`   | Alias de ok()                    |
| `$r->isRedirect()`    | `bool`   | true para 3xx                    |
| `$r->isClientError()` | `bool`   | true para 4xx                    |
| `$r->isServerError()` | `bool`   | true para 5xx                    |
| `$r->getStatusCode()` | `int`    | Alias de $r->status              |
| `$r->getHeaders()`    | `array`  | Alias de $r->headers             |
| `$r->header('name')`  | `string` | Header específico da resposta    |
| `$r->finalUrl()`      | `string` | URL final após redirecionamentos |

### JSON

| Método                       | Retorno  | Descrição                                     |
|------------------------------|----------|-----------------------------------------------|
| `$r->json()`                 | `mixed`  | Decodifica body como JSON (lança exceção)     |
| `$r->jsonSafe()`             | `mixed`  | json() sem exceção — retorna null se inválido |
| `$r->jsonGet('key')`              | `mixed`  | Acessa chave simples no JSON                  |
| `$r->jsonGet('data.user.id')`     | `mixed`  | Acessa caminho aninhado com notação de ponto |
| `$r->jsonGet('items.0.id', 'x')`  | `mixed`  | Suporta índice de array e valor padrão        |
| `$r->isJson()`               | `bool`   | true se body for JSON válido                  |

### Extração de texto

| Método                            | Retorno  | Descrição                                 |
|-----------------------------------|----------|-------------------------------------------|
| `$r->between($start, $end)`       | `string` | Extrai texto entre dois delimitadores     |
| `$r->betweenAll($start, $end)`    | `array`  | Todas as ocorrências entre delimitadores  |
| `$r->match('/regex/')`            | `string` | Primeiro grupo de captura do regex        |
| `$r->matchAll('/regex/')`         | `array`  | Todos os grupos de captura do regex       |
| `$r->contains('texto')`           | `bool`   | Verifica se body contém a string          |
| `$r->contains('texto', false)`    | `bool`   | Case-insensitive                          |

### Debug / Outros

| Método          | Retorno   | Descrição                                       |
|-----------------|-----------|-------------------------------------------------|
| `$r->dump()`    | `static`  | Imprime resumo e retorna $this para encadear    |
| `(string) $r`   | `string`  | Cast para string retorna o body                 |

---

## Exemplos práticos

### Criar email temporário e aguardar código

```php
$CurlX  = new CurlX();
$cookie = new CookieJar('mailtm');

// Pega domínio disponível
$domains = $CurlX->get('https://api.mail.tm/domains');
$domain  = $domains->json()['hydra:member'][0]['domain'];

// Cria conta
$address  = 'user' . rand(1000, 9999) . '@' . $domain;
$password = 'MinhaS3nha!';

$CurlX->post('https://api.mail.tm/accounts', [
    'address'  => $address,
    'password' => $password,
]);

// Login e token
$login = $CurlX->post('https://api.mail.tm/token', [
    'address'  => $address,
    'password' => $password,
]);
$token = $login->jsonGet('token');

$headers = ['authorization: Bearer ' . $token];

// Aguarda o email com o código chegar
$r = $CurlX->waitFor('https://api.mail.tm/messages', 'hydra:member', $headers);

if ($r) {
    $messageId = $r->json()['hydra:member'][0]['id'];
    $email     = $CurlX->get('https://api.mail.tm/messages/' . $messageId, $headers);
    $cod       = $email->between('subject":"Código de ativação:', '"');
    echo "Código: $cod";
}

$cookie->delete();
```

### Debug durante desenvolvimento

```php
// Ativa debug pra todas as requisições
$CurlX->debug();

$r = $CurlX->get('https://api.exemplo.com/test');
// Imprime: método, URL, status, tempo, body (primeiros 500 chars)

// Ou inspeciona uma resposta específica
$r->dump();
```

### jsonSafe e jsonGet pra evitar exceção

```php
// Em vez de try/catch pra JSON inválido:
$data = $r->jsonSafe();
if ($data) {
    echo $data['status'];
}

// Ou acessa direto com padrão:
$status  = $r->jsonGet('status', 'UNKNOWN');
$message = $r->jsonGet('message', '');

// Caminho aninhado (json path simples):
$userId  = $r->jsonGet('data.user.id', 0);
$itemId  = $r->jsonGet('items.0.id', '');
```

---

## Tratamento de erros

Erros fatais de cURL (sem conexão, timeout) lançam `CurlXException`:

```php
try {
    $r = $CurlX->get('https://servidor-inexistente.com/');
} catch (CurlXException $e) {
    echo "Erro: " . $e->getMessage();
}
```

Erros de aplicação (404, 500) não lançam exceção — verifique `$r->ok()` ou `$r->status`.

---

## Requisitos

- PHP 8.1+
