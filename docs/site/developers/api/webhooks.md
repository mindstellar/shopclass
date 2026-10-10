---
title: API webhooks
description: "Get a signed POST from a ShopClass site when a listing, comment or user changes: the event list, the body, the signature headers, verification code for PHP, Node and Python, retries, pausing, secret rotation and plugin events."
sidebar:
  order: 5
---

A webhook is a POST the site sends to your server when something happens: a listing is
posted, a comment is added, a user signs up. You do not poll the API.

Deliveries follow the [Standard Webhooks](https://www.standardwebhooks.com/) layout, so
its libraries can verify them.

The site owner adds endpoints on **Settings → API → Webhooks**
([Set up the REST API](/docs/configure/api/#webhooks)), or an admin key with the
`admin:webhooks` scope adds them through the [admin API](/docs/developers/api/admin/#webhooks).

## Events

| Event | Sent when | `data` |
|---|---|---|
| `listing.created` | A listing is posted. | The listing |
| `listing.updated` | A listing is edited or gets a photo. | The listing |
| `listing.activated` | A listing is approved or activated. | The listing |
| `listing.deactivated` | A listing is sent back to moderation. | The listing |
| `listing.spam` | A listing is marked as spam. | The listing |
| `listing.reported` | A visitor reports a listing. | `{"id": 412, "reason": "offensive"}`; the reason is `spam`, `badcat`, `offensive`, `repeated` or `expired` |
| `listing.deleted` | A listing is deleted. | `{"id": 412}` |
| `comment.created` | A comment is added, approved or waiting for moderation. | The comment |
| `user.registered` | A user signs up. | The user |
| `user.updated` | A user changes their profile. | The user |
| `user.deleted` | A user is deleted. | `{"id": 23}` |
| `ping` | Someone presses **Send test**. Never subscribed to. | `{"endpoint_id": "…", "message": "…"}` |

Plugins can add their own events, named `ext.<slug>.<name>`.
See [Plugin events](#plugin-events). `GET /admin/webhook-events` lists every event the site has.

An event is sent whichever way the change was made: the web, the admin or the API.

## The body

```json
{
  "type": "listing.created",
  "id": "msg_01K7Q3ZV8M0G5E2R4T6W9XJ1HB",
  "timestamp": "2026-10-04T09:30:12Z",
  "data": {
    "id": 412,
    "url": "https://example.com/vintage-books_i412",
    "status": "pending",
    "title": "Vintage books",
    "category": { "id": 12, "slug": "books-magazines", "name": "Books - Magazines" },
    "price": { "amount": "90.00", "currency": "USD", "formatted": "$ 90" },
    "seller": { "id": 23, "name": "Jane Doe", "username": "janedoe", "url": "https://example.com/user/profile/janedoe" }
  }
}
```

| Member | Meaning |
|---|---|
| `type` | The event. |
| `id` | The message id. The same as the `webhook-id` header. |
| `timestamp` | When the event was made, RFC 3339 in UTC. |
| `data` | The resource, or its id for a delete. |
| `thin` | Present and `true` only when the body was cut down. See below. |

- **`data` is the public view.** It is what an anonymous `GET /api/v1/` of the same
  resource returns, in the site's default language. It stays in the v1 shape when a newer
  API version ships. An e-mail address or phone number appears only
  where that public view shows one. For anything more, call the API with your own key.
- **A deleted resource has its id only.**
- **A big body is sent thin.** Over about 64 KB, `data` holds only `id` and `url`, and
  `thin` is `true`. Fetch the rest with `GET`.
- **A resource the public cannot see is sent thin.** For a listing that is pending,
  disabled, spam or expired, or a comment that is not published, `data` is
  `{"id", "url", "live": false}` and `thin` is `true`. A comment's `url` is `null`; it
  carries `listing_id` instead. Fetch the rest with an admin key.
- The body is JSON with `/` and non-ASCII characters left as they are. Sign-check the
  bytes you received. Do not parse and re-encode them first.

## Headers

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `User-Agent` | `Shopclass-Webhooks/<site version>` |
| `webhook-id` | The message id, such as `msg_01K7Q3ZV8M0G5E2R4T6W9XJ1HB`. |
| `webhook-timestamp` | Unix time in seconds when this attempt was signed. |
| `webhook-signature` | One or more signatures, space separated, each `v1,<base64>`. |

## Verify the signature

Check every delivery. Anyone who knows your address can POST to it.

The signature is the base64 of an HMAC-SHA256 over `<webhook-id>.<webhook-timestamp>.<body>`.
The key is the **base64-decoded** part of the secret after `whsec_`. Not the text of the
secret.

Your code must:

1. Use the raw body, byte for byte.
2. Reject a `webhook-timestamp` more than 5 minutes from your clock.
3. Compare with a constant-time function.
4. Accept the delivery if **any** of the space-separated signatures matches. During a
   [secret rotation](#rotating-the-secret) there are two.

### PHP

```php
function verify_webhook(string $secret, string $id, string $timestamp, string $signatures, string $body): bool
{
    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
        return false;
    }
    $key = base64_decode(substr($secret, strlen('whsec_')), true);
    if ($key === false) {
        return false;
    }
    $expected = 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));
    foreach (explode(' ', $signatures) as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}
```

```php
$ok = verify_webhook(
    getenv('WEBHOOK_SECRET'),                      // whsec_…
    $_SERVER['HTTP_WEBHOOK_ID'] ?? '',
    $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '',
    $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '',
    file_get_contents('php://input')
);
if (!$ok) {
    http_response_code(400);
    exit;
}
```

### Node

`headers` is the lower-case header object Node gives you. `rawBody` is a string or a
`Buffer`. With Express, read it with `express.raw({ type: 'application/json' })`.

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

export function verifyWebhook(secret, headers, rawBody) {
  const id = headers['webhook-id'] ?? '';
  const timestamp = headers['webhook-timestamp'] ?? '';
  const signatures = headers['webhook-signature'] ?? '';
  if (!/^\d{1,12}$/.test(timestamp) || Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) {
    return false;
  }
  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64');
  const expected = createHmac('sha256', key).update(`${id}.${timestamp}.`).update(rawBody).digest();
  return signatures.split(' ').some((part) => {
    const [version, signature] = part.split(',');
    if (version !== 'v1' || !signature) return false;
    const given = Buffer.from(signature, 'base64');
    return given.length === expected.length && timingSafeEqual(given, expected);
  });
}
```

### Python

```python
import base64
import hashlib
import hmac
import time


def verify_webhook(secret: str, headers: dict, raw_body: bytes) -> bool:
    msg_id = headers.get("webhook-id", "")
    timestamp = headers.get("webhook-timestamp", "")
    signatures = headers.get("webhook-signature", "")
    if not timestamp.isdigit() or abs(time.time() - int(timestamp)) > 300:
        return False
    key = base64.b64decode(secret.removeprefix("whsec_"))
    signed = f"{msg_id}.{timestamp}.".encode() + raw_body
    expected = b"v1," + base64.b64encode(hmac.new(key, signed, hashlib.sha256).digest())
    return any(hmac.compare_digest(expected, s.encode()) for s in signatures.split(" "))
```

## Answer, retry and pause

Answer with any `2xx` within 15 seconds, and do slow work after you answer. Anything else
counts as a failed delivery: a `3xx`, `4xx` or `5xx`, a timeout, or no connection. The site
does not follow redirects.

| What | How it works |
|---|---|
| Retries | A failed delivery is tried again after about 1 minute, 5 minutes, 30 minutes, 2 hours, 5 hours, then every 10 hours, and a last time after 14 hours: 12 tries over about 72 hours. Each wait varies by up to 10%. If your server answers with `Retry-After` (seconds or a date), the site waits that long instead, up to 24 hours. |
| Dead letters | After the 12th failure the job stops. It stays in **Tools → System info → Jobs** with its last error, where an admin can retry or drop it. |
| 410 Gone | If your server answers `410 Gone`, the endpoint is paused at once, with no retries, and the site's contact address gets an e-mail. |
| Auto-pause | When 8 deliveries in a row fail, the endpoint is switched off and the site's contact address gets one e-mail. Its status becomes `paused`. |
| Re-enable | Switch the endpoint on again (screen, or `PATCH` with `"enabled": true`). That clears the pause and the failure count. |
| A success | Resets the failure count. |
| Test ping | **Send test** sends one `ping`. It is sent once, whatever the endpoint's state, and never counts toward a pause. |

Events that happen while an endpoint is paused are not sent later.

Write your receiver so it copes with the delivery system:

- **Be idempotent.** Store each `webhook-id` and skip one you have seen. A retry carries the
  same id as the first try.
- **Do not rely on order.** A retried `listing.created` can arrive after the
  `listing.updated` that followed it. Compare the `timestamp` members, or fetch the current state.
- **Answer fast.** Queue the work on your side.

## Rotating the secret

Each endpoint has its own secret, `whsec_…`, shown **once**, when the endpoint is made or its
secret is rotated.

Rotate it on the screen (**Rotate secret**, asks for the admin's password) or with
`POST /admin/webhooks/{id}/rotate-secret`. The answer holds the new secret.

For 24 hours every delivery carries **two** signatures, one for each secret, in one
`webhook-signature` header. Put the new secret on your server in that time. A receiver that
accepts any matching signature needs no other change. After 24 hours only the new secret
signs.

The site stores secrets encrypted with its signing key (`OSC_CSRF_SECRET`). If that key
changes, deliveries fail with "The signing secret cannot be read" until you rotate the secret.

## Where the site may send

| Rule | Detail |
|---|---|
| Schemes | `http` and `https`. Use `https`. |
| Ports | 80 for `http`, 443 for `https`. |
| Addresses | Only public ones. A host that resolves to a private, loopback or reserved address is refused. |
| Redirects | Not followed. |

**Settings → API → Webhooks → Allow webhook addresses on a private network** lifts the
address rule, for a receiver on the owner's own network such as
`http://192.168.1.20/hook`. The port rule still holds. It is off by default. It is a site setting, not an API
setting: it cannot be changed through `PATCH /admin/settings`.

The address is checked when the endpoint is saved and again on every delivery.
The site connects to the address it checked, so a changing DNS answer cannot redirect it.

Limits: 50 endpoints a site, an address up to 2048 characters, a description up to 255.

## Plugin events

A plugin adds an event in two steps. Register it, then emit it.

### 1. Register on `api_webhook_events`

```php
osc_add_filter('api_webhook_events', static function ($events) {
    $events['ext.acme.offer_created'] = array(
        'description' => 'A buyer made an offer.',
        'schema'      => '',
    );

    return $events;
});
```

| Rule | Detail |
|---|---|
| Name | `ext.<slug>.<name>`. The slug is `a-z`, `0-9`, `_`, `-` (up to 40). The name is `a-z`, `0-9`, `_`, `.` (up to 60). |
| A wrong name | Dropped, with a PHP warning. |
| Core's events | Cannot be changed or removed. |
| `schema` | The name of the component that describes `data`. Optional. |

The event then shows in **Events** on the screen and in `GET /admin/webhook-events`.
Admins choose whether an endpoint gets it.

### 2. Emit with `osc_webhook_emit()`

```php
$messageId = osc_webhook_emit('ext.acme.offer_created', array(
    'id'  => $offerId,
    'url' => $offerUrl,
));
```

| Argument | Meaning |
|---|---|
| `$type` | A registered event. An unknown type throws `InvalidArgumentException`. |
| `$data` | The event's `data`. Keep e-mails and secrets out. |
| `$options['thin']` | The `data` to send when the body is over about 64 KB. Default: `id` and `url` taken from `$data`. |
| `$options['endpoints']` | Only these endpoint ids. |

It returns the message id (`webhook-id`), or `null` when no endpoint wants the event. It
only queues the delivery, so it is quick.

### Change a body: `api_webhook_payload`

```php
osc_add_filter('api_webhook_payload', static function ($payload, $type, $endpoint) {
    if ($type === 'listing.created') {
        $payload['data']['source'] = 'acme';
    }

    return $payload;
});
```

It runs once per endpoint, so `$endpoint` (the endpoint as the admin API shows it, no
secret) lets you shape a body for one receiver. Return the whole `$payload`.

### Watch deliveries: `api_webhook_delivered`

```php
osc_add_hook('api_webhook_delivered', static function ($endpoint, $event, $httpStatus, $attempt) {
    // $event is array('id' => 'msg_…', 'type' => '…', 'test' => false)
    // $httpStatus is 0 when there was no answer
});
```

It runs after every attempt, including failed ones and test pings.

## See also

- [Admin endpoints](/docs/developers/api/admin/#webhooks): add and manage endpoints with a key
- [Set up the REST API](/docs/configure/api/#webhooks): the screen
- [Background jobs](/docs/developers/jobs/): how retries and dead letters work
