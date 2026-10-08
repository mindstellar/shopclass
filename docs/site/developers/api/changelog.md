---
title: API changelog
description: "Changes to the ShopClass REST API: what v1 promises, how endpoints are deprecated and retired, and a dated list of changes."
sidebar:
  order: 6
---

This page is the API's own log. The site's release notes link here for API changes.

## Versioning

The version is in the path: `/api/v1`. `GET /api/<version>/` reports the version asked for as `api_version`. Each
version has its own OpenAPI document at `/api/<version>/openapi.json`; its `info.version` is
that document's revision (`1.0` for v1), whose minor part goes up when the version gains
something.

Inside v1, a change only **adds**:

- a new endpoint
- a new member in an answer
- a new optional parameter
- a new value in a list of allowed values

Clients must ignore members they do not know, and must accept values they do not know in
any list of allowed values: a new listing `status`, `warnings` code, field error code or
webhook event can arrive inside v1. Treat an unknown error `code` by its status.

These need a new version, v2:

- removing or renaming a member, endpoint or parameter
- changing a member's type or meaning
- changing a status code, or the `code` of an existing error
- making an optional parameter required
- changing how authentication works

If v2 ships, it runs beside v1 for at least 12 months. A core endpoint that does not change
serves both; one that does keeps its v1 form under `/api/v1`. These stay on v1 until they
opt in:

- plugin endpoints that do not list `versions`
- webhook bodies
- `osc_api_url()`, unless given a version

A member is deprecated inside a version by marking it `deprecated: true` in the OpenAPI
document and listing it below. It keeps its type and meaning until the version is retired.

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
- Scopes: `admin:comments`, `admin:settings`, `admin:keys`, `admin:webhooks`.
  `admin:listings`, `admin:users` and `admin:taxonomy` also open their admin endpoints.
- An `api` member on `GET /`: `registration`, `personal_keys`, `photo_urls`, `public_reads`.
- Hooks: `api_webhook_events`, `api_webhook_payload`, `api_webhook_delivered`. Function:
  `osc_webhook_emit()`.
- Settings: **Allow webhook addresses on a private network**.
- No new error codes. Admin actions the state refuses answer `409 conflict`.

See [Admin endpoints](/docs/developers/api/admin/) and [Webhooks](/docs/developers/api/webhooks/).

### v1: writes and sign-in

Added to v1.

| Endpoint | Does |
|---|---|
| `POST /auth/token` | Sign in with a password, or swap a refresh token for new tokens |
| `POST /auth/sign-out` | Sign out this sign-in |
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
- Scopes held by users: `listings:write`, `listings:delete`, `comments:write`,
  `alerts:read`, `alerts:write`, `account:read`, `account:write`.
- The `owner` view for a user's own listings and account.
- `Idempotency-Key` on writes, answered again with `Idempotency-Replayed: true`.
- `PATCH` takes `application/merge-patch+json` and answers `Accept-Patch`.
- `Location` on every `201`, and `warnings` next to `data`: `listing_pending`, `photo_skipped`,
  `comment_pending`, `email_confirmation_sent`.
- Error codes: `token_expired`, `not_owner`, `listing_limit`,
  `login_blocked`, `idempotency_key_reused`, `idempotency_in_flight`, `conflict`,
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
