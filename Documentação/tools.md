# CurlXTools

Class auxiliar do CurlX

---

## Inicialização

```php
require_once 'vendor/autoload.php';
$Tools = new CurlXTools($CurlX);
```
---

## Módulo card

### `parseCard(string $input)`

```php
$card = $Tools->parseCard('4111111111111111|12|2028|123');
// ou
$card = $Tools->parseCard('4111111111111111:12:28:123');

echo $card['cc'];     // 4111111111111111
echo $card['mes'];    // 12
echo $card['ano2'];   // 28
echo $card['ano4'];   // 2028
echo $card['cvv'];    // 123
echo $card['bin'];    // 411111
echo $card['brand'];  // visa
echo $card['full'];   // 4111111111111111|12|2028|123
```

### `isLuhnValid(string $cc)`

```php
if (!$Tools->isLuhnValid($card['cc'])) {
    echo 'Número inválido';
    exit;
}
```

### `detectBrand(string $cc)`

```php
echo $Tools->detectBrand('4111111111111111'); // visa
echo $Tools->detectBrand('5111111111111111'); // mastercard
```

### `bin(string $cc)`

Informações da BIN via FluidPay

```php
$info = $Tools->bin($card['cc']);

if ($info['success']) {
    echo $info['vendor'];   // VISA
    echo $info['bank'];     // JPMORGAN CHASE
    echo $info['level'];    // CLASSIC
    echo $info['type'];     // CREDIT
    echo $info['country'];  // US
    echo $info['full'];     // VISA - JPMORGAN CHASE - CLASSIC - US
}
```

---

## Geração de Dados

### `generateCPF(bool $format = false)`

Gera um CPF matematicamente válido

```php
$Tools->generateCPF();       // '12345678909'
$Tools->generateCPF(true);   // '123.456.789-09'
```

### `generateCNPJ(bool $format = false)`

Gera um CNPJ matematicamente válido

```php
$Tools->generateCNPJ();      // '12345678000195'
$Tools->generateCNPJ(true);  // '12.345.678/0001-95'
```

### `generatePerson()`

Gera informações (BR) via 4Devs (nome, CPF, RG, endereço, CEP, telefone, etc)

```php
$p = $Tools->generatePerson();

echo $p['nome'];
echo $p['cpf'];
echo $p['rg'];
echo $p['endereco'];
echo $p['cep'];
echo $p['telefone'];
echo $p['email'];
```

### `generatePersonUS()`

Gera informações (USA) via randomuser.me

```php
$p = $Tools->generatePerson();

echo $p['phone'];
echo $p['email'];
echo $p['first_name'];
echo $p['last_name'];
```


### `randomEmail(string $prefix = 'user')`

Gera um email aleatório

```php
$Tools->randomEmail();           // 'user83921748263@gmail.com'
$Tools->randomEmail('nocyam');   // 'nocyam83921748263@hotmail.com'
```

### `randomString(int $length = 10, string $type = 'alnum')`

Gera uma string aleatória

```php
$Tools->randomString();            // 'aB3kR7mX9z'
$Tools->randomString(16);          // string de 16 chars
$Tools->randomString(8, 'num');    // só números: '83920471'
$Tools->randomString(8, 'lower');  // só minúsculas: 'kxmrpqva'
$Tools->randomString(8, 'upper');  // só maiúsculas: 'KXMRPQVA'
$Tools->randomString(8, 'alpha');  // letras: 'kXmRpQvA'
$Tools->randomString(8, 'alnum');  // padrão: letras + números
```

---

## Proxy

### `formatProxy(string $proxyStr, string $method = 'tunnel')`

Converte string de proxy para o formato de array

```php
// ip:porta
$proxy = $Tools->formatProxy('192.168.1.1:8080');

// ip:porta:user:pass
$proxy = $Tools->formatProxy('192.168.1.1:8080:user123:pass456');

// SOCKS5
$proxy = $Tools->formatProxy('127.0.0.1:1080', 'socks5');

$CurlX->get($url, [], null, $proxy);
```

### `randomProxy(string $file, string $method = 'tunnel')`

Pega um proxy aleatório de um arquivo

```php
// proxies.txt:
// 192.168.1.1:8080
// 192.168.1.2:8080:user:pass

$proxy = $Tools->randomProxy('proxies.txt');
$CurlX->get($url, [], null, $proxy);
```

---

## Logs

### `saveLog(string $filename, string $content)`

```php
$Tools->saveLog('hits.txt', "LIVE: {$card['full']} | {$bin['full']}");
$Tools->saveLog('dies.txt', "DIE: {$card['full']}");
```

### `saveLogTs(string $filename, string $content)`

```php
$Tools->saveLogTs('errors.txt', 'Timeout na requisição');
// [2026-02-19 11:30:00] Timeout na requisição
```

---

## Utilitários de texto

### `getStr(string $string, string $start, string $end)`

Extrai texto entre dois delimitadores

> Prefira `$r->between()` quando estiver trabalhando com o objeto de resposta do CurlX

### `cleanHtml(string $html)`

Remove tags, scripts, styles e decodifica entidades HTML

```php
$texto = $Tools->cleanHtml($r->body);
echo $texto;
```

### `queryToArray(string $query)` e `arrayToQuery(array $data)`

Converte entre query string e array

```php
$Tools->queryToArray('a=1&b=2');   // ['a' => '1', 'b' => '2']
$Tools->arrayToQuery(['a' => '1']); // 'a=1'
```
