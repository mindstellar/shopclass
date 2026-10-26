---
title: API reference
description: "Every REST API endpoint: method, path, credential, scope, parameters and answers."
sidebar:
  order: 3
---

Generated from the route table by `tools/gen-api-doc.php`. The same endpoints are in
[openapi.json](/docs/developers/api/openapi.json)
for Swagger UI, Redoc or Postman, and you can browse them in the [API explorer](/docs/developers/api/explorer/). A site also serves its own copy, with its plugins'
endpoints, at `/api/v1/openapi.json`.

<!-- generated:api -->

## Account

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/account` | user | `account:read` | The signed-in user's own profile |
| PATCH | `/account` | user | `account:write` | Edit the profile; a new e-mail address is confirmed by a link first |
| GET | `/account/addresses` | user | `addresses:read` | Your saved delivery addresses, the default first |
| POST | `/account/addresses` | user | `addresses:write` | Save an address; the first one, or one sent with default true, becomes the default |
| DELETE | `/account/addresses/{address}` | user | `addresses:write` | Delete a saved address; deleting the default makes the next one the default |
| PUT | `/account/addresses/{address}` | user | `addresses:write` | Replace one saved address's fields |
| POST | `/account/addresses/{address}/default` | user | `addresses:write` | Make a saved address the default; it becomes the account's own address and the old default moves to the list |
| GET | `/account/alerts` | user | `alerts:read` | Your saved searches |
| POST | `/account/alerts` | user | `alerts:write` | Save a search, with the filters GET /listings takes, or a back-in-stock or price-drop alert on a product; an existing one is answered as it is |
| GET | `/account/alerts/{id}` | user | `alerts:read` | One of your saved searches |
| DELETE | `/account/alerts/{id}` | user | `alerts:write` | Stop a saved search |
| GET | `/account/keys` | user | `account:write` | Personal API keys, when the site allows them |
| POST | `/account/keys` | user | `account:write` | Make a personal API key; its token is shown once |
| GET | `/account/keys/{id}` | user | `account:write` | One personal API key; never its secret |
| DELETE | `/account/keys/{id}` | user | `account:write` | Revoke a personal API key |
| GET | `/account/listings` | user | `account:read` | Your own listings in any status, newest first |
| POST | `/account/password` | user | `account:write` | Change the password; every sign-in ends and this client gets a new one |
| GET | `/account/sessions` | user | `account:read` | Your live sign-ins; personal keys are at /account/keys |
| DELETE | `/account/sessions/{session}` | user | `account:write` | End one sign-in |
| POST | `/account/sign-out-everywhere` | user | `account:write` | Sign out of every device: web sign-ins, every API sign-in including this one, and personal keys |
| GET | `/account/wishlist` | user | `wishlist:read` | Your wishlist: listing ids, newest first |
| PUT | `/account/wishlist` | user | `wishlist:write` | Replace your wishlist; listings that are not live are dropped |
| POST | `/account/wishlist/{listing}` | user | `wishlist:write` | Put a live listing at the top of your wishlist |
| DELETE | `/account/wishlist/{listing}` | user | `wishlist:write` | Take a listing off your wishlist |

### GET `/account`

The signed-in user's own profile

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`UserDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/account`

Edit the profile; a new e-mail address is confirmed by a link first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AccountInput` as JSON, optional.

Answers: 200 OK (`AccountDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/addresses`

Your saved delivery addresses, the default first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AddressList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/addresses`

Save an address; the first one, or one sent with default true, becomes the default

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AddressInput` as JSON.

Answers: 201 Created (`AddressDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/account/addresses/{address}`

Delete a saved address; deleting the default makes the next one the default

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `address` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### PUT `/account/addresses/{address}`

Replace one saved address's fields

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `address` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AddressInput` as JSON.

Answers: 200 OK (`AddressDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/addresses/{address}/default`

Make a saved address the default; it becomes the account's own address and the old default moves to the list

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `address` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`AddressList`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/alerts`

Your saved searches

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AlertList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/alerts`

Save a search, with the filters GET /listings takes, or a back-in-stock or price-drop alert on a product; an existing one is answered as it is

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AlertInput` as JSON, optional.

Answers: 200 OK (`AlertDocument`); 201 Created (`AlertDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/alerts/{id}`

One of your saved searches

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AlertDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/account/alerts/{id}`

Stop a saved search

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/keys`

Personal API keys, when the site allows them

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`PersonalKeyList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/keys`

Make a personal API key; its token is shown once

Body: `PersonalKeyInput` as JSON.

Answers: 201 Created (`PersonalKeyDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/keys/{id}`

One personal API key; never its secret

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`PersonalKeyDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/account/keys/{id}`

Revoke a personal API key

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/listings`

Your own listings in any status, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `status` | query | string or array | no | One value, a comma list, or repeated. |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `count` | query | boolean | no | true: also count every match for meta.total; skipped otherwise, as it costs a query. |
| `include` | query | string | no | Comma list: custom_fields, translations, rating (the approved ratings: average, count and, on one listing, the count of each star). |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ListingPage`); 304 Not modified; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/password`

Change the password; every sign-in ends and this client gets a new one

Body: `PasswordChange` as JSON.

Answers: 200 OK (`TokenDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/sessions`

Your live sign-ins; personal keys are at /account/keys

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`SessionList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/account/sessions/{session}`

End one sign-in

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `session` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/sign-out-everywhere`

Sign out of every device: web sign-ins, every API sign-in including this one, and personal keys

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/wishlist`

Your wishlist: listing ids, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`WishlistDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PUT `/account/wishlist`

Replace your wishlist; listings that are not live are dropped

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `WishlistInput` as JSON.

Answers: 204 No content; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/wishlist/{listing}`

Put a live listing at the top of your wishlist

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `listing` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/account/wishlist/{listing}`

Take a listing off your wishlist

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `listing` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

## Admin comments

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/admin/comments` | admin | `admin:comments` | Every comment, whatever its status, newest first |
| GET | `/admin/comments/{id}` | admin | `admin:comments` | One comment |
| PATCH | `/admin/comments/{id}` | admin | `admin:comments` | Edit a comment's text or author, or approve or block it |
| DELETE | `/admin/comments/{id}` | admin | `admin:comments` | Delete a comment |

### GET `/admin/comments`

Every comment, whatever its status, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `status` | query | string or array | no | One value, a comma list, or repeated. |
| `listing` | query | string or array | no | Listing ids: one, a comma list, or repeated. |
| `user` | query | string or array | no | Author user ids: one, a comma list, or repeated. |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `count` | query | boolean | no | true: also count every match for meta.total; skipped otherwise, as it costs a query. |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminCommentPage`); 304 Not modified; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/comments/{id}`

One comment

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminCommentDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/comments/{id}`

Edit a comment's text or author, or approve or block it

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AdminCommentPatch` as JSON, optional.

Answers: 200 OK (`AdminCommentDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/comments/{id}`

Delete a comment

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

## Admin conversations

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/admin/conversations` | admin | `admin:conversations` | Every conversation, newest first |
| GET | `/admin/conversations/{id}` | admin | `admin:conversations` | One conversation with every message, held ones included |
| POST | `/admin/conversations/{id}/clear-report` | admin | `admin:conversations` | Drop the report on a conversation and open it again |
| POST | `/admin/conversations/{id}/close` | admin | `admin:conversations` | Close a reported conversation: nobody writes in it any more |
| GET | `/admin/messages` | admin | `admin:conversations` | Messages waiting for review, newest first |
| GET | `/admin/messages/{id}` | admin | `admin:conversations` | One message |
| POST | `/admin/messages/{id}/release` | admin | `admin:conversations` | Deliver a held message |
| POST | `/admin/messages/{id}/remove` | admin | `admin:conversations` | Drop a held message |

### GET `/admin/conversations`

Every conversation, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `status` | query | string: `open`, `held`, `closed`, `reported` | no |  |
| `user` | query | string or array | no | User ids, as buyer or seller: one, a comma list, or repeated. |
| `listing` | query | string or array | no | Listing ids: one, a comma list, or repeated. |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminConversationPage`); 304 Not modified; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/conversations/{id}`

One conversation with every message, held ones included

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminConversationDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/conversations/{id}/clear-report`

Drop the report on a conversation and open it again

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`AdminConversationDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/conversations/{id}/close`

Close a reported conversation: nobody writes in it any more

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`AdminConversationDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/messages`

Messages waiting for review, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `status` | query | string: `held` | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminMessagePage`); 304 Not modified; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/messages/{id}`

One message

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminMessageDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/messages/{id}/release`

Deliver a held message

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`AdminMessageDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/messages/{id}/remove`

Drop a held message

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`AdminMessageDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

## Admin listings

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/admin/listings` | admin | `admin:listings` | Every listing, whatever its status, newest first |
| GET | `/admin/listings/{id}` | admin | `admin:listings` | One listing in the admin view |
| PATCH | `/admin/listings/{id}` | admin | `admin:listings` | Edit any listing, its owner, expiry and status included; members not sent keep their values |
| DELETE | `/admin/listings/{id}` | admin | `admin:listings` | Delete a listing |
| POST | `/admin/listings/{id}/bump` | admin | `admin:listings` | Move a listing to the top of "newest first" |
| POST | `/admin/listings/{id}/publish` | admin | `admin:listings` | Publish a listing that waits for payment, without charging the seller |

### GET `/admin/listings`

Every listing, whatever its status, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `status` | query | string or array | no | One value, a comma list, or repeated. |
| `user` | query | string or array | no | User ids: one, a comma list, or repeated. |
| `category` | query | string or array | no | Category ids or slugs: one, a comma list, or repeated. Subcategories are included. |
| `q` | query | string | no | Titles containing this. |
| `include` | query | string | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `count` | query | boolean | no | true: also count every match for meta.total; skipped otherwise, as it costs a query. |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ListingPage`); 304 Not modified; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/listings/{id}`

One listing in the admin view

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `include` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ListingDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/listings/{id}`

Edit any listing, its owner, expiry and status included; members not sent keep their values

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AdminListingPatch` as JSON, optional.

Answers: 200 OK (`ListingDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/listings/{id}`

Delete a listing

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/listings/{id}/bump`

Move a listing to the top of "newest first"

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`ListingDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/listings/{id}/publish`

Publish a listing that waits for payment, without charging the seller

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`ListingDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

## Admin settings

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/admin/jobs` | admin | `admin:settings` | The background job queue: jobs per status and those that stopped retrying |
| GET | `/admin/keys` | admin | `admin:keys` | Every API key, newest first; never a secret |
| POST | `/admin/keys` | admin | `admin:keys` | Make an admin or public key; its token is shown once |
| GET | `/admin/keys/{id}` | admin | `admin:keys` | One API key; never its secret |
| DELETE | `/admin/keys/{id}` | admin | `admin:keys` | Revoke a key; never another admin's own key |
| POST | `/admin/keys/{id}/rotate` | admin | `admin:keys` | Make a new key in place of one of yours or a public key; the old one works until revoked |
| GET | `/admin/settings` | admin | `admin:settings` | The settings the API can change |
| PATCH | `/admin/settings` | admin | `admin:settings` | Change some settings, all or none, checked as on the settings screens |

### GET `/admin/jobs`

The background job queue: jobs per status and those that stopped retrying

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `limit` | query | integer | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`JobsDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/keys`

Every API key, newest first; never a secret

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ApiKeyList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/keys`

Make an admin or public key; its token is shown once

Body: `ApiKeyInput` as JSON.

Answers: 201 Created (`ApiKeyDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/keys/{id}`

One API key; never its secret

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ApiKeyDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/keys/{id}`

Revoke a key; never another admin's own key

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/keys/{id}/rotate`

Make a new key in place of one of yours or a public key; the old one works until revoked

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |

Answers: 201 Created (`ApiKeyDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/settings`

The settings the API can change

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`SettingsDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/settings`

Change some settings, all or none, checked as on the settings screens

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `SettingsPatch` as JSON, optional.

Answers: 200 OK (`SettingsDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Admin taxonomy

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| POST | `/admin/areas` | admin | `admin:taxonomy` | Add an area to a city |
| GET | `/admin/areas/{id}` | admin | `admin:taxonomy` | One city area |
| PATCH | `/admin/areas/{id}` | admin | `admin:taxonomy` | Rename a city area |
| DELETE | `/admin/areas/{id}` | admin | `admin:taxonomy` | Delete a city area and the listings in it |
| GET | `/admin/categories` | admin | `admin:taxonomy` | Every category, enabled or not, with each language's texts |
| POST | `/admin/categories` | admin | `admin:taxonomy` | Add a category |
| GET | `/admin/categories/{id}` | admin | `admin:taxonomy` | One category with each language's texts |
| PATCH | `/admin/categories/{id}` | admin | `admin:taxonomy` | Edit a category; a new slug keeps the old one redirecting |
| DELETE | `/admin/categories/{id}` | admin | `admin:taxonomy` | Delete a category, its subcategories and their listings; a large one is emptied in the background (202) |
| POST | `/admin/cities` | admin | `admin:taxonomy` | Add a city to a region |
| GET | `/admin/cities/{id}` | admin | `admin:taxonomy` | One city |
| PATCH | `/admin/cities/{id}` | admin | `admin:taxonomy` | Rename a city |
| DELETE | `/admin/cities/{id}` | admin | `admin:taxonomy` | Delete a city, its areas and the listings in it |
| POST | `/admin/currencies` | admin | `admin:taxonomy` | Add a currency |
| GET | `/admin/currencies/{code}` | admin | `admin:taxonomy` | One currency |
| PATCH | `/admin/currencies/{code}` | admin | `admin:taxonomy` | Rename a currency or change its symbol |
| DELETE | `/admin/currencies/{code}` | admin | `admin:taxonomy` | Delete a currency no listing uses and the site does not default to |
| POST | `/admin/custom-fields` | admin | `admin:taxonomy` | Add a custom field |
| GET | `/admin/custom-fields/{id}` | admin | `admin:taxonomy` | One custom field |
| PATCH | `/admin/custom-fields/{id}` | admin | `admin:taxonomy` | Edit a custom field |
| DELETE | `/admin/custom-fields/{id}` | admin | `admin:taxonomy` | Delete a custom field and its values |
| POST | `/admin/regions` | admin | `admin:taxonomy` | Add a region to a country |
| GET | `/admin/regions/{id}` | admin | `admin:taxonomy` | One region |
| PATCH | `/admin/regions/{id}` | admin | `admin:taxonomy` | Rename a region |
| DELETE | `/admin/regions/{id}` | admin | `admin:taxonomy` | Delete a region, its cities and the listings in it |

### POST `/admin/areas`

Add an area to a city

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CityAreaInput` as JSON.

Answers: 201 Created (`CityAreaDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/areas/{id}`

One city area

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CityAreaDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/areas/{id}`

Rename a city area

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CityAreaPatch` as JSON.

Answers: 200 OK (`CityAreaDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/areas/{id}`

Delete a city area and the listings in it

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/categories`

Every category, enabled or not, with each language's texts

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminCategoryList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/categories`

Add a category

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AdminCategoryInput` as JSON.

Answers: 201 Created (`AdminCategoryDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/categories/{id}`

One category with each language's texts

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminCategoryDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/categories/{id}`

Edit a category; a new slug keeps the old one redirecting

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AdminCategoryPatch` as JSON, optional.

Answers: 200 OK (`AdminCategoryDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/categories/{id}`

Delete a category, its subcategories and their listings; a large one is emptied in the background (202)

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 202 Accepted; 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/cities`

Add a city to a region

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CityInput` as JSON.

Answers: 201 Created (`CityDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/cities/{id}`

One city

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CityDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/cities/{id}`

Rename a city

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CityPatch` as JSON, optional.

Answers: 200 OK (`CityDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/cities/{id}`

Delete a city, its areas and the listings in it

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/currencies`

Add a currency

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CurrencyInput` as JSON.

Answers: 201 Created (`CurrencyDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/currencies/{code}`

One currency

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `code` | path | string | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CurrencyDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/currencies/{code}`

Rename a currency or change its symbol

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `code` | path | string | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CurrencyPatch` as JSON, optional.

Answers: 200 OK (`CurrencyDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/currencies/{code}`

Delete a currency no listing uses and the site does not default to

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `code` | path | string | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/custom-fields`

Add a custom field

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AdminCustomFieldInput` as JSON.

Answers: 201 Created (`AdminCustomFieldDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/custom-fields/{id}`

One custom field

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`AdminCustomFieldDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/custom-fields/{id}`

Edit a custom field

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AdminCustomFieldPatch` as JSON, optional.

Answers: 200 OK (`AdminCustomFieldDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/custom-fields/{id}`

Delete a custom field and its values

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/regions`

Add a region to a country

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `RegionInput` as JSON.

Answers: 201 Created (`RegionDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/regions/{id}`

One region

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`RegionDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/regions/{id}`

Rename a region

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `RegionPatch` as JSON, optional.

Answers: 200 OK (`RegionDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/regions/{id}`

Delete a region, its cities and the listings in it

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

## Admin users

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/admin/users` | admin | `admin:users` | Every user, newest first |
| GET | `/admin/users/{id}` | admin | `admin:users` | One user, every member |
| PATCH | `/admin/users/{id}` | admin | `admin:users` | Edit a user's profile, e-mail, username or password, or confirm or block them |
| DELETE | `/admin/users/{id}` | admin | `admin:users` | Delete a user with their listings, comments and saved searches |
| GET | `/admin/users/{id}/sessions` | admin | `admin:users` | A user's live sign-ins; their keys are at /admin/keys |
| DELETE | `/admin/users/{id}/sessions/{session}` | admin | `admin:users` | End one of a user's sign-ins |
| POST | `/admin/users/{id}/sign-out-everywhere` | admin | `admin:users` | Sign a user out of every device: web sign-ins, API tokens and personal keys |

### GET `/admin/users`

Every user, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `q` | query | string | no | E-mail, username or name starting with this. |
| `confirmed` | query | boolean | no |  |
| `blocked` | query | boolean | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `count` | query | boolean | no | true: also count every match for meta.total; skipped otherwise, as it costs a query. |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`UserPage`); 304 Not modified; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/users/{id}`

One user, every member

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`UserDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/users/{id}`

Edit a user's profile, e-mail, username or password, or confirm or block them

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `AdminUserPatch` as JSON, optional.

Answers: 200 OK (`UserDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/users/{id}`

Delete a user with their listings, comments and saved searches

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/users/{id}/sessions`

A user's live sign-ins; their keys are at /admin/keys

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`SessionList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/users/{id}/sessions/{session}`

End one of a user's sign-ins

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `session` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/users/{id}/sign-out-everywhere`

Sign a user out of every device: web sign-ins, API tokens and personal keys

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

## Admin webhooks

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/admin/webhook-events` | admin | `admin:webhooks` | The events an endpoint can subscribe to, plugin events included |
| GET | `/admin/webhooks` | admin | `admin:webhooks` | Every webhook endpoint, newest first; never a secret |
| POST | `/admin/webhooks` | admin | `admin:webhooks` | Add an endpoint; its signing secret is shown once |
| GET | `/admin/webhooks/{webhook}` | admin | `admin:webhooks` | One endpoint; never its secret |
| PATCH | `/admin/webhooks/{webhook}` | admin | `admin:webhooks` | Change an endpoint; switching it on clears a pause |
| DELETE | `/admin/webhooks/{webhook}` | admin | `admin:webhooks` | Delete an endpoint and its waiting deliveries |
| GET | `/admin/webhooks/{webhook}/deliveries` | admin | `admin:webhooks` | The endpoint's state and its deliveries still on the job queue |
| POST | `/admin/webhooks/{webhook}/rotate-secret` | admin | `admin:webhooks` | A new signing secret, shown once; the old one also signs for 24 hours |
| POST | `/admin/webhooks/{webhook}/test` | admin | `admin:webhooks` | Queue a ping to the endpoint, sent once even while it is off |

### GET `/admin/webhook-events`

The events an endpoint can subscribe to, plugin events included

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`WebhookEventList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/webhooks`

Every webhook endpoint, newest first; never a secret

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`WebhookList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/webhooks`

Add an endpoint; its signing secret is shown once

Body: `WebhookInput` as JSON.

Answers: 201 Created (`WebhookDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/webhooks/{webhook}`

One endpoint; never its secret

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `webhook` | path | string | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`WebhookDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/admin/webhooks/{webhook}`

Change an endpoint; switching it on clears a pause

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `webhook` | path | string | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `WebhookPatch` as JSON, optional.

Answers: 200 OK (`WebhookDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/admin/webhooks/{webhook}`

Delete an endpoint and its waiting deliveries

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `webhook` | path | string | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/admin/webhooks/{webhook}/deliveries`

The endpoint's state and its deliveries still on the job queue

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `webhook` | path | string | yes |  |
| `limit` | query | integer | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`WebhookDeliveriesDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/webhooks/{webhook}/rotate-secret`

A new signing secret, shown once; the old one also signs for 24 hours

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `webhook` | path | string | yes |  |

Answers: 200 OK (`WebhookDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/admin/webhooks/{webhook}/test`

Queue a ping to the endpoint, sent once even while it is off

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `webhook` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 202 Accepted (`WebhookTestDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

## Auth

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/auth/session` | user | `account:read` | A fresh page token, for theme JavaScript on a page open longer than its token lives |
| POST | `/auth/sign-out` | user | - | Sign out this sign-in; POST /account/sign-out-everywhere ends them all |
| POST | `/auth/token` | none | - | Sign in with a password, or swap a refresh token for new tokens |

### GET `/auth/session`

A fresh page token, for theme JavaScript on a page open longer than its token lives

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`SessionTokenDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/auth/sign-out`

Sign out this sign-in; POST /account/sign-out-everywhere ends them all

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/auth/token`

Sign in with a password, or swap a refresh token for new tokens

Body: `TokenRequest` as JSON.

Answers: 200 OK (`TokenDocument`); 400 Bad request; 403 Not allowed for this credential; 413 Body too large; 415 Unsupported content type; 429 Too many requests; 500 Server error; 503 Maintenance.

## Categories

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/categories` | public | `listings:read` | Every category, flat or as a tree |
| GET | `/categories/{category}` | public | `listings:read` | One category by id or slug, with its custom fields |
| GET | `/custom-fields` | public | `listings:read` | Custom fields, all or a category's |

### GET `/categories`

Every category, flat or as a tree

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `tree` | query | boolean | no |  |
| `roots` | query | boolean | no | Only the top-level categories, flat; ignored with tree. |
| `include` | query | string | no | Comma list: cover, the first photo of the newest live listing in the category or below it; null when there is none. |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CategoryList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/categories/{category}`

One category by id or slug, with its custom fields

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `category` | path | string | yes |  |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CategoryDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/custom-fields`

Custom fields, all or a category's

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `locale` | query | string | no |  |
| `category` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CustomFieldList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Conversations

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/account/conversations` | user | `conversations:read` | Your conversations, newest activity first; meta carries your unread total |
| POST | `/account/conversations` | user | `conversations:write` | Write to a listing's seller; a second message about the same listing goes to the conversation already there |
| GET | `/account/conversations/{id}` | user | `conversations:read` | One of your conversations |
| POST | `/account/conversations/{id}/block` | user | `conversations:write` | Block a conversation: nobody can write in it until you unblock it |
| DELETE | `/account/conversations/{id}/block` | user | `conversations:write` | Lift your own block; a block the other person set stays |
| GET | `/account/conversations/{id}/messages` | user | `conversations:read` | A conversation's messages, newest first; with after, the newer ones oldest first. Reading marks nothing read |
| POST | `/account/conversations/{id}/messages` | user | `conversations:write` | Reply in one of your conversations |
| POST | `/account/conversations/{id}/read` | user | `conversations:write` | Mark a conversation read |
| POST | `/account/conversations/{id}/report` | user | `conversations:write` | Report a conversation to the admins; it is also blocked for you |
| PUT | `/account/push-subscriptions` | user | `conversations:write` | Register a push endpoint to wake this device for new messages; the push carries no text |
| DELETE | `/account/push-subscriptions/{browser}` | user | `conversations:write` | Stop waking a device; the browser id is the one PUT answered |

### GET `/account/conversations`

Your conversations, newest activity first; meta carries your unread total

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ConversationPage`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/conversations`

Write to a listing's seller; a second message about the same listing goes to the conversation already there

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `ConversationInput` as JSON.

Answers: 201 Created (`MessageDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/conversations/{id}`

One of your conversations

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ConversationDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/conversations/{id}/block`

Block a conversation: nobody can write in it until you unblock it

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`ConversationDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/account/conversations/{id}/block`

Lift your own block; a block the other person set stays

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`ConversationDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/conversations/{id}/messages`

A conversation's messages, newest first; with after, the newer ones oldest first. Reading marks nothing read

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `after` | query | integer | no | A message id: answer the messages after it, oldest first, with no next page. cursor is then ignored. |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`MessagePage`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/conversations/{id}/messages`

Reply in one of your conversations

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `MessageInput` as JSON.

Answers: 201 Created (`MessageDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/conversations/{id}/read`

Mark a conversation read

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`ConversationDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/conversations/{id}/report`

Report a conversation to the admins; it is also blocked for you

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `ReportInput` as JSON.

Answers: 200 OK (`ConversationDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PUT `/account/push-subscriptions`

Register a push endpoint to wake this device for new messages; the push carries no text

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `PushSubscriptionInput` as JSON.

Answers: 200 OK (`PushSubscriptionDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/account/push-subscriptions/{browser}`

Stop waking a device; the browser id is the one PUT answered

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `browser` | path | string | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

## Listings

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/comments/{id}` | public | `listings:read` | One comment: an approved one on a listing you can see, or your own |
| DELETE | `/comments/{id}` | user | `comments:write` | Delete your own approved comment |
| GET | `/listings` | public | `listings:read` | Search listings |
| POST | `/listings` | user | `listings:write` | Post a listing; moderation, the listing limit and the posting wait apply as on the form |
| GET | `/listings/{id}` | public | `listings:read` | One listing |
| PATCH | `/listings/{id}` | user | `listings:write` | Edit your listing; members not sent keep their values |
| DELETE | `/listings/{id}` | user | `listings:delete` | Delete your listing |
| GET | `/listings/{id}/comments` | public | `listings:read` | A listing's approved comments, oldest first or newest first |
| POST | `/listings/{id}/comments` | user | `comments:write` | Comment on a listing; it may wait for approval |
| GET | `/listings/{id}/photos` | public | `listings:read` | A listing's photos |
| POST | `/listings/{id}/photos` | user | `listings:write` | Add a photo to your listing |
| GET | `/listings/{id}/photos/{photo}` | public | `listings:read` | One photo of a listing |
| DELETE | `/listings/{id}/photos/{photo}` | user | `listings:write` | Remove a photo from your listing |
| POST | `/listings/{id}/publish` | user | `listings:write` | Publish a listing that waits for payment: with your credits, or by starting an order for the credits it is short of. Needs orders:write too; 404 on a shop |
| GET | `/listings/{id}/related` | public | `listings:read` | Live listings like this one, best first: same category and shared title words, or what a search plugin picks |
| POST | `/photos` | user | `listings:write` | Upload a photo for a listing you are about to post; its token lasts two hours. 200, not 201: the token names no resource to read |

### GET `/comments/{id}`

One comment: an approved one on a listing you can see, or your own

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CommentDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/comments/{id}`

Delete your own approved comment

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/listings`

Search listings

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `q` | query | string | no |  |
| `category` | query | string or array | no | One value, a comma list, or repeated. |
| `country` | query | string or array | no | One value, a comma list, or repeated. |
| `region` | query | string or array | no | One value, a comma list, or repeated. |
| `city` | query | string or array | no | One value, a comma list, or repeated. |
| `city_area` | query | string or array | no | One value, a comma list, or repeated. |
| `user` | query | string or array | no | One value, a comma list, or repeated. |
| `locale` | query | string | no |  |
| `price_min` | query | integer | no |  |
| `price_max` | query | integer | no |  |
| `with_photos` | query | boolean | no |  |
| `premium` | query | boolean | no |  |
| `in_stock` | query | boolean | no | true: only listings with a variant in stock (stock not tracked, or above 0). |
| `on_sale` | query | boolean | no | true: only listings with a variant whose compare_at is above its price. With in_stock, one variant must be both. |
| `lat` | query | number | no | Measure distances from this point, with lng. Needs distance search switched on. |
| `lng` | query | number | no |  |
| `radius` | query | number | no | Only listings within this many km of lat/lng. |
| `bbox` | query | string | no | west,south,east,north: only listings whose point is inside this box, such as a map's view. West above east crosses the date line. With lat/lng/radius both apply. Needs distance search switched on. |
| `custom_field` | query | object | no | custom_field[<id>]=<value>, or custom_field[<id>][]=A&custom_field[<id>][]=B for a dropdown or radio field: any of up to 20 values. Needs a category filter; each id must be a searchable field of that category, else 422. |
| `option` | query | object | no | option[<name>][]=<value>: only listings with one variant that has, for every name given, one of its values (OR within a name, AND across names). At most 3 names and 10 values each, names up to 30 and values up to 40 characters; anything over is ignored. With in_stock or on_sale, the same variant must meet them too. |
| `ids` | query | string or array | no | One value, a comma list, or repeated. |
| `sort` | query | string: `created`, `id`, `price`, `relevance`, `distance` | no |  |
| `order` | query | string: `asc`, `desc` | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `page` | query | integer | no | Page number, for numbered pages. Any sort; not with cursor. Past the deepest page: 422 page_too_deep. With count=true, meta.page and meta.pages say where you are. |
| `count` | query | boolean | no | true: also count every match for meta.total; skipped otherwise, as it costs a query. |
| `fields` | query | string | no |  |
| `include` | query | string | no | Comma list: custom_fields, translations, rating (the approved ratings: average, count and, on one listing, the count of each star). |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ListingPage`); 304 Not modified; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/listings`

Post a listing; moderation, the listing limit and the posting wait apply as on the form

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `ListingInput` as JSON.

Answers: 201 Created (`SavedListing`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/listings/{id}`

One listing

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `include` | query | string | no | Comma list: custom_fields, translations, rating (the approved ratings: average, count and, on one listing, the count of each star). |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ListingDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PATCH `/listings/{id}`

Edit your listing; members not sent keep their values

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `ListingPatch` as JSON, optional.

Answers: 200 OK (`SavedListing`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/listings/{id}`

Delete your listing

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/listings/{id}/comments`

A listing's approved comments, oldest first or newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `count` | query | boolean | no | true: also count every match for meta.total; skipped otherwise, as it costs a query. |
| `sort` | query | string: `oldest`, `newest` | no | Default oldest. |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CommentPage`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/listings/{id}/comments`

Comment on a listing; it may wait for approval

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CommentInput` as JSON, optional.

Answers: 201 Created (`SavedComment`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/listings/{id}/photos`

A listing's photos

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`PhotoList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/listings/{id}/photos`

Add a photo to your listing

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: one image, as multipart/form-data in the field `photo` or as the raw image.

Answers: 201 Created (`PhotoDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/listings/{id}/photos/{photo}`

One photo of a listing

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `photo` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`PhotoDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### DELETE `/listings/{id}/photos/{photo}`

Remove a photo from your listing

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `photo` | path | integer | yes |  |
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 204 No content; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 412 Precondition failed; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/listings/{id}/publish`

Publish a listing that waits for payment: with your credits, or by starting an order for the credits it is short of. Needs orders:write too; 404 on a shop

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `ListingPublishInput` as JSON, optional.

Answers: 200 OK (`ListingPublished`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/listings/{id}/related`

Live listings like this one, best first: same category and shared title words, or what a search plugin picks

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `include` | query | string | no | Comma list: custom_fields, translations, rating (the approved ratings: average, count and, on one listing, the count of each star). |
| `limit` | query | integer | no |  |
| `same_category` | query | boolean | no |  |
| `same_country` | query | boolean | no |  |
| `same_region` | query | boolean | no |  |
| `with_photos` | query | boolean | no |  |
| `premium_only` | query | boolean | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ListingPage`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/photos`

Upload a photo for a listing you are about to post; its token lasts two hours. 200, not 201: the token names no resource to read

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: one image, as multipart/form-data in the field `photo` or as the raw image.

Answers: 200 OK (`PhotoTokenDocument`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Locations

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/cities/{id}/areas` | public | `listings:read` | A city's areas |
| GET | `/countries` | public | `listings:read` | Countries |
| GET | `/countries/{code}/regions` | public | `listings:read` | A country's regions |
| GET | `/regions/{id}/cities` | public | `listings:read` | A region's cities |

### GET `/cities/{id}/areas`

A city's areas

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `q` | query | string | no | Names starting with this, in any language. |
| `locale` | query | string | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CityAreaList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/countries`

Countries

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `q` | query | string | no | Names starting with this, in any language. |
| `locale` | query | string | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CountryList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/countries/{code}/regions`

A country's regions

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `code` | path | string | yes |  |
| `q` | query | string | no | Names starting with this, in any language. |
| `locale` | query | string | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`RegionList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/regions/{id}/cities`

A region's cities

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `q` | query | string | no | Names starting with this, in any language. |
| `locale` | query | string | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CityList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Meta

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/openapi.json` | none | - | This API described in OpenAPI 3.1, with the site's plugin endpoints |

### GET `/openapi.json`

This API described in OpenAPI 3.1, with the site's plugin endpoints

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`OpenApiDocument`); 304 Not modified; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Orders

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/account/cart` | user | `cart:read` | Your saved cart: variant ids and quantities, never prices |
| PUT | `/account/cart` | user | `cart:write` | Replace your saved cart; variants that cannot be bought are dropped |
| GET | `/account/orders` | user | `orders:read` | Your orders, newest first |
| POST | `/account/orders` | user | `orders:write` | Place an order for a cart; the server prices it again and answers how to pay |
| GET | `/account/orders/{id}` | user | `orders:read` | One of your orders |
| POST | `/account/orders/{id}/cancel` | user | `orders:write` | Cancel one of your pending bank transfer or cash on delivery orders |
| POST | `/account/orders/{id}/pay` | user | `orders:write` | Start paying one of your pending card orders again |
| GET | `/cart/quote` | public | `listings:read` | Price a cart: lines, shipping, fees and totals, worked out from the database |

### GET `/account/cart`

Your saved cart: variant ids and quantities, never prices

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CartDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### PUT `/account/cart`

Replace your saved cart; variants that cannot be bought are dropped

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-Match` | header | string | no | An ETag from a GET of the same path (any fields, include or locale) or from the last write's answer, or `*`: 412 precondition_failed when the resource has changed since. |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `CartInput` as JSON.

Answers: 204 No content; 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 412 Precondition failed; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/orders`

Your orders, newest first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`OrderPage`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/orders`

Place an order for a cart; the server prices it again and answers how to pay

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Body: `OrderInput` as JSON.

Answers: 201 Created (`OrderPlaced`); 400 Bad request; 401 No valid credential; 403 Not allowed for this credential; 409 Conflict; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/account/orders/{id}`

One of your orders

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`OrderDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/orders/{id}/cancel`

Cancel one of your pending bank transfer or cash on delivery orders

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`OrderDocument`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### POST `/account/orders/{id}/pay`

Start paying one of your pending card orders again

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `Idempotency-Key` | header | string | no | Up to 255 visible ASCII characters. A retry with the same key and body gets the first answer again, with Idempotency-Replayed: true. |

Answers: 200 OK (`OrderPlaced`); 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 409 Conflict; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/cart/quote`

Price a cart: lines, shipping, fees and totals, worked out from the database

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `lines` | query | string | no | variant:qty pairs, comma separated, at most 50. A quantity over 20 is capped with a problem. |
| `shipping` | query | string | no | A shipping method id from shipping_methods; the first one when left out. |
| `gateway` | query | string | no | A payment method id; its fee is added to the totals. |
| `country` | query | string | no | The delivery country's two-letter code, for delivery and tax plugins; anything else is ignored. |
| `state` | query | string | no | The delivery state, up to 100 characters; a longer one is ignored. |
| `city` | query | string | no | The delivery city, up to 100 characters; a longer one is ignored. Cash on delivery limited to chosen cities needs it. |
| `postcode` | query | string | no | The delivery postcode, up to 15 characters; a longer one is ignored. |
| `coupon` | query | string | no | A coupon code: trimmed and upper-cased, 1-40 of A-Z, 0-9 and -. Anything else is ignored, not refused. |
| `locale` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`QuoteDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Pages

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/menus` | public | `listings:read` | The site's menus: main and footer (the top categories and linked pages until the owner builds them), menus the owner made, and any a plugin adds |
| GET | `/pages` | public | `listings:read` | The pages shown as links, in order, in the asked language |
| GET | `/pages/{page}` | public | `listings:read` | One page by id or internal name, linked or not, with its text and meta |
| GET | `/seo` | public | `listings:read` | The head data of a public page: canonical URL, title, description, robots, breadcrumbs, hreflang and Open Graph |

### GET `/menus`

The site's menus: main and footer (the top categories and linked pages until the owner builds them), menus the owner made, and any a plugin adds

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `locale` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`MenuList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/pages`

The pages shown as links, in order, in the asked language

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `locale` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`PageSummaryList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/pages/{page}`

One page by id or internal name, linked or not, with its text and meta

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `page` | path | string | yes |  |
| `locale` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`PageDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/seo`

The head data of a public page: canonical URL, title, description, robots, breadcrumbs, hreflang and Open Graph

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `for` | query | string: `home`, `listing`, `category`, `page`, `search` | no | The kind of page. |
| `id` | query | string | no | A listing id, a category id or slug, or a page id or internal name. Not used for home and search. |
| `q` | query | string | no | Search only: the search words. |
| `locale` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`SeoDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Search

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/search/categories` | public | `listings:read` | Up to three categories for a new listing's title, best first |
| GET | `/search/suggest` | public | `listings:read` | Listings for a search box while someone types, best first; a search plugin may add searches |

### GET `/search/categories`

Up to three categories for a new listing's title, best first

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `title` | query | string | no |  |
| `locale` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CategorySuggestionsDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/search/suggest`

Listings for a search box while someone types, best first; a search plugin may add searches

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `q` | query | string | no | What was typed. Titles are matched by word start; input with no word of three letters or more suggests nothing. |
| `limit` | query | integer | no |  |
| `locale` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`SearchSuggestionsDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Site

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| GET | `/` | public | `listings:read` | The site, its locales and its collections |
| GET | `/currencies` | public | `listings:read` | The currencies listings are priced in |

### GET `/`

The site, its locales and its collections

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`SiteDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/currencies`

The currencies listings are priced in

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`CurrencyList`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

## Users

| Method | Path | Auth | Scope | Summary |
|---|---|---|---|---|
| POST | `/users` | none | - | Sign up, when the site allows it; the activation e-mail goes out as from the form |
| GET | `/users/{id}` | public | `listings:read` | A user's public profile |
| GET | `/users/{id}/listings` | public | `listings:read` | A user's live listings |

### POST `/users`

Sign up, when the site allows it; the activation e-mail goes out as from the form

Body: `Registration` as JSON.

Answers: 201 Created (`NewAccountDocument`); 400 Bad request; 403 Not allowed for this credential; 413 Body too large; 415 Unsupported content type; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/users/{id}`

A user's public profile

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `locale` | query | string | no |  |
| `fields` | query | string | no |  |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`UserDocument`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

### GET `/users/{id}/listings`

A user's live listings

| Parameter | In | Type | Required | Notes |
|---|---|---|---|---|
| `id` | path | integer | yes |  |
| `q` | query | string | no |  |
| `category` | query | string or array | no | One value, a comma list, or repeated. |
| `country` | query | string or array | no | One value, a comma list, or repeated. |
| `region` | query | string or array | no | One value, a comma list, or repeated. |
| `city` | query | string or array | no | One value, a comma list, or repeated. |
| `city_area` | query | string or array | no | One value, a comma list, or repeated. |
| `locale` | query | string | no |  |
| `price_min` | query | integer | no |  |
| `price_max` | query | integer | no |  |
| `with_photos` | query | boolean | no |  |
| `premium` | query | boolean | no |  |
| `in_stock` | query | boolean | no | true: only listings with a variant in stock (stock not tracked, or above 0). |
| `on_sale` | query | boolean | no | true: only listings with a variant whose compare_at is above its price. With in_stock, one variant must be both. |
| `lat` | query | number | no | Measure distances from this point, with lng. Needs distance search switched on. |
| `lng` | query | number | no |  |
| `radius` | query | number | no | Only listings within this many km of lat/lng. |
| `bbox` | query | string | no | west,south,east,north: only listings whose point is inside this box, such as a map's view. West above east crosses the date line. With lat/lng/radius both apply. Needs distance search switched on. |
| `custom_field` | query | object | no | custom_field[<id>]=<value>, or custom_field[<id>][]=A&custom_field[<id>][]=B for a dropdown or radio field: any of up to 20 values. Needs a category filter; each id must be a searchable field of that category, else 422. |
| `option` | query | object | no | option[<name>][]=<value>: only listings with one variant that has, for every name given, one of its values (OR within a name, AND across names). At most 3 names and 10 values each, names up to 30 and values up to 40 characters; anything over is ignored. With in_stock or on_sale, the same variant must meet them too. |
| `ids` | query | string or array | no | One value, a comma list, or repeated. |
| `sort` | query | string: `created`, `id`, `price`, `relevance`, `distance` | no |  |
| `order` | query | string: `asc`, `desc` | no |  |
| `limit` | query | integer | no |  |
| `cursor` | query | string | no |  |
| `page` | query | integer | no | Page number, for numbered pages. Any sort; not with cursor. Past the deepest page: 422 page_too_deep. With count=true, meta.page and meta.pages say where you are. |
| `count` | query | boolean | no | true: also count every match for meta.total; skipped otherwise, as it costs a query. |
| `fields` | query | string | no |  |
| `include` | query | string | no | Comma list: custom_fields, translations, rating (the approved ratings: average, count and, on one listing, the count of each star). |
| `If-None-Match` | header | string | no | An ETag from an earlier answer: 304 with no body while it still matches. |

Answers: 200 OK (`ListingPage`); 304 Not modified; 401 No valid credential; 403 Not allowed for this credential; 404 Not found; 422 Not valid; 429 Too many requests; 500 Server error; 503 Maintenance.

<!-- /generated:api -->
