---
title: API changelog
description: "Changes to the ShopClass REST API: what v1 promises, how endpoints are deprecated and retired, and a dated list of changes."
sidebar:
  order: 6
---

This page is the API's own log. The site's release notes link here for API changes.

## Versioning

The version is in the path: `/api/v1`. `GET /api/v1/` reports it as `api_version`.

Inside v1, a change only **adds**:

- a new endpoint
- a new member in an answer
- a new optional parameter
- a new value in a list of allowed values

Clients must ignore members they do not know.

These need a new version, v2:

- removing or renaming a member, endpoint or parameter
- changing a member's type or meaning
- changing a status code, or the `code` of an existing error
- making an optional parameter required
- changing how authentication works

If v2 ships, it runs beside v1 for at least 12 months.

## Deprecation policy

1. **Announced.** The endpoint or member is marked deprecated in a release and listed
   below with its end date.
2. **Warned.** Every answer from a deprecated endpoint carries these headers:

   | Header | Value |
   |---|---|
   | `Deprecation` | `@<unix time>`: when it was deprecated ([RFC 9745](https://www.rfc-editor.org/rfc/rfc9745)) |
   | `Sunset` | An HTTP date: when it stops answering ([RFC 8594](https://www.rfc-editor.org/rfc/rfc8594)) |
   | `Link` | `<…/docs/developers/api/changelog/>; rel="deprecation"` |

   The OpenAPI document marks the operation `deprecated: true`.
3. **Removed.** Not before the next major release, and not sooner than six months after
   the `Sunset` header first shipped.

A route with a `deprecated` date but no `sunset` has no removal date yet.

Plugin endpoints can use the same headers: set `deprecated` and `sunset` in the route spec
([Plugin endpoints](/docs/developers/api/plugin-endpoints/#the-spec)).

To catch a deprecation early, log any answer that has a `Deprecation` header.

## Current deprecations

| What | Since | Use instead |
|---|---|---|
| Listing Import's old paths: `POST /listings`, `POST /listings:batch`, `PUT /listings/{external_id}`, `DELETE /listings/{external_id}`, `GET /runs/{id}` | 2026-10-04 | The same paths under `/api/v1/ext/listing-import/` |

These answer only while the Listing Import plugin is active. They redirect (`301` for `GET`,
`308` for the rest, so the method and body are kept) to the new path. They are removed in 7.1.

## Changes

### v1: admin and webhooks

Added to v1.

| Endpoint | Does |
|---|---|
| `/admin/listings`, with `bump` | Moderate and edit any listing |
| `/admin/comments`, with `approved` and `blocked` on `PATCH` | Moderate comments |
| `/admin/users`, with `confirmed` and `blocked` on `PATCH`, and `/admin/users/{id}/sessions` | Manage users and end their sign-ins |
| `GET /account/listings` | The user's own listings in any status |
| `/admin/categories`, `/admin/currencies`, `/admin/custom-fields` | Manage categories, currencies and custom fields |
| `/admin/regions`, `/admin/cities`, `/admin/areas` | Manage locations |
| `GET`, `PATCH /admin/settings`, `GET /admin/jobs` | Settings from a fixed list, and the job queue |
| `/admin/keys`, with `rotate` | Manage API keys |
| `/admin/webhooks`, with `rotate-secret`, `test`, `deliveries`, and `GET /admin/webhook-events` | Manage webhook endpoints |
| `GET /comments/{id}`, `GET /listings/{id}/photos/{photo}`, `GET /account/keys/{id}`, `GET /account/alerts/{id}` | Read one thing back, so every `Location` can be read |

Also added:

- Webhooks: signed deliveries in the Standard Webhooks layout for ten events
  (`listing.created`, `listing.updated`, `listing.deleted`, `listing.activated`,
  `listing.deactivated`, `listing.spam`, `comment.created`, `user.registered`, `user.updated`,
  `user.deleted`), with retries, auto-pause and secret rotation. Plugins add events named
  `ext.<slug>.<name>`.
- Scopes now used: `admin:comments`, `admin:settings`, `admin:keys`, `admin:webhooks`.
  `admin:listings`, `admin:users` and `admin:taxonomy` also open their admin endpoints.
- An `api` member on `GET /`: `registration`, `personal_keys`, `photo_urls`, `public_reads`.
- Hooks: `api_webhook_events`, `api_webhook_payload`, `api_webhook_delivered`. Function:
  `osc_webhook_emit()`.
- Settings: **Allow webhook addresses on a private network**.
- No new error codes. Admin actions the state refuses answer `409 conflict`.

Also settled before the first release, so no released client saw the old behaviour:

- `POST /photos` answers `200` with no `Location`, because a token is not a resource to read.
- On public routes an admin key runs as nobody. Admin rights apply on `/admin/` routes only.
- A `GET` route refuses a query parameter it does not take with `422`. `api_key` is always allowed.
- Each `POST` under `/admin/regions`, `/admin/cities`, `/admin/areas`, `/admin/currencies` and `/admin/custom-fields` has a `GET` for the new item, and `Location` points at it.
- `Problem.code` is an open string in the OpenAPI document, and `info.version` is `1`.
- `If-Match` checks the stored version, so a tag from a `GET` with `fields`, `include` or `locale` works. The check and the write run as one. A `PATCH` sent with `If-Match` answers with the new `ETag`.
- A credential that cannot read the `GET` of the path gets `412` for `If-Match`, instead of the check being skipped.
- A stale `If-Match` on a resource the credential cannot see answers as its `GET` does (`404`), not `412`.
- `If-Match`, `*` included, on a resource that is gone answers as its `GET` does and runs nothing, instead of leaving it to the write.
- A refresh token sent again within 30 seconds, before its new token is used, gets that same new token instead of ending the sign-in.
- `Idempotency-Key` treats another `If-Match` as another request, and a lock outlives the longest a PHP request may run.
- `GET /` no longer shows the software version, and `features.public_reads` is gone: `api.public_reads` says the same.
- Token endpoint errors carry `error_description`, as RFC 6749 names it.
- An unknown `fields` or `include` value is `422 validation_failed` at `/fields` or `/include`, as every other bad query value is. `invalid_query` is gone.
- With comments off, reading a listing's comments is `403 feature_disabled`, as posting one is. Revoking a personal key twice is `409`, as for an admin key. `POST /users` sends no `Location`, since the new account cannot be read until it is confirmed.
- `{photo}` in a path is an integer, as `{id}` is. An API key's `owner` is `{type, id, name}` instead of a name.
- The OpenAPI document declares the `X-RateLimit-*` headers, the `200` of `POST /account/alerts` for a search already saved, and `400` instead of `422` on `POST /auth/token`.
- New scope `alerts:read`, which `alerts:write` includes, for reading saved searches.
- Sessions are sign-ins only, each with a `name`: keys are listed and revoked at `/account/keys` and `/admin/keys`. `POST /auth/sign-out` ends this sign-in only; `POST /account/sign-out-everywhere` ends them all.
- Every date input takes a day or an RFC 3339 date-time; both key types also take a number of days such as `90d`.
- A valid token from an address that sent many bad ones works; only that address's failing tokens answer `429 too_many_failures`.
- `Idempotency-Key` keeps every `4xx` except `429`, including a `409` or `422` from a core refusal.
- A banned user's access token or personal key answers `403 banned`, as a session call does.
- A key made through `POST /admin/keys` cannot outlive the key that makes it.
- `POST /auth/revoke` is now `POST /auth/sign-out`.
- A list that stops at the offset paging limit says so with `meta.truncated: true`.
- A rotated key cannot outlive the key that rotates it.
- A listing's contact e-mail and phone are hidden as its page hides them.
- Listing status changes go through `PATCH /admin/listings/{id}` (`approved`, `blocked`, `spam`, `premium`). Only `bump` stays an action.
- Custom fields are `custom_fields` everywhere: the listing and category member, `include=custom_fields`, the write body, the `custom_field[<id>]` filter, and the `/custom-fields` and `/admin/custom-fields` paths. `fields` is only the sparse fieldset.
- A `403` names its reason: `wrong_credential`, `banned` or `feature_disabled`. `forbidden` is left for the other refusals.
- Every list has `links.next`, `null` on a list answered whole, so a list can be paged later without breaking clients.
- `GET /admin/listings` takes `user` and `category` as lists, as search does. `category` takes slugs and includes subcategories.
- Admin comments read back `approved` and `blocked`. Users read and filter on `confirmed` and `blocked` instead of `active` and `enabled`. Sign-up answers `confirmed`.

See [Admin endpoints](/docs/developers/api/admin/) and [Webhooks](/docs/developers/api/webhooks/).

### v1: writes and sign-in

Added to v1. Nothing that was there changed.

| Endpoint | Does |
|---|---|
| `POST /auth/token` | Sign in with a password, or swap a refresh token for new tokens |
| `POST /auth/sign-out` | Sign out one sign-in, or all with `all: true` |
| `GET`, `PATCH /account`, `POST /account/password` | The user's own profile and password |
| `GET /account/sessions`, `DELETE /account/sessions/{id}` | The user's sign-ins |
| `GET`, `POST /account/keys`, `DELETE /account/keys/{id}` | Personal API keys, when the site allows them |
| `POST /users` | Sign up, when the site allows it |
| `POST /listings`, `PATCH /listings/{id}`, `DELETE /listings/{id}` | Post, edit and delete the user's listings |
| `POST /photos`, `POST /listings/{id}/photos`, `DELETE /listings/{id}/photos/{photo}` | Upload, add and remove photos |
| `POST /listings/{id}/comments`, `DELETE /comments/{id}` | Comment, and delete one's own comment |
| `GET`, `POST /account/alerts`, `DELETE /account/alerts/{id}` | Saved searches |

Also added:

- Credentials: access tokens (`sca_`, 15 minutes), single-use refresh tokens
  (`scr_`) with reuse detection, and personal keys (`sck_`) that expire within a year and stop
  when the password changes.
- Scopes now held by users: `listings:write`, `listings:delete`, `comments:write`,
  `alerts:write`, `account:read`, `account:write`.
- The `owner` view for a user's own listings and account.
- `Idempotency-Key` on writes, answered again with `Idempotency-Replayed: true`.
- `PATCH` takes `application/merge-patch+json` and answers `Accept-Patch`.
- `Location` on every `201`, and `warnings` next to `data`: `listing_pending`, `photo_skipped`,
  `comment_pending`, `email_confirmation_sent`.
- Error codes now returned: `token_expired`, `not_owner`, `listing_limit`,
  `login_blocked`, `idempotency_key_reused`, `idempotency_in_flight`, `conflict`. New codes:
  `invalid_header`, and at `POST /auth/token` the OAuth 2 ones: `invalid_request`,
  `invalid_grant`, `invalid_scope`, `unsupported_grant_type`. The token endpoint also takes a form.
- Settings: personal keys, sign-up, photos by address, and new listings per user an hour.
- Hourly caps for new listings, comments, photo downloads, e-mail changes and sign-ups.

See [Writes](/docs/developers/api/writes/) and [Authentication](/docs/developers/api/authentication/#signing-in-as-a-user).

### v1: first release

Read-only endpoints, authenticated with keys.

| Endpoint | Returns |
|---|---|
| `GET /` | The site, its locales and links |
| `GET /openapi.json` | The OpenAPI 3.1 document, with the site's plugin endpoints. No key needed. |
| `GET /listings`, `GET /listings/{id}` | Search, and one listing |
| `GET /listings/{id}/photos`, `GET /listings/{id}/comments` | A listing's photos and approved comments |
| `GET /categories`, `GET /categories/{category}`, `GET /custom-fields` | Categories (flat or tree) and custom fields |
| `GET /countries`, `/countries/{code}/regions`, `/regions/{id}/cities`, `/cities/{id}/areas` | Locations |
| `GET /currencies` | Currencies |
| `GET /users/{id}`, `GET /users/{id}/listings` | A public profile and the user's live listings |

Also in the first release:

- Public keys (`scp_`) and admin keys (`sck_`), made on **Admin → Settings → API** or with
  `api:key:create`.
- Cursor paging, sparse fieldsets (`fields`), `ETag` and `If-None-Match`.
- Rate limits with `RateLimit` and `X-RateLimit-*` headers, and a lockout after failed keys.
- CORS for origins the site owner lists.
- Errors as `application/problem+json`.
- For plugins: `ext/<slug>/` routes, `ext:<slug>:<verb>` scopes, and declared fields under `ext`.
