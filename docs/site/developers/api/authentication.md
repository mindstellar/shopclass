---
title: API authentication
description: "How to authenticate to the ShopClass REST API: Bearer keys, user sign-in with access and refresh tokens, personal keys, same-site sessions for theme JavaScript, scopes, what each view returns, rate limits and the lockout after failed keys."
sidebar:
  order: 2
---

Send a credential in the `Authorization` header of every call.

```bash
curl -H "Authorization: Bearer scp_YOUR_KEY" https://example.com/api/v1/listings
```

There are five kinds of credential:

| Credential | Looks like | For | Made by |
|---|---|---|---|
| Public key | `scp_…` | An app or page that only reads. | The site owner |
| Admin key | `sck_…` | A script or server of the site owner. | The site owner |
| Access token | `sca_…` | An app acting for a signed-in user. Lives 15 minutes. | `POST /auth/token` |
| Personal key | `sck_…` | A user's own script. | The user |
| Same-site session | `scs_…` page token plus the sign-in cookie | Theme JavaScript on the site's own pages, as the signed-in user. | `osc_api_session_meta()` |

The site owner makes public and admin keys: [Set up the REST API](/docs/configure/api/).
Writing as a user (listings, comments, saved searches, the account) needs an access token, a
personal key or, from the site's own pages, a same-site session. Admin keys run the
[admin endpoints](/docs/developers/api/admin/).

## Keys

| Key | Prefix | Held by | May hold |
|---|---|---|---|
| Public key | `scp_` | An app or a web page. Safe to ship in client code. | `listings:read` only |
| Admin key | `sck_` | A script or server of the site owner. Keep secret. | `listings:read` and the `admin:*` scopes |
| Personal key | `sck_` | A user's script. Keep secret. | Some of that user's scopes, see [Personal keys](#personal-keys) |

A key is `<prefix><16 characters>.<64 hex digits>`. The secret half is stored hashed, so
a lost key cannot be recovered.

How a call is accepted:

| Rule | Detail |
|---|---|
| Header | `Authorization: Bearer <credential>`. Any other scheme, such as Basic auth, is ignored. |
| Query string | `?api_key=scp_…` works for **public keys on `GET` and `HEAD` only**. Other keys and tokens are never accepted there. See the warning below. |
| Cookies | Never used alone. Only a [same-site session](#same-site-session-theme-javascript) reads the sign-in cookie, and only with a page token in its header. The API sets no cookie. |
| No credential | `401`, unless the site owner allowed anonymous reads. Then reads work with the public scope and a lower per-address limit. |
| `GET /openapi.json` | Needs no credential. |

:::caution[`?api_key=` is written to logs]
A key in the address lands in web server and CDN access logs, browser history and `Referer`
headers. Anyone who can read those has it. A public key only reads what anonymous visitors
can, so the risk is someone using your rate limit. Send `Authorization: Bearer scp_…` when you
can, and rotate a key that leaked this way.
:::

Every refusal of a key answers the same `401 unauthorized`, whether the key is unknown,
has the wrong secret, is revoked, expired, or its owner is gone. A caller cannot tell them apart.

A key stops working when it is revoked, when it expires, or when its owner is disabled or
deleted. Its scopes also shrink to what its owner may hold today: demote an admin and the
admin keys they made lose their admin scopes.

## Signing in as a user

A user signs in with their password and gets two tokens. Use the access token for calls. Use
the refresh token to get a new one.

```bash
curl -X POST $API/auth/token \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"password","username":"jane@example.com","password":"…","label":"My phone"}'
```

```json
{
  "access_token": "sca_…",
  "token_type": "Bearer",
  "expires_in": 900,
  "scope": "listings:read listings:write listings:delete comments:write alerts:read alerts:write account:read account:write",
  "refresh_token": "scr_…",
  "refresh_expires_in": 2592000
}
```

The token endpoint follows OAuth 2 ([RFC 6749](https://www.rfc-editor.org/rfc/rfc6749)): the
members are at the top level, with no `data` wrapper, and the answer has
`Cache-Control: no-store`. A form works as well as JSON, so a standard OAuth client can sign in:

```bash
curl -X POST $API/auth/token \
  -d grant_type=password -d username=jane@example.com -d password=…
```

`POST /auth/token` needs no credential. It takes a JSON or `application/x-www-form-urlencoded` body:

| Member | Used by | Meaning |
|---|---|---|
| `grant_type` | both | `password` or `refresh_token`. Anything else is `400 unsupported_grant_type`. A site owner can switch `password` off in **Settings → API** ("Allow apps to sign in with a password"); it then answers `unsupported_grant_type` too, and refresh tokens keep working. |
| `username` | `password` | E-mail address or username. |
| `password` | `password` | The account's password. |
| `scope` | `password` | Scopes to ask for, space separated. Leave it out for every user scope. A list with no scope a user can hold is `400 invalid_scope`. |
| `label` | `password` | A name for this sign-in, up to 100 characters. Shown in the session list. |
| `refresh_token` | `refresh_token` | The `scr_…` token. |

Who may sign in: active, enabled accounts of a site with user accounts on. Admins use keys.
The site must have users on, or the answer is `403 feature_disabled`. A banned or suspended
account, or one that is not activated yet, gets `400 invalid_grant`.

### Access tokens

| | |
|---|---|
| Life | 15 minutes. `expires_in` says. |
| Sent as | `Authorization: Bearer sca_…` |
| Scopes | The ones granted at sign-in, cut down to what the user may hold today. |
| Ends early | When the user signs out, changes their password, signs out of all devices, or the account is disabled. A ban rule that matches the user or the address refuses it with `403 banned`. |

When it runs out, calls answer `401 token_expired`, not `unauthorized`:

```text
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer error="invalid_token", error_description="The access token expired"
```

`token_expired` means the token was genuine. Swap the refresh token for new tokens and retry
the call. `unauthorized` means the token is forged, or went stale: the password changed or
the account was disabled. Sign in again. An expired token is not counted as a failed guess.

### Refresh tokens

```bash
curl -X POST $API/auth/token \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"refresh_token","refresh_token":"scr_…"}'
```

The answer has the same shape as a sign-in, with a **new** refresh token.

| Rule | Detail |
|---|---|
| Single use | Each refresh token works once. Store the new one and drop the old. |
| Life | 30 days by default, counted from the last use. `refresh_expires_in` says. |
| Retry | The same token sent again within 30 seconds, before its new token is used, gets that same new token. A client that lost the answer keeps its sign-in. |
| Reuse | Any other second use ends the whole sign-in: `400 invalid_grant`, and every token of that sign-in stops. Sign in again. |
| Password change | Ends every sign-in. The one that changed the password gets a new one. |
| Other refusals | An unknown, expired or revoked token, or an account that may no longer sign in, is `400 invalid_grant`. |

Two requests with the same refresh token at once both get the same new token. Once a thread
uses that new token, a late refresh with the old one ends the sign-in, so a client with several
threads should still refresh in one place.

### Errors from the token endpoint

A refused grant is a `400` with the OAuth 2 `error` and `error_description` members
([RFC 6749 §5.2](https://www.rfc-editor.org/rfc/rfc6749#section-5.2)). The usual problem
members are there too: `code` is the same as `error`, and `detail` as `error_description`:

```json
{
  "type": "https://mindstellar.com/docs/developers/api/errors/#invalid_grant",
  "title": "The credentials or refresh token are not valid.",
  "status": 400,
  "detail": "The e-mail, username or password is wrong.",
  "code": "invalid_grant",
  "error": "invalid_grant",
  "error_description": "The e-mail, username or password is wrong.",
  "instance": "urn:request:Xq3v9LmT2aBc"
}
```

| Case | Status and `error` |
|---|---|
| Wrong password, unknown account, bad or reused refresh token | `400 invalid_grant` |
| Banned, not activated or suspended account | `400 invalid_grant` |
| Missing or unknown members, or a body that cannot be read | `400 invalid_request` |
| A `scope` naming no scope a user can hold | `400 invalid_scope` |
| Unknown `grant_type` | `400 unsupported_grant_type` |
| Too many wrong passwords | `429 login_blocked`, with `Retry-After`; no `error` |

Wrong passwords share the web sign-in's counter. An unknown account and a wrong password
answer the same, and take as long.

### Signing out

```bash
curl -X POST $API/auth/sign-out -H "Authorization: Bearer $TOKEN"
```

It answers `204` and ends this sign-in: its refresh token and its access tokens, at once. It
needs an access token, not a key. To end every sign-in, use `POST /account/sign-out-everywhere`
below.

Users see their sign-ins at `GET /account/sessions` and on the **API access** page of their
account, each with its `id`, `name` and `current`. `DELETE /account/sessions/{id}` ends one.
Personal keys are listed and revoked at `/account/keys`.

### Signing out of all devices

```bash
curl -X POST $API/account/sign-out-everywhere -H "Authorization: Bearer $TOKEN"
```

`204`. It needs an access token with `account:write`. Every access token, refresh token, page
token and personal key of the user stops at once, this access token too, and so does every web
sign-in. Sign in again to carry on. An admin key with `admin:users` does the same for any user
with `POST /admin/users/{id}/sign-out-everywhere`. The account page and the admin Users screen
have the same button.

An admin's own **Sign out of all devices** (Edit profile) also revokes every admin and public
key that admin made. Other admins' keys keep working.

### Changing the password

`POST /account/password` with `current_password` and `new_password` needs an access token
with `account:write`. Like the web form, it signs the user out everywhere: every sign-in and
personal key ends. The answer is a new sign-in for this client, with the same label: an access
token and a refresh token. Store both. Wrong passwords count toward the same limit as sign-in.

## Personal keys

A personal key is for a user's own script: a long-lived credential that needs no sign-in
dance. The site owner must switch on **Let users make personal API keys**, or `/account/keys`
answers `403 feature_disabled`. While it is off, existing personal keys answer `403
feature_disabled` too; they work again when it is switched back on.

```bash
curl -X POST $API/account/keys \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Stock script","scopes":["listings:read","listings:write"],"expires_at":"2027-03-01","current_password":"…"}'
```

```json
{
  "data": {
    "id": 7,
    "name": "Stock script",
    "prefix": "sck_Ab3dEf5gHi7jKl9M",
    "scopes": ["listings:read", "listings:write"],
    "status": "active",
    "created_at": "2026-10-03T09:12:00Z",
    "last_used_at": null,
    "expires_at": "2027-03-01T23:59:59Z",
    "token": "sck_Ab3dEf5gHi7jKl9M.…"
  }
}
```

`token` is in this answer only. It is not stored, so copy it now.

| Rule | Detail |
|---|---|
| Who calls | An access token with `account:write`. A key cannot make or list keys. |
| Password | `current_password` is checked again, and counts toward the sign-in limit. A wrong one is a `422` on `/current_password`. |
| `name` | 1 to 100 characters. |
| `scopes` | At least one of: `listings:read`, `listings:write`, `listings:delete`, `comments:write`, `alerts:read`, `alerts:write`, `account:read`, plus any plugin scope users may hold. Never `account:write`. |
| `expires_at` | Required. `YYYY-MM-DD`, the last day it works, in the future and within a year (366 days). |
| Ends on sign-out | The key is revoked when the user changes their password or signs out of all devices. Make a new one. |
| Ends also | When revoked, expired, or the user is disabled or deleted. |
| Manage | `GET /account/keys`, `GET /account/keys/{id}`, `DELETE /account/keys/{id}`, or the account's **API access** page. |

Calls with a personal key count against the key's own rate limit, like an admin key.

## Same-site session (theme JavaScript)

A theme's own JavaScript can call the API as the visitor who is signed in on the site: save a
search, post a comment, edit a listing, without a key and without asking for the password again.
The browser sends the sign-in cookie. The page adds a short-lived **page token** in a header.

| Use | When |
|---|---|
| Same-site session | JavaScript on this site's own pages, acting for the signed-in visitor. |
| Public key | A page or app that only reads public data, on this site or another. |
| Access token | An app, or a page on another site, acting for a user who signs in to it. |
| Personal key or admin key | A script or server. Never in a page. |

Print the meta tag in the theme's `<head>`:

```php
<?php echo osc_api_session_meta(); ?>
```

```html
<meta name="shopclass-api" content="{&quot;url&quot;:&quot;https://example.com/api/v1&quot;,&quot;token&quot;:&quot;scs_…&quot;,&quot;header&quot;:&quot;X-Shopclass-Token&quot;,&quot;expires_at&quot;:&quot;2026-10-04T14:00:00Z&quot;}">
```

Its content is JSON: `url` (the API address), `token` (empty when nobody is signed in),
`header` and `expires_at`. It is empty when the API is off. `osc_api_session_token()` returns
the token alone.

```js
const api = JSON.parse(document.querySelector('meta[name="shopclass-api"]').content);

const res = await fetch(api.url + '/account/alerts', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', [api.header]: api.token },
  body: JSON.stringify({ q: 'bike', category: [12] }),
});
```

`fetch()` sends the cookie on a same-origin call by default. Do not send `credentials: 'include'`
to another site.

A page token lives 2 hours. A page left open longer can get a new one before it runs out:

```js
const fresh = await fetch(api.url + '/auth/session', { headers: { [api.header]: api.token } });
api.token = (await fresh.json()).data.token;
```

`GET /auth/session` answers `token`, `header` and `expires_at`. It works only for a session
call, with a page token that still works. After that, reload the page.

Every rule must pass, or the call is refused. A call with a page token is never run as anonymous:

| Rule | Detail |
|---|---|
| Page token | Only in the `X-Shopclass-Token` header. Never read from the query string or the body. It names the user and their sign-out stamp, so another user's token, or one made before a password change or a sign-out of all devices, is refused: `401 session_required`. An expired one is `401 token_expired`. |
| Sign-in cookie | The site's signed sign-in cookie for that same user. With no valid cookie: `401 session_required`. A cookie with no page token stays anonymous. |
| Same origin | `Origin` must be the site's own scheme, host and port: `https://example.com` for a site at `https://example.com/shop/`. A GET with no `Origin` must show `Sec-Fetch-Site: same-origin`, or a `Referer` from the site. Anything else is `403 cross_origin`. `http` and `https`, other ports and subdomains are other origins. |
| User only | An active, enabled user who is not banned. Suspended or unconfirmed: `401 session_required`. Banned: `403 banned`. An admin signed in to the admin panel gets nothing here. |
| Scopes | Every user scope except `account:write`: no profile, password or e-mail change, no keys, no ending sign-ins. No admin scope. |
| No CORS | An answer never carries `Access-Control-Allow-Origin`, even when the site allows `*`. `X-Shopclass-Token` is not an allowed CORS header, so a page on another site cannot send it. |
| Never stored | Answers are `Cache-Control: private, no-store` and vary on `Cookie`. |

A call with `Authorization: Bearer` or `?api_key=` is a key or token call: the page token is
ignored. Calls count in the user's `api_user` bucket, shared with their access tokens.
`Idempotency-Key` values are kept per user for session calls.

The sign-in cookie is `SameSite=Lax` and `HttpOnly`, so other sites' pages cannot send it with
a `fetch()`, and page scripts cannot read it.

Two installs on the same origin, such as `example.com/a/` and `example.com/b/`, share one
origin, so they can read each other's pages. Give each its own host.

## What each credential can do

| Call | Public key | Admin key | Access token | Personal key | Same-site session |
|---|---|---|---|---|---|
| Read listings, categories, locations, public profiles | yes | yes | yes | yes, with `listings:read` | yes |
| Admin view and non-live listings | no | with `admin:*` | no | no | no |
| Post, edit, delete own listings and photos | no | no | yes | with `listings:write` / `listings:delete` | yes |
| Comment, delete own comment | no | no | yes | with `comments:write` | yes |
| Saved searches (`/account/alerts`) | no | no | yes | with `alerts:read` / `alerts:write` | yes |
| Read the account, own listings in any status, and sessions | no | no | yes | with `account:read` | yes |
| Edit the account, change password, manage keys | no | no | yes | no | no |
| `POST /auth/token`, `POST /users` | no credential needed | | | | |
| `POST /auth/sign-out` | no | no | yes | no | no |
| `POST /account/sign-out-everywhere` | no | no | yes | no | no |
| `GET /auth/session` | no | no | no | no | yes |

Writes to a user's own data need an access token or a personal key. An admin key is not a
user, so it gets `403 wrong_credential` there.

## Scopes

A scope is `resource:verb`. A call needs the scope its endpoint names. Every read
endpoint needs `listings:read`, and any `admin:*` scope also grants it. `listings:write`
also grants `listings:read`, and `account:write` also grants `account:read`.

| Scope | Held by | What it does |
|---|---|---|
| `listings:read` | Everyone | Read listings, categories, fields, locations, currencies and public profiles. |
| `listings:write` | Users | Post and edit own listings, upload photos. |
| `listings:delete` | Users | Delete own listings. |
| `comments:write` | Users | Post comments, delete own comments. |
| `alerts:read` | Users | List own saved searches. `alerts:write` includes it. |
| `alerts:write` | Users | Make and stop own saved searches. |
| `account:read` | Users | Read the own account, the own listings in any status, and the list of sign-ins and keys. |
| `account:write` | Access tokens only | Edit the account, change the password, manage personal keys (listing them too, so a key never sees other keys), end sessions. |
| `admin:listings` | Admin keys, moderators' keys | The admin view of listings, reading listings that are not live, and `/admin/listings`. |
| `admin:comments` | Admin keys, moderators' keys | Moderate comments: `/admin/comments`. |
| `admin:users` | Admin keys | The admin view of users, reading disabled users, and `/admin/users`. |
| `admin:taxonomy` | Admin keys | The admin view of categories, and `/admin/categories`, `/admin/currencies`, `/admin/custom-fields` and the location endpoints. |
| `admin:settings` | Admin keys | `/admin/settings` and `/admin/jobs`. |
| `admin:keys` | Admin keys | `/admin/keys`. |
| `admin:webhooks` | Admin keys | `/admin/webhooks` and `/admin/webhook-events`. |

A moderator's key may hold only `listings:read`, `admin:listings` and `admin:comments`.
Every admin endpoint is listed in [Admin endpoints](/docs/developers/api/admin/).

Plugins can add scopes named `ext:<plugin-slug>:<verb>`. Each declares who may hold it.
See [Plugin endpoints](/docs/developers/api/plugin-endpoints/#scopes).

A call missing a scope gets `403 insufficient_scope` and a header that names it:

```text
WWW-Authenticate: Bearer error="insufficient_scope", scope="admin:users"
```

## Views

The same resource looks different to different callers. There are three views.

| View | Who gets it |
|---|---|
| `public` | Everyone: public keys, anonymous calls, and admin keys without the resource's admin scope. |
| `owner` | The user a resource belongs to, calling with their own key or token. |
| `admin` | An admin key holding the resource's admin scope. |

| Resource | Scope that gives the admin view |
|---|---|
| Listing | `admin:listings` |
| User | `admin:users` |
| Category | `admin:taxonomy` |

What each view adds:

| Resource | public | owner and admin add |
|---|---|---|
| Listing | `contact.email` only when the seller chose to show it. `contact.phone` unless the site hides it. Neither on an expired listing, nor for a caller who is not a signed-in user when only users may contact sellers. | `contact.email` and `contact.phone` always, plus `show_email`. |
| Listing, admin only | | `ip`, `stats` (report counters). Listings that are not live can be read. |
| User | `id`, `name`, `username`, `url`, `avatar`, `is_company`, `website`, `location`, `listings_count`, `registered_at`. Only active users. | `email`, `phone_land`, `phone_mobile`, `address`, `zip`, `lat`, `lng`, `confirmed`, `blocked`, `last_access_at`, `last_access_ip`. |

The listing's edit secret is never sent in any view. A user's access token or personal key
gets the `owner` view of their own listings and their own account.

Plugin fields in `ext` are sent in the views their plugin declared. An undeclared field is
sent to the admin view only.

## Rate limits

Limits count requests in a fixed 60-second window. The site owner sets the numbers.

| Bucket | Counted per | Default |
|---|---|---|
| `api_anon` | IP address, no key | 60 a minute |
| `api_key` | admin key or personal key | 120 a minute |
| `api_key` | public key and IP address together | 120 a minute |
| `api_user` | signed-in user (access token or same-site session) | 120 a minute |
| `api_write` | an extra bucket for `POST`, `PUT`, `PATCH`, `DELETE` | 30 a minute |

Posting has its own hourly limits on top: see [Writes](/docs/developers/api/writes/#limits).

IPv6 addresses count by their `/64`. An IPv4-mapped IPv6 address counts as that IPv4 client.

Answers to keys and tokens carry the limit headers. Answers that a CDN may cache (anonymous and
public-key reads) leave them out, so one caller's counter is never cached for another.
A `429` always carries them.

| Header | Example | Meaning |
|---|---|---|
| `RateLimit-Policy` | `"api_key";q=120;w=60` | Limit `q` per window of `w` seconds. |
| `RateLimit` | `"api_key";r=119;t=57` | `r` requests left, `t` seconds to the reset. |
| `X-RateLimit-Limit` | `120` | Same as `q`. |
| `X-RateLimit-Remaining` | `119` | Same as `r`. |
| `X-RateLimit-Reset` | `1791058440` | Unix time of the reset. |
| `Retry-After` | `57` | On `429` only: seconds to wait. |

When a call counts against two buckets, `RateLimit` and `X-RateLimit-*` describe the one
closest to its limit.

`RateLimit` and `RateLimit-Policy` follow the IETF draft. The `X-RateLimit-*` set says the same
for clients and proxies that only know the older names; read whichever your client knows.

```json
{
  "type": "https://mindstellar.com/docs/developers/api/errors/#rate_limited",
  "title": "Too many requests.",
  "status": 429,
  "detail": "Too many requests. Try again shortly.",
  "code": "rate_limited",
  "instance": "urn:request:Xq3v9LmT2aBc"
}
```

Wait for `Retry-After`, then retry. The counter fails open: if the site cannot reach it,
requests are allowed.

## Lockout after failed keys

Wrong keys are counted so nobody can guess one.

| Failures in 15 minutes | Result |
|---|---|
| 20 for one key id from one address | That key id is refused from that address, **even with the right secret**. |
| 500 from one address, with no known key id | Every wrong token from that address gets `429` instead of `401`. A valid token still works, so a shared address (an office, carrier NAT) is not locked out. |

Both answer `429 too_many_failures` with `Retry-After: 900` until the window passes. One
address's failures never lock out another address.

Fix the key before retrying. A client that retries a `401` in a loop locks itself out.
An expired access token (`token_expired`) is not counted. Wrong passwords at
`POST /auth/token` have their own limit: `429 login_blocked`.

## Safe use

- Put a public key in an app or page. Never put an admin key or a personal key there.
- In a mobile app, keep the refresh token in the platform's secure storage.
- Keep an admin key in a server environment variable, not in source control.
- Give each app its own key, so one leak is one revoke.
- Give an admin key only the scopes it needs, and an expiry if the job is temporary.
- In a browser, the site owner must list your site under **Allowed origins**.
- In the site's own theme, use a [same-site session](#same-site-session-theme-javascript) instead of a key.
