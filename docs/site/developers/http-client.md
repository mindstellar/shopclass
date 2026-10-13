---
title: Outgoing HTTP and admin notices
description: "Call another service from a plugin with mindstellar\\http\\CurlHttpClient (HTTPS only, timeouts, a size cap), and e-mail the site owner with osc_mail_admin()."
sidebar:
  order: 33
---

Two small helpers for plugins that talk to other services (7.0+).

## Calling another service

`mindstellar\http\CurlHttpClient` sends one request and returns the answer:

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

What it does for you:

- **HTTPS only.** Any other URL throws `InvalidArgumentException` before connecting.
- **Certificates are checked.** Redirects are not followed and no cookies are kept.
- **Timeouts:** `connect_timeout` 5 s and `timeout` 15 s unless you pass others.
- **Size cap:** `max_bytes`, 1 MB by default. A larger answer throws `HttpException`.
- **Safe errors.** An `HttpException` names the host only, never the path, query string
  or headers, so an API key cannot reach a log.

Any HTTP status is an answer, not an exception: check `status` yourself.

Type your code against `mindstellar\http\HttpClient` and take the client in the
constructor. Tests can then pass a fake that never touches the network.

## Telling the site owner

`osc_mail_admin($subject, $lines)` e-mails the site's contact address. Each line is
plain text and becomes its own escaped paragraph.

```php
osc_mail_admin('[Acme] Payout failed', [
    'The payout for order #' . $orderId . ' was refused.',
    'Open Billing, then Orders, to retry it.',
]);
```

It returns `false` instead of throwing when the mail cannot be sent, so it is safe to call
from a callback or a job.
