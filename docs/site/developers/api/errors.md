---
title: API errors
description: "The ShopClass REST API error format (RFC 9457 problem+json), every error code with its HTTP status, and how validation errors point at the field."
sidebar:
  order: 4
---

Every error is JSON with `Content-Type: application/problem+json`
([RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)). Branch on the HTTP status for the
class of error and on `code` for the exact case.

```json
{
  "type": "https://mindstellar.com/docs/developers/api/errors/#not_found",
  "title": "Not found.",
  "status": 404,
  "detail": "No such listing.",
  "code": "not_found",
  "instance": "urn:request:Xq3v9LmT2aBc"
}
```

| Member | Meaning |
|---|---|
| `type` | A link to the code's entry on this page. |
| `title` | The same text for every case of a code. |
| `status` | The HTTP status, repeated. |
| `detail` | What went wrong this time. For people: do not parse it. |
| `code` | The stable machine name. Match on this. |
| `instance` | `urn:request:<id>`: the same id as the `Request-Id` response header. Quote it when you report a problem. |
| `errors` | On `422` only: one entry per bad field. |
| `error` | At `POST /auth/token` only: the OAuth 2 error, the same as `code`. |

Codes do not change inside v1. `detail` wording may.

Every response carries a `Request-Id` header. Send your own `Request-Id` or `X-Request-Id` (8 to 64 characters from `A-Za-z0-9._-`) and it is echoed back.

## Validation errors

A `422 validation_failed` lists every problem at once in `errors`:

```json
{
  "type": "https://mindstellar.com/docs/developers/api/errors/#validation_failed",
  "title": "The request is not valid.",
  "status": 422,
  "detail": "limit must be at least 1.",
  "code": "validation_failed",
  "errors": [
    { "pointer": "/limit", "code": "minimum", "message": "must be at least 1", "in": "query" }
  ],
  "instance": "urn:request:Xq3v9LmT2aBc"
}
```

| Member | Meaning |
|---|---|
| `pointer` | The field, as a [JSON Pointer](https://www.rfc-editor.org/rfc/rfc6901): `/limit`, or `/price/amount` for a nested body field. Empty means the whole input. |
| `code` | `type`, `enum`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `format`, `minItems`, `maxItems`, `required` or `additionalProperties`. Writes add `invalid`, `unknown`, `limit`, `mismatch`, `minProperties` and `rejected` (the web form's own message). |
| `message` | What is wrong with it. |
| `in` | `query` or `body`: where the field was. |

## Codes

### 400 Bad request

#### `invalid_json`

The body is not valid JSON, or not a JSON object. Every endpoint that takes a body can return it.

#### `invalid_cursor`

The `cursor` is forged, more than a day old, or was made for other filters, sort or order. Start again from the
first page and follow `links.next`.

#### `invalid_query`

A query value the endpoint cannot use, such as `fields=bogus` or `include=bogus`.

#### `invalid_header`

A request header is malformed. Today that is an `Idempotency-Key` that is empty, over 255
characters, or not plain visible ASCII.

#### `invalid_request`, `invalid_grant`, `invalid_scope`, `unsupported_grant_type`

Only from `POST /auth/token`, which answers as OAuth 2 asks (RFC 6749 §5.2): `invalid_request`
for a body that is missing members or cannot be read, `invalid_grant` for a wrong password, a
bad or reused refresh token, or an account that may not sign in, `invalid_scope` for a `scope`
naming no user scope, and `unsupported_grant_type` for a `grant_type` other than `password` or
`refresh_token`. The body also has the same `error` member.

### 401 Unauthorized

#### `unauthorized`

No key, or a key or token the site refused. The reason is never given. The answer carries
`WWW-Authenticate: Bearer` (nothing sent) or `Bearer error="invalid_token"` (something was sent).

#### `token_expired`

An access token was genuine but has run out. Swap the refresh token for a new one and retry
the call. For a page token, reload the page. It is not counted as a failed guess. The answer carries
`WWW-Authenticate: Bearer error="invalid_token", error_description="The access token expired"`.
See [Authentication](/docs/developers/api/authentication/#access-tokens).

#### `session_required`

A call with a page token (`X-Shopclass-Token`) whose sign-in cookie is missing or not valid,
whose page token belongs to another user or an old password, or whose user is suspended or not
confirmed. Reload the page. See
[Same-site session](/docs/developers/api/authentication/#same-site-session-theme-javascript).

### 403 Forbidden

#### `forbidden`

The credential is fine but may not do this, for example:

- an endpoint that needs an admin key, or a user's token or key
- a feature the site has off: comments, personal keys, sign-up through the API, accounts
- posting or commenting from a banned account or address
- an admin key making a key or rotating one with a scope it does not hold, or rotating another admin's key

#### `insufficient_scope`

The key lacks the scope the endpoint needs. `WWW-Authenticate` names it:
`Bearer error="insufficient_scope", scope="admin:users"`.

#### `not_owner`

The resource is real and live, but belongs to someone else: another user's listing or comment.

#### `cross_origin`

A call with a page token that did not come from the site's own pages: another `Origin`, a
`Sec-Fetch-Site` other than `same-origin`, or no sign of where it came from.

#### `api_disabled`

The site owner switched the API off. Nothing a client can do.

### 404 Not found

#### `not_found`

No such endpoint, API version or record. A pending, disabled or spam listing, a disabled user, and a
comments list on a site with comments off are also `404`. So is a comment that is not approved
and not the caller's own, and a photo, key or saved search that is not the caller's.

### 405 Method not allowed

#### `method_not_allowed`

The path exists but not for this method. `Allow` lists the methods that work.

### 409 Conflict

#### `conflict`

The request clashes with the current state:

- a stored `Idempotency-Key` answer that cannot be read
- an [admin action](/docs/developers/api/admin/) the state refuses: activating a blocked
  listing, deleting a currency a listing uses or the site defaults to, revoking a key that is
  already revoked

#### `idempotency_in_flight`

A request with the same `Idempotency-Key` is still running. `Retry-After: 1`. Send the call again
in a moment.

### 412 Precondition failed

#### `precondition_failed`

The resource changed since the `If-Match` value you sent. `PATCH` and `DELETE` on a path that
has a `GET` check it: send the `ETag` of your last `GET` of that resource, or `*` for any
existing one. `GET` it again and retry. Without `If-Match` the write is never refused.

### 413 Payload too large

#### `too_large`

The body is over 1 MB, or a photo is over the site's largest photo size or 16 MB.

### 415 Unsupported media type

#### `unsupported_media_type`

The body was not sent as `application/json` (or `application/merge-patch+json` on `PATCH`). A
photo must come as `multipart/form-data` in a field named `photo`, or as the image itself.

### 422 Unprocessable

#### `validation_failed`

A query or body value broke a rule. Read `errors`. For listing searches this includes an
unknown `category`, a query parameter the endpoint does not take (`api_key` always works), a `limit` outside 1 to the site's maximum, and an unknown `locale`. For
writes it also covers the web form's own refusals: a missing field, an unknown place, an
unknown or expired `photo_tokens` entry, a wrong `current_password`, or a language the site
does not have. A write refused by the form has `code: "rejected"` on each entry in `errors`.

#### `idempotency_key_reused`

The same `Idempotency-Key` was sent with a different request. Use a new key for a different
request.

#### `listing_limit`

The user has reached the site's listing limit. `detail` says what the limit is.

### 429 Too many requests

#### `rate_limited`

Over a rate limit: the per-minute limits, or an hourly cap on listings, comments, photo
downloads, e-mail changes or sign-ups. Wait `Retry-After` seconds. See
[rate limits](/docs/developers/api/authentication/#rate-limits) and
[write limits](/docs/developers/api/writes/#limits).

#### `login_blocked`

Too many wrong passwords for this account or address, at `POST /auth/token`, `POST /account/password`
or `POST /account/keys`. `Retry-After` says how long.

#### `too_many_failures`

Too many wrong keys from this address in 15 minutes. `Retry-After: 900`. See
[lockout](/docs/developers/api/authentication/#lockout-after-failed-keys).

### 500 and 503

#### `server_error`

The site hit an error. The cause is in the server's error log, not in the answer. Retry
later; report it if it persists.

#### `maintenance`

The site is in maintenance mode. `Retry-After: 900`.

## Handling errors

- Retry `429`, `500` and `503` after a wait. `429` and `503` say how long.
- On `401 token_expired`, refresh the token and retry once. Do not retry other `401`s unchanged.
- Do not retry `400`, `403`, `404`, `405` or `422` unchanged.
- Retry a write after a timeout with the same `Idempotency-Key`: [Writes](/docs/developers/api/writes/#retrying-safely-with-idempotency-key).
- Treat an unknown `code` by its status.
- Plugin endpoints use the same format. See [Plugin endpoints](/docs/developers/api/plugin-endpoints/#errors).
