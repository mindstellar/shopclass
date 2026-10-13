---
title: HTTP client
description: "Call another service from a plugin with mindstellar\\http\\CurlHttpClient: HTTPS only, certificates checked, timeouts and a size cap."
sidebar:
  order: 33
---

`mindstellar\http\CurlHttpClient` (7.0+) sends one request and returns the answer:

```php
use mindstellar\http\CurlHttpClient;
use mindstellar\http\HttpException;

try {
    $r = (new CurlHttpClient())->send(
        'POST',
        'https://api.example.com/v1/charges',
        ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
        json_encode($payload),
        ['timeout' => 10]
    );
} catch (HttpException $e) {
    // No answer came back, or it was too large.
}

$r['status'];                     // 200
$r['headers']['content-type'];    // header names are lower case
$r['body'];
```

## What it does for you

- **HTTPS only.** Certificates are checked. Redirects are not followed and no cookies are kept.
- **Timeouts:** `connect_timeout` 5 s and `timeout` 15 s unless you pass others. Fractions are allowed.
- **Size cap:** `max_bytes`, 1 MB by default, for the body. Headers are capped at 64 KB.
- **User agent:** `Shopclass/<version>` unless you send your own `User-Agent` header.
- **Safe errors.** An `HttpException` names the host only, never the path, query string
  or headers, so an API key cannot reach a log.

Any HTTP status is an answer, not an exception: check `status` yourself.

It does **not** check where a URL points. If the URL comes from a user, check it against
your own allow-list first.

## What throws

`InvalidArgumentException`, before any connection, for:

- a URL that is not `https://` with a host;
- a method other than `GET`, `POST`, `PUT`, `PATCH`, `DELETE` or `HEAD`;
- a header name with characters a header name cannot have;
- a header value with a line break (CR or LF) or a NUL byte;
- `max_bytes` that is not a positive whole number;
- `connect_timeout` or `timeout` that is not a positive number.

`HttpException` when the server has no cURL extension, when the transfer fails (no answer,
or it was cut off part way), or when the answer is larger than the caps.

## In tests

Type your code against `mindstellar\http\HttpClient` and take the client in the constructor,
so a test can pass a fake. The client's own constructor also takes a `$transport` callable;
that is for core's tests only.
