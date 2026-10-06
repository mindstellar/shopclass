---
title: REST API
description: "Read a ShopClass site as JSON, post listings, photos and comments as a signed-in user, and run the site with an admin key: quick start with curl, response shape, paging, caching and versioning."
sidebar:
  order: 1
---

The REST API gives another program access to a ShopClass site. With a key it reads listings,
categories, custom fields, locations, currencies and public user profiles. As a signed-in
user it also posts, edits and deletes listings, uploads photos, comments and saves searches.
With an admin key it moderates and manages the site, and signed webhooks tell your server
when something changes. It speaks JSON over HTTPS.

| | |
|---|---|
| Base URL | `https://example.com/api/v1` |
| Without friendly URLs | `https://example.com/index.php?page=api&path=v1/…` |
| Auth | `Authorization: Bearer <key or token>`, see [Authentication](/docs/developers/api/authentication/) |
| Errors | `application/problem+json`, see [Errors](/docs/developers/api/errors/) |
| Every endpoint | [Reference](/docs/developers/api/reference/), or the site's own `/api/v1/openapi.json` |
| Client code | `openapi.json` is OpenAPI 3.1: use a generator that reads 3.1, such as openapi-generator 7 or later. Webhooks are described there but not generated. |

The site owner turns the API on and makes your key:
[Set up the REST API](/docs/configure/api/). The API is **off by default**, and once on it needs a
key unless the owner allowed anonymous reads. Writing needs a user account, not a public key.

## Quick start

Set two variables. A public key (`scp_…`) is enough for everything below.

```bash
API=https://example.com/api/v1
KEY=scp_YOUR_KEY
```

### The site

```bash
curl -H "Authorization: Bearer $KEY" $API/
```

```json
{
  "data": {
    "name": "Shopclass Dev",
    "description": "Shopclass is a free classified site.",
    "url": "https://example.com/",
    "version": "%SHOPCLASS_VERSION%",
    "api_version": "v1",
    "default_locale": "en_US",
    "locales": [{ "code": "en_US", "name": "English (US)", "direction": "ltr" }],
    "currency": "USD",
    "timezone": "Europe/Madrid",
    "friendly_urls": true,
    "features": { "users": true, "registration": true, "comments": true, "public_reads": false },
    "api": { "registration": false, "personal_keys": true, "photo_urls": false, "public_reads": false },
    "links": {
      "listings": "https://example.com/api/v1/listings",
      "categories": "https://example.com/api/v1/categories",
      "countries": "https://example.com/api/v1/countries",
      "currencies": "https://example.com/api/v1/currencies",
      "fields": "https://example.com/api/v1/fields",
      "openapi": "https://example.com/api/v1/openapi.json"
    }
  }
}
```

The `api` member tells an app what the site allows, so it can hide a button before the
call fails:

| Member | Meaning |
|---|---|
| `registration` | `POST /users` works: the site allows sign-up through the API and has registration on. |
| `personal_keys` | Users may make personal keys. |
| `photo_urls` | A new listing may name photos by web address (`photo_urls`). |
| `public_reads` | Reads work without a key. |

### Categories

```bash
curl -H "Authorization: Bearer $KEY" "$API/categories?tree=1&fields=id,slug,name,children"
```

Flat by default, in display order, each with `parent_id`. `tree=1` nests them under `children`.
`GET /categories/{id-or-slug}` returns one category with its custom fields.

### Search listings

```bash
curl -H "Authorization: Bearer $KEY" \
  "$API/listings?category=for-sale&price_max=500&with_photos=1&sort=price&order=asc&limit=1&fields=id,title,price,category,seller"
```

```json
{
  "data": [
    {
      "id": 159,
      "title": "Vintage Hardcover Classics Collection (20 Books)",
      "category": {
        "id": 12, "slug": "books-magazines", "name": "Books - Magazines",
        "path": [{ "id": 1, "slug": "for-sale", "name": "For sale" }]
      },
      "price": { "amount": "90.00", "currency": "USD", "formatted": "$ 90" },
      "seller": { "id": 23, "name": "Michael Reed", "username": "michaelreed", "url": "https://example.com/user/profile/michaelreed" }
    }
  ],
  "meta": { "total": null, "limit": 1 },
  "links": {
    "self": "https://example.com/api/v1/listings?category=for-sale&…",
    "next": "https://example.com/api/v1/listings?category=for-sale&…&cursor=eyJ2…"
  }
}
```

Filters for `GET /listings`:

| Parameter | Meaning |
|---|---|
| `q` | Search words (up to 200 characters). With `q`, the default sort is `relevance`. |
| `category` | Category ids or slugs. Subcategories are included. An unknown one is a `422`. |
| `country` | A two-letter code (`DE`) or a name. |
| `region`, `city`, `city_area` | An id or a name. |
| `user` | Seller ids. |
| `locale` | Search and return text in this locale, such as `en_US`. |
| `price_min`, `price_max` | Whole units of the site's currency. |
| `with_photos`, `premium` | `1` or `true` to keep only those. |
| `field[<id>]` | A custom field value, such as `field[4]=Blue`. Ids come from `GET /fields`. |
| `sort` | `created` (default), `id`, `price` or `relevance`. |
| `order` | `desc` (default) or `asc`. |
| `limit` | Page size, 1 up to the site's maximum results per page. Above that is a `422`. |
| `cursor` | Where the last page ended. Take it from `links.next`. |
| `count` | `true` to fill `meta.total`; otherwise it is `null`. |

A parameter the endpoint does not know is a `422`, so a typo does not silently return everything.
| `fields` | Keep only these members: `fields=id,title,price`. `id` is always kept. |
| `include` | Switch on costly members: `fields` (custom field values) and `translations`. |

Category, place and user filters take one value, a comma list (`city=1187,3218`) or the
parameter repeated.

### One listing

```bash
curl -H "Authorization: Bearer $KEY" "$API/listings/159?include=fields,translations"
```

Related: `GET /listings/{id}/photos`, `GET /listings/{id}/photos/{photo}`,
`GET /listings/{id}/comments` (approved comments, oldest first, when comments are on),
`GET /comments/{id}` and `GET /users/{id}/listings`.
A pending, disabled or spam listing is a `404` except to its owner and keys with the admin view.
An expired listing answers with `status: "expired"`, as its page shows it; search leaves it out.

### Paging

Follow `links.next` until it is `null`. Do not build cursors yourself; each one works for a day.

```bash
URL="$API/listings?limit=50"
while [ "$URL" != "null" ]; do
  PAGE=$(curl -s -H "Authorization: Bearer $KEY" "$URL")
  echo "$PAGE" | jq -r '.data[].title'
  URL=$(echo "$PAGE" | jq -r '.links.next')
done
```

- A cursor is signed and only works with the filters, sort and order it was made for. Any
  other use is a `400` `invalid_cursor`.
- `meta.total` is `null` unless you send `count=true`, which counts all matches on the first
  page (it costs a query). Later pages of a `created` or `id` sort leave it `null`.
- A cursor is good for a day and its expiry is rounded up to the hour, so a page's
  `links.next` and `ETag` stay the same within the hour.
- `limit` defaults to the site's results per page. Locations default to 500, max 1000,
  and send `total: null`.
- Only `created` and `id` page by position. `price` and `relevance` page by offset and stop
  after 10,000 results.

### Saving bandwidth with ETags

Every successful `GET` carries an `ETag`. Send it back in `If-None-Match`. If nothing
changed you get `304 Not Modified` and no body.

```bash
curl -i -H "Authorization: Bearer $KEY" $API/
# ETag: "9a04037fc4f6b5e68d56cca62a681b9b"

curl -i -H "Authorization: Bearer $KEY" -H 'If-None-Match: "9a04037fc4f6b5e68d56cca62a681b9b"' $API/
# HTTP/1.1 304 Not Modified
```

Answers for public keys and anonymous calls also carry
`Cache-Control: public, max-age=60, stale-while-revalidate=60`, so a CDN may keep them.
The site owner sets the number of seconds. Answers for admin keys are never shared-cached.

To avoid overwriting a change made meanwhile, send the `ETag` of your last `GET` in
`If-Match` on a `PATCH` or `DELETE`. If the resource changed you get `412 precondition_failed`.
`If-Match: *` only checks that the resource exists.

On listings, comments, photos, the account, users, keys, saved searches, categories, fields,
currencies and locations, the `ETag` holds the stored version (`"<version>.<hash>"`). Any `GET`
of the path works for `If-Match`, whatever its `fields`, `include` or `locale`. The check and
the write run as one, so no other write can land between them. A `PATCH` sent with `If-Match`
answers with the new `ETag`, ready for the next edit. On other paths, use the tag of the plain
`GET` (no `fields` or `include`).

## Quick start: writing

Writes act for a user. This walkthrough signs in, uploads a photo, posts a listing, edits it
and deletes it. `jq` picks values out of the answers.

```bash
API=https://example.com/api/v1
```

### 1. Sign in

```bash
TOKEN=$(curl -s -X POST $API/auth/token -H "Content-Type: application/json" \
  -d '{"grant_type":"password","username":"jane@example.com","password":"…","label":"curl"}' \
  | jq -r '.access_token')
```

The answer also has a `refresh_token`. The access token lives 15 minutes. When calls answer
`401 token_expired`, swap the refresh token for new ones:
[Authentication](/docs/developers/api/authentication/#refresh-tokens).

### 2. Upload a photo

```bash
PHOTO=$(curl -s -X POST $API/photos -H "Authorization: Bearer $TOKEN" -F photo=@books.jpg \
  | jq -r '.data.token')
```

The answer is `200`, with no `Location`. The token lasts two hours and works for this user only.

### 3. Post the listing

```bash
curl -i -X POST $API/listings \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d "{\"category_id\":12,\"title\":\"Vintage books\",\"description\":\"20 hardcovers.\",
       \"price\":\"90.00\",\"currency\":\"USD\",\"photo_tokens\":[\"$PHOTO\"]}"
```

```text
HTTP/1.1 201 Created
Location: https://example.com/api/v1/listings/412
```

The body is `{"data": {…the listing…}}`. If the site checks new listings first, `data.status` is
`pending` and a `warnings` list says `listing_pending`.

### 4. Edit it

Send only what changes, as a JSON merge patch.

```bash
curl -X PATCH $API/listings/412 \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/merge-patch+json" \
  -d '{"price":"75.00"}'
```

### 5. Delete it

```bash
curl -i -X DELETE $API/listings/412 -H "Authorization: Bearer $TOKEN"   # 204 No Content
```

### Retrying a write

Send an `Idempotency-Key` on a `POST`. If the connection drops, send the **same** call with
the **same** key: the server runs it once and replays the first answer, marked
`Idempotency-Replayed: true`. Details: [Writes](/docs/developers/api/writes/#retrying-safely-with-idempotency-key).

More: comments, saved searches, photo uploads, limits and warnings are in
[Writes](/docs/developers/api/writes/).

## Response shape

One thing:

```json
{ "data": { "id": 27, "name": "James Foster" } }
```

A list:

```json
{
  "data": [ ],
  "meta": { "total": null, "limit": 12 },
  "links": { "self": "https://…", "next": null }
}
```

Rules that hold everywhere:

| Rule | Detail |
|---|---|
| Names | `snake_case`. |
| Ids | Integers. Countries and currencies use their code, such as `"DE"`, `"EUR"`. |
| Times | RFC 3339 in UTC: `2026-07-05T01:47:36Z`. |
| Money | `{"amount": "90.00", "currency": "USD", "formatted": "$ 90"}`. The amount is a decimal string. `price` is `null` when the listing or its category has no price. |
| Missing values | `null`, never `""`. |
| URLs | Absolute. |
| Booleans | `true` and `false`. |
| Unknown members | Ignore them. New ones can appear in v1. |

A listing has: `id`, `url`, `status`, `title`, `description`, `locale`, `category`
(`id`, `slug`, `name`, `path`), `price`, `location` (`country`, `region`, `city`,
`city_area`, `address`, `zip`, `lat`, `lng`), `contact` (`name`, `email`, `phone`), `seller`
(`id`, `name`, `username`, `url`) or `null`, `photos` (`id`, `thumbnail`, `preview`,
`normal`, `original`), `premium`, `views`, `published_at`, `updated_at`, `expires_at`.
`fields` and `translations` appear with `include=`. `original` is `null` unless the site keeps
original photos.

Who sees which members is in [Authentication](/docs/developers/api/authentication/#views).
Plugins can add data under an `ext` member: [Plugin endpoints](/docs/developers/api/plugin-endpoints/).

The API does not count views.

## Versioning

The version is in the path: `/api/v1`. Inside v1 changes only add things: new members, new
endpoints, new optional parameters. Removing or retyping a member, or changing a status
or error `code`, would be a new version. How old endpoints are retired is in the
[API changelog](/docs/developers/api/changelog/).

## Next

- [Authentication](/docs/developers/api/authentication/): keys, sign-in, scopes, limits
- [Writes](/docs/developers/api/writes/): listings, photos, comments, saved searches
- [Admin endpoints](/docs/developers/api/admin/): moderate and manage a site with an admin key
- [Webhooks](/docs/developers/api/webhooks/): get a signed POST when something changes
- [Errors](/docs/developers/api/errors/)
- [Reference](/docs/developers/api/reference/)
- [Plugin endpoints](/docs/developers/api/plugin-endpoints/): add your own
