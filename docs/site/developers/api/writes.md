---
title: API writes
description: "Post, edit and delete listings through the ShopClass REST API, upload photos, comment, save searches, sign users up, retry safely with Idempotency-Key, and know the limits and warnings."
sidebar:
  order: 3
---

Writes act for a signed-in user. Sign in first: [Authentication](/docs/developers/api/authentication/#signing-in-as-a-user).

```bash
API=https://example.com/api/v1
TOKEN=sca_…        # an access token, or a personal key
```

| Endpoint | Scope | Does |
|---|---|---|
| `POST /listings` | `listings:write` | Post a listing. `201`. |
| `PATCH /listings/{id}` | `listings:write` | Edit your listing. |
| `DELETE /listings/{id}` | `listings:delete` | Delete your listing. `204`. |
| `POST /photos` | `listings:write` | Upload a photo before its listing exists. `200` with a token, no `Location`. |
| `POST /listings/{id}/photos` | `listings:write` | Add a photo to your listing. `201`. It counts as an edit (`listing.updated`). |
| `GET /listings/{id}/photos/{photo}` | `listings:read` | One photo. The `Location` of an added photo. |
| `DELETE /listings/{id}/photos/{photo}` | `listings:write` | Remove a photo. `204`. |
| `POST /listings/{id}/comments` | `comments:write` | Comment on a listing. `201`. |
| `GET /comments/{id}` | `listings:read` | One comment: an approved one on a listing you can see, or your own. The `Location` of a new comment. |
| `DELETE /comments/{id}` | `comments:write` | Delete your own approved comment. `204`. |
| `GET /account/alerts` | `alerts:write` | Your saved searches. |
| `POST /account/alerts` | `alerts:write` | Save a search. |
| `GET /account/alerts/{id}` | `alerts:write` | One saved search. The `Location` of a new one. |
| `DELETE /account/alerts/{id}` | `alerts:write` | Stop a saved search. `204`. |
| `POST /users` | none | Sign up, when the site allows it. `201`. |

A write that is not allowed gets `403`. A user who is not the owner of a live listing gets
`403 not_owner`. A listing the user cannot see is a `404`.

## What every write does

- **Bodies are JSON.** Send `Content-Type: application/json`. Bodies are limited to 1 MB; photo
  uploads to 16 MB. An unknown member is a `422`, not ignored.
- **A `201` has a `Location` header** with the new resource's address, and a `GET` of that address works. See [Location targets](#location-targets).
- **The saved resource is in `data`**, in the owner's view.
- **`warnings`** sits next to `data` when something did not go as asked. See [Warnings](#warnings).
- **One transaction.** A listing save that fails part way leaves nothing behind.
- **The same rules as the web forms.** Moderation, the listing limit, spam checks, bans, e-mails
  and plugin hooks all run, because the API calls the same code.
- Write calls also count in the [`api_write` bucket](/docs/developers/api/authentication/#rate-limits).

### Location targets

Every `Location` can be read with `GET`.

| Created by | `Location` |
|---|---|
| `POST /listings` | `/listings/{id}` |
| `POST /listings/{id}/photos` | `/listings/{id}/photos/{photo}` |
| `POST /listings/{id}/comments` | `/comments/{id}` |
| `POST /account/alerts` | `/account/alerts/{id}` |
| `POST /account/keys` | `/account/keys/{id}` |
| `POST /users` | `/users/{id}`, which is a `404` until the account is confirmed |

`POST /photos` answers `200`, not `201`, and has no `Location`. A staged token is not a
resource you can read back.

## Post a listing

```bash
curl -i -X POST $API/listings \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2a80-4d1e-4a53-9c6b-0d9a8f3e51aa" \
  -d '{
    "category_id": 12,
    "title": "Vintage Hardcover Classics Collection",
    "description": "20 books in good condition.",
    "price": "90.00",
    "currency": "USD",
    "country": "US",
    "city": "Austin",
    "contact_phone": "+1 555 0100",
    "show_email": false,
    "fields": { "4": "Blue" },
    "photo_tokens": ["9f2b6c1e0a7d4e3b8c5a1d2e3f405162"]
  }'
```

```text
HTTP/1.1 201 Created
Location: https://example.com/api/v1/listings/412
```

```json
{
  "data": { "id": 412, "status": "pending", "title": "Vintage Hardcover Classics Collection", "…": "…" },
  "warnings": [
    { "code": "listing_pending", "message": "The listing goes live once it is activated or approved." }
  ]
}
```

Required: `category_id`, `title`, `description`.

| Member | Type | Notes |
|---|---|---|
| `category_id` | integer | A category of the site. |
| `title` | string, up to 1000 | In `locale`, or the site's default language. |
| `description` | string, up to 20000 | The same. |
| `locale` | string | The language of `title` and `description`, such as `en_US`. Must be one of the site's languages. |
| `translations` | object | `{"de_DE": {"title": "…", "description": "…"}}` for other languages. |
| `price` | string, number or `null` | A decimal such as `"12.50"`. `null` for no price. |
| `currency` | string | Three capitals, such as `USD`. |
| `country` | string | A two-letter code. Empty clears it. |
| `region_id`, `city_id` | integer or `null` | Ids from `GET /countries/…/regions` and the like. An unknown id is a `422`. |
| `region`, `city` | string | A name, when there is no id. |
| `city_area`, `address`, `zip` | string | |
| `lat`, `lng` | number or `null` | |
| `contact_phone` | string, up to 45 | |
| `show_email` | boolean | Show the seller's e-mail on the listing. |
| `fields` | object | Custom field id to value. See below. |
| `photo_tokens` | array, up to 50 | Tokens from `POST /photos`, added in this order. |
| `photo_urls` | array, up to 20 | Web addresses the site downloads, when the site allows it. |
| `ext` | object | Plugin members, under the plugin's slug. |

Custom `fields` are limited to the listing's category and cleaned as the web form cleans them.
A field that is not in the category is dropped without an error. A date range is
`{"from": "2026-01-01", "to": "2026-01-31"}`. `null` removes a value.

Plugins can change the body before it is saved with the `api_listing_input` filter.

### What can refuse a post

| Answer | Why |
|---|---|
| `422 validation_failed` | A bad member, or the web form's own refusal: a missing field, or the posting wait. `errors` points at the member. |
| `422 listing_limit` | The user has reached the site's listing limit. `detail` says which. |
| `403 forbidden` | The account or address is banned from posting. |
| `429 rate_limited` | Over the [hourly cap](#limits). |

## Edit a listing

`PATCH` sends only what changes. Everything not sent keeps its value.

```bash
curl -X PATCH $API/listings/412 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/merge-patch+json" \
  -d '{ "price": "75.00", "fields": { "4": null } }'
```

Send `application/merge-patch+json` or `application/json`. The answer repeats `Accept-Patch`.

| In the body | Effect |
|---|---|
| A member | Replaces that value. |
| `null` on `price`, `lat`, `lng`, `region_id`, `city_id` | Clears it. |
| `fields: {"4": null}` | Removes that custom field value. |
| `translations` | Merges by locale. Other locales stay. |
| `photo_tokens`, `photo_urls` | **Adds** photos. It never replaces or removes any. |

The answer is the saved listing, with `200`. If the site holds edited listings for review,
the status goes back to `pending` and `warnings` says `listing_pending`.

## Delete a listing

```bash
curl -X DELETE $API/listings/412 -H "Authorization: Bearer $TOKEN"
```

`204`, no body. The listing and its photos are removed as the web delete does it.

## Photos

A photo is checked as the listing form checks it: a type the site accepts (JPEG, PNG, GIF or
WebP), an image that decodes, within the pixel limit and the site's largest photo size.

### Upload first, then attach

```bash
curl -X POST $API/photos \
  -H "Authorization: Bearer $TOKEN" \
  -F photo=@books.jpg
```

```json
{ "data": { "token": "9f2b6c1e0a7d4e3b8c5a1d2e3f405162", "expires_at": "2026-10-03T11:12:00Z" } }
```

Send the file one of two ways:

| Way | How |
|---|---|
| Multipart | `multipart/form-data`, the file in a field named `photo`. |
| Raw | The image itself as the body, with `Content-Type: image/jpeg` (or `image/png`, `image/gif`, `image/webp`). |

```bash
curl -X POST $API/photos -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: image/jpeg" --data-binary @books.jpg
```

| Rule | Detail |
|---|---|
| Token life | Two hours. The cron removes older photos. |
| Whose | A token works only for the user who uploaded it. An unknown, expired or foreign token is a `422` on `/photo_tokens/<n>`. |
| Waiting | A user may hold 50 staged photos at once. The 51st is a `422`. |
| Size | The site's largest photo size, and never over 16 MB. Bigger is `413 too_large`. |
| Wrong type | `415 unsupported_media_type` when the request is neither multipart nor an image. A file that is not an allowed image, or has too many pixels, is `422`. |

The token is used once. After the listing saves, it is gone. If the save fails, it stays
valid until it expires.

### Add to a listing you already have

```bash
curl -X POST $API/listings/412/photos -H "Authorization: Bearer $TOKEN" -F photo=@back.jpg
```

`201` with the photo (`id`, `thumbnail`, `preview`, `normal`, `original`) and a `Location`
header. If the listing already holds as many photos as the site allows, it is a `422` on
`/photo`. If the site holds edited listings for review, adding a photo sends the listing back
to review.

```bash
curl -X DELETE $API/listings/412/photos/881 -H "Authorization: Bearer $TOKEN"
```

`204`. A photo id that is not on this listing is a `404`.

### How many photos a listing holds

The site sets a maximum number of photos per listing. When `photo_tokens` or `photo_urls` name more than the
listing has room for, the listing still saves with the first ones, and `warnings` carries
`photo_skipped` with the count.

### Photos by address

Off by default. The site owner switches it on with **Let new listings name photos by web
address**. With it off, `photo_urls` is a `422` on `/photo_urls`: upload to `/photos` instead.

```json
{ "category_id": 12, "title": "…", "description": "…", "photo_urls": ["https://cdn.example.com/books.jpg"] }
```

The site downloads each address itself:

- Only public `http` and `https` addresses on their usual ports. Private and local addresses are refused.
- Redirects are not followed. A download stops after 15 seconds and at the site's largest photo size.
- One bad address fails the whole request with a `422` on `/photo_urls/<n>`. Nothing is saved.
- Each download counts toward 30 an hour per user on `PATCH`. See [Limits](#limits).

## Comments

```bash
curl -i -X POST $API/listings/412/comments \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "title": "Still available?", "body": "Can you ship to Canada?" }'
```

`201` with the comment and `Location: …/comments/9`.

```json
{
  "data": {
    "id": 9, "listing_id": 412, "title": "Still available?", "body": "Can you ship to Canada?",
    "author": { "name": "Jane Doe", "user_id": 23 }, "published_at": "2026-10-03T09:30:00Z"
  },
  "warnings": [ { "code": "comment_pending", "message": "The comment shows once it is approved." } ]
}
```

| Rule | Detail |
|---|---|
| `body` | Required, 1 to 5000 characters. A body that is only tags or spaces is a `422`. |
| `title` | Optional, up to 200. |
| Author | Taken from the account. You cannot set a name or e-mail. |
| Moderation | If the site approves comments first, or the spam check holds one, you get `comment_pending`. |
| Refusals | `403` when comments are off, or the account is banned. `404` when the listing is pending, disabled or spam. |
| Delete | `DELETE /comments/{id}` removes your own approved comment. Someone else's is `403`; one still waiting for approval is `409`. |

## Saved searches

A saved search mails the user new matches. It takes the filters of `GET /listings`.

```bash
curl -i -X POST $API/account/alerts \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "filters": { "q": "bicycle", "category": "for-sale", "price_max": 300, "with_photos": true } }'
```

```json
{
  "data": {
    "id": 5,
    "filters": { "q": "bicycle", "category": [1], "price_max": 300, "with_photos": true },
    "type": "daily",
    "active": true,
    "created_at": "2026-10-03T09:40:00Z"
  }
}
```

| Rule | Detail |
|---|---|
| Filters | `q`, `category`, `country`, `region`, `city`, `city_area`, `user`, `locale`, `price_min`, `price_max`, `with_photos`, `premium`, `field`. At least one. |
| Same search again | Answers `200` with the saved one, not a second `201`. |
| `type` | How often it mails: `instant`, `hourly`, `daily` or `weekly`. |
| List and stop | `GET /account/alerts`, `DELETE /account/alerts/{id}` (`204`). |
| Shown on the web | The same search appears on the account's alerts page. |

## Sign up

Off by default. The site owner switches on **Allow sign-up through the API**, and the site must
have registration on.

```bash
curl -i -X POST $API/users -H "Content-Type: application/json" \
  -d '{ "name": "Jane Doe", "email": "jane@example.com", "password": "…" }'
```

```json
{ "data": { "id": 31, "confirmed": false } }
```

`confirmed` is `false` until the link in the activation e-mail is opened. The user cannot sign in
before that. Optional: `username`, `phone_land`, `phone_mobile`. A site that is off answers
`403 forbidden`.

There is no captcha to show, so sign-up is limited: **5 per address and 100 for the whole
site an hour**. If the site cannot count, it refuses.

## Retrying safely with Idempotency-Key

A network drop after a `POST` leaves you not knowing if it worked. Send an `Idempotency-Key`
and retry the same call: the server runs it once.

```bash
curl -X POST $API/listings -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: 6f1c2a80-4d1e-4a53-9c6b-0d9a8f3e51aa" … # same call, any number of times
```

| Rule | Detail |
|---|---|
| Where | `POST`, `PUT`, `PATCH`, `DELETE` with any credential. Anonymous calls ignore it. |
| Key | 1 to 255 visible ASCII characters. A random UUID is fine. Otherwise `400 invalid_header`. |
| Kept | 24 hours. Keys belong to one sign-in (across its refreshed tokens) or one key. Another user cannot use yours. |
| Replay | The same status, headers and body come back, plus `Idempotency-Replayed: true`. |
| Different request | The same key with another method, path, query or body is `422 idempotency_key_reused`. |
| Still running | The same key while the first call runs is `409 idempotency_in_flight`, with `Retry-After: 1`. |
| Errors | A `5xx` or `429` is not kept: retry runs again. Any other `4xx` is kept and replays. Fix the request and use a **new** key. |
| Not covered | `POST /auth/token`, `POST /account/password` and `POST /account/keys`. They carry secrets, so they never replay. |

## Limits

| What | Limit | Over it |
|---|---|---|
| Requests | [The usual limits](/docs/developers/api/authentication/#rate-limits), and 30 writes a minute | `429 rate_limited` |
| New listings | Per user an hour: the site's setting, or 30 when it is `0` (10 while the listing form asks for a captcha). Per address: three times that. | `429 rate_limited` |
| Comments | 20 an hour per user, counted with the ones they post on the site's comment form | `429 rate_limited` |
| Photos fetched by address | 30 an hour per user, counted on `PATCH` | `429 rate_limited` |
| E-mail changes on the account | 5 an hour per user | `429 rate_limited` |
| Staged photos waiting | 50 per user | `422` on `/photo` |
| `photo_tokens` / `photo_urls` in one body | 50 / 20 | `422 validation_failed` |
| Sign-ups | 5 an hour per address, 100 for the site | `429 rate_limited` |

Each `429` has `Retry-After`. The site owner sets the listing number: [Set up the REST API](/docs/configure/api/#rate-limits).

## Account

| Endpoint | Scope | Does |
|---|---|---|
| `GET /account` | `account:read` | Your profile, in the owner's view. |
| `PATCH /account` | `account:write` | Edit the profile: `name`, `website`, `phone_land`, `phone_mobile`, `country`, `region_id`, `city_id`, `city_area`, `address`, `zip`, `lat`, `lng`, `is_company`, `email`. |
| `GET /account/listings` | `account:read` | Your listings in any status, newest first, as your listings page shows them. Filters: `status` (`active`, `pending`, `disabled`, `expired`, `spam`), `limit`, `cursor`, `count`. |
| `POST /account/password` | `account:write` | Change the password. |
| `GET /account/sessions` | `account:read` | Sign-ins and keys that act for you. |
| `DELETE /account/sessions/{id}` | `account:write` | End one sign-in, or revoke one key (`key-<id>`). `204`. |
| `GET`, `POST`, `DELETE /account/keys` | `account:write` | [Personal keys](/docs/developers/api/authentication/#personal-keys). |

A new `email` is not applied at once. A confirmation link goes to the new address, and the
answer carries the warning `email_confirmation_sent`. The e-mail changes when the link is opened.

## Warnings

`warnings` is a list of `{ "code", "message" }`. A warning is not an error: the write worked.

| Code | On | Meaning |
|---|---|---|
| `listing_pending` | `POST`, `PATCH /listings` | The listing is not live yet. It needs approval or activation. |
| `photo_skipped` | `POST`, `PATCH /listings` | Some photos were not added: the listing holds as many as it may. |
| `comment_pending` | `POST …/comments` | The comment shows once it is approved. |
| `email_confirmation_sent` | `PATCH /account` | A link went to the new address. |

Errors are in [API errors](/docs/developers/api/errors/).
