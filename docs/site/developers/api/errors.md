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
| `detail` | What went wrong this time, for people. It may be in the site's language. Do not parse it. |
| `code` | The stable machine name. Match on this. |
| `instance` | `urn:request:<id>`: the same id as the `Request-Id` response header. Quote it when you report a problem. |
| `errors` | On `422` only: one entry per bad field. |
| `error` | At `POST /auth/token` only: the OAuth 2 error, the same as `code`. |

Codes do not change inside v1. `detail` wording and language may: branch on `code`, never on
`detail`. Plugin endpoints may answer their own codes, named `ext_<slug>_<name>`.

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
| `code` | One of the [field codes](#field-codes) below. |
| `message` | What is wrong with it. |
| `in` | `query` or `body`: where the field was. Always present. |

### Field codes

| Code | Meaning |
|---|---|
| `type` | The value has the wrong JSON type. |
| `enum` | The value is not one of the allowed values. |
| `minimum` | The number is too small. |
| `maximum` | The number is too large. |
| `minLength` | The text is too short or blank. |
| `maxLength` | The text is too long. |
| `pattern` | The text does not have the expected form. |
| `format` | The value is not a valid date, e-mail, URL or id. |
| `minItems` | The list has too few items. |
| `maxItems` | The list has too many items, or an account holds as many as it may. |
| `minProperties` | The object needs at least one member. |
| `required` | A required field is missing. |
| `additionalProperties` | The field is not one the endpoint takes. |
| `invalid` | The value is not accepted, for a reason `message` gives. |
| `unknown` | The value names something that does not exist, such as a category, country or custom field. |
| `taken` | Another resource already uses the value. |
| `mismatch` | The value does not match, such as a wrong current password, or a region outside the country or a city outside the region. |
| `limit` | A limit is reached, such as the photos a listing may hold. |
| `rejected` | The web form refused the input; `message` is its text. |

Plugin endpoints may add `ext_<slug>_<name>` codes. Treat an unknown code as `invalid`.

## Codes

Each code's `type` link points at its anchor here.

| Code | Status | Meaning |
|---|---|---|
| <a id="invalid_json"></a>`invalid_json` | 400 | The body is not valid JSON, or not a JSON object. |
| <a id="invalid_cursor"></a>`invalid_cursor` | 400 | The `cursor` is forged, over a day old, or made for other filters, sort or order. Start again and follow `links.next`. |
| <a id="invalid_header"></a>`invalid_header` | 400 | A malformed header: an `Idempotency-Key` that is empty, over 255 characters, or not plain visible ASCII. |
| <a id="banned"></a>`banned` | 403 | A ban rule matches the account, its e-mail or the address. Covers every call with a user's token, key or session, sign-up, and posting. |
| <a id="feature_disabled"></a>`feature_disabled` | 403 | The site has user accounts, API sign-up, personal keys or comments switched off. |
| <a id="forbidden"></a>`forbidden` | 403 | Any other refusal: making or rotating a key with a scope the admin key lacks, rotating another admin's key, or an account that cannot save alerts. |
| <a id="insufficient_scope"></a>`insufficient_scope` | 403 | The key lacks the endpoint's scope. `WWW-Authenticate` names it: `Bearer error="insufficient_scope", scope="admin:users"`. |
| <a id="not_owner"></a>`not_owner` | 403 | The resource is real and live but belongs to someone else, such as another user's listing or comment. |
| <a id="cross_origin"></a>`cross_origin` | 403 | A page-token call that did not come from the site's own pages: another `Origin`, a `Sec-Fetch-Site` other than `same-origin`, or neither. |
| <a id="api_disabled"></a>`api_disabled` | 403 | The site owner switched the API off. |
| <a id="not_found"></a>`not_found` | 404 | No such endpoint, API version or record. Also a pending, disabled or spam listing, a disabled user, an unapproved comment that is not the caller's, and a photo, key or saved search that is not the caller's. |
| <a id="method_not_allowed"></a>`method_not_allowed` | 405 | The path exists but not for this method. `Allow` lists the methods that work. |
| <a id="idempotency_in_flight"></a>`idempotency_in_flight` | 409 | A request with the same `Idempotency-Key` is still running. `Retry-After: 1`. |
| <a id="too_large"></a>`too_large` | 413 | The body is over 1 MB, or a photo is over the site's largest photo size or 16 MB. |
| <a id="unsupported_media_type"></a>`unsupported_media_type` | 415 | The body is not `application/json` (or `application/merge-patch+json` on `PATCH`). A photo is `multipart/form-data` in a field named `photo`, or the image itself. |
| <a id="idempotency_key_reused"></a>`idempotency_key_reused` | 422 | The same `Idempotency-Key` was sent with a different request. |
| <a id="listing_limit"></a>`listing_limit` | 422 | The user has reached the site's listing limit. `detail` says what it is. |
| <a id="login_blocked"></a>`login_blocked` | 429 | Too many wrong passwords for this account or address, at `POST /auth/token`, `POST /account/password` or `POST /account/keys`. See `Retry-After`. |
| <a id="too_many_failures"></a>`too_many_failures` | 429 | Too many wrong keys from this address in 15 minutes. `Retry-After: 900`. See [lockout](/docs/developers/api/authentication/#lockout-after-failed-keys). |
| <a id="server_error"></a>`server_error` | 500 | The site hit an error; the cause is in the server's error log. Retry later. |
| <a id="maintenance"></a>`maintenance` | 503 | The site is in maintenance mode. `Retry-After: 900`. |

### `invalid_request`, `invalid_grant`, `invalid_scope`, `unsupported_grant_type`

<a id="invalid_request"></a><a id="invalid_grant"></a><a id="invalid_scope"></a><a id="unsupported_grant_type"></a>

400. Only from `POST /auth/token`, which answers as OAuth 2 asks (RFC 6749 §5.2): `invalid_request`
for a body that is missing members or cannot be read, `invalid_grant` for a wrong password, a
bad or reused refresh token, or an account that may not sign in, `invalid_scope` for a `scope`
naming no user scope, and `unsupported_grant_type` for a `grant_type` other than `password` or
`refresh_token`. The body also has the same `error` member.

### `unauthorized`

401. No key, or a key or token the site refused. The reason is never given. The answer carries
`WWW-Authenticate: Bearer` (nothing sent) or `Bearer error="invalid_token"` (something was sent).

### `token_expired`

401. An access token was genuine but has run out. Swap the refresh token for a new one and retry
the call. For a page token, reload the page. It is not counted as a failed guess. The answer carries
`WWW-Authenticate: Bearer error="invalid_token", error_description="The access token expired"`.
See [Authentication](/docs/developers/api/authentication/#access-tokens).

### `session_required`

401. A call with a page token (`X-Shopclass-Token`) whose sign-in cookie is missing or not valid,
whose page token belongs to another user or an old password, or whose user is suspended or not
confirmed. Reload the page. See
[Same-site session](/docs/developers/api/authentication/#same-site-session-theme-javascript).

### `wrong_credential`

403. The endpoint needs another kind of credential: an admin key, a full admin's key (not a
moderator's), a user's token or key, an access token (to change the password or sign out), or
a same-site session call. Posting or commenting where the site allows only signed-in users
also answers this.

### `conflict`

409. The request clashes with the current state:

- a stored `Idempotency-Key` answer that cannot be read
- an [admin action](/docs/developers/api/admin/) the state refuses: activating a blocked
  listing, deleting a currency a listing uses or the site defaults to, revoking a key that is
  already revoked

### `precondition_failed`

412. The resource changed since the `If-Match` value you sent. `GET` it again and retry. See
[Writes](/docs/developers/api/writes/#editing-safely-with-if-match).

### `validation_failed`

422. A query or body value broke a rule. Read `errors`. For listing searches this includes an
unknown `category`, a query parameter the endpoint does not take (`api_key` always works), a `limit` outside 1 to the site's maximum, and an unknown `locale`. For
writes it also covers the web form's own refusals: a missing field, an unknown place, an
unknown or expired `photo_tokens` entry, a wrong `current_password`, or a language the site
does not have. A write refused by the form has `code: "rejected"` on each entry in `errors`.

### `rate_limited`

429. Over a rate limit: the per-minute limits, or an hourly cap on listings, comments, photo
downloads, e-mail changes or sign-ups. Wait `Retry-After` seconds. See
[rate limits](/docs/developers/api/authentication/#rate-limits) and
[write limits](/docs/developers/api/writes/#limits).

## Handling errors

- Retry `429`, `500` and `503` after a wait. `429` and `503` say how long.
- On `401 token_expired`, refresh the token and retry once. Do not retry other `401`s unchanged.
- Do not retry `400`, `403`, `404`, `405` or `422` unchanged.
- Retry a write after a timeout with the same `Idempotency-Key`: [Writes](/docs/developers/api/writes/#retrying-safely-with-idempotency-key).
- Treat an unknown `code` by its status.
- Plugin endpoints use the same format. See [Plugin endpoints](/docs/developers/api/plugin-endpoints/#errors).
