---
title: API admin endpoints
description: "Run a ShopClass site from a script: moderate listings and comments, manage users, categories, currencies, custom fields, locations, settings, API keys and webhooks, with the admin key scopes, moderator keys and curl examples."
sidebar:
  order: 4
---

The admin endpoints do what the admin screens do, with an **admin key**. They live under
`/api/v1/admin/` and use the same code as the screens, so the same checks, e-mails, caches
and plugin hooks run.

```bash
API=https://example.com/api/v1
KEY=sck_YOUR_ADMIN_KEY
```

The site owner makes the key and picks its scopes:
[Set up the REST API](/docs/configure/api/#keys).
Public keys, personal keys and access tokens get `403` here.

## Scopes

Each endpoint needs one scope. A key holds only the scopes it was made with.

| Scope | Endpoints |
|---|---|
| `admin:listings` | `/admin/listings` |
| `admin:comments` | `/admin/comments` |
| `admin:users` | `/admin/users` |
| `admin:taxonomy` | `/admin/categories`, `/admin/currencies`, `/admin/custom-fields`, `/admin/regions`, `/admin/cities`, `/admin/areas` |
| `admin:settings` | `/admin/settings`, `/admin/jobs` |
| `admin:keys` | `/admin/keys` |
| `admin:webhooks` | `/admin/webhooks`, `/admin/webhook-events` |

A missing scope is `403 insufficient_scope`. Any `admin:*` scope also lets the key read
public data.

### Moderator keys

A key made for a moderator account can hold only `admin:listings` and `admin:comments`, and
only while moderators may open those pages in the admin. Make one with
`php oc-cli.php api:key:create --admin=<moderator> --scopes=admin:listings,admin:comments`.
The settings screen is for full admins.

### Who is acting

On an admin endpoint the request runs as the admin who owns the key. The activity log
records that admin. Listing actions are logged as `API key #<id>`.

On every other endpoint an admin key runs as nobody, so a public call never picks up an
admin's rights.

## Rules for every endpoint

- Answers and errors are as in the rest of the API: `{"data": …}`, lists with `meta` and
  `links`, errors as [problem documents](/docs/developers/api/errors/).
- Writes take JSON. `PATCH` is a merge patch: send only what changes. An unknown member is a
  `422`.
- Creates answer `201` with a `Location`. Deletes answer `204`.
- `Idempotency-Key` works on writes. It is ignored on the four that hand out a secret: make a
  key, rotate a key, add a webhook endpoint, rotate its secret.
- Writes count in the key's write rate limit.
- Text is cleaned as the admin screens clean it.

## Listings

`admin:listings`. These show every listing, whatever its status, in the admin view.

| Endpoint | Does |
|---|---|
| `GET /admin/listings` | Search. Filters: `status` (`active`, `pending`, `disabled`, `expired`, `spam`; a comma list or repeated), `user` (ids) and `category` (ids or slugs, subcategories included), each a comma list or repeated as in search, `q` (title contains), `include`, `locale`, `fields`, `limit`, `cursor`, `count`. |
| `GET /admin/listings/{id}` | One listing. |
| `PATCH /admin/listings/{id}` | Edit any member of a listing, plus `owner_id` (`null` for none), `contact_name`, `contact_email` and `expires_at` (`YYYY-MM-DD`, `null` for never). `approved` approves a listing that waits for moderation, or sends it back. `blocked`, `spam` and `premium` (no end date) set or clear each one. |
| `DELETE /admin/listings/{id}` | Delete. |
| `POST /admin/listings/{id}/bump` | Move to the top of "newest first". |

The admin view reads `approved`, `blocked`, `spam` and `premium` back under the same names;
`status` sums them up. A status change answers `409` when the listing refuses it, such as
approving a blocked one; send `blocked: false` in the same call. The e-mails the screens send
go out after the change is saved.

```bash
curl -X PATCH $API/admin/listings/412 -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" -d '{"approved": true}'

curl "$API/admin/listings?status=pending&limit=50" -H "Authorization: Bearer $KEY"
```

## Comments

`admin:comments`.

| Endpoint | Does |
|---|---|
| `GET /admin/comments` | List. Filters: `status` (`active`, `pending`, `disabled`, `spam`), `listing`, `user`, `limit`, `cursor`, `count`. |
| `GET /admin/comments/{id}` | One comment, with the author's e-mail, `approved` and `blocked`. |
| `PATCH /admin/comments/{id}` | Edit `title`, `body`, `author_name`, `author_email`. `approved: true` approves it and tells the author; `false` holds it back. `blocked: true` blocks it; `false` unblocks it. |
| `DELETE /admin/comments/{id}` | Delete. |

## Users

`admin:users`.

| Endpoint | Does |
|---|---|
| `GET /admin/users` | List. Filters: `q` (e-mail, username or name starting with it), `confirmed`, `blocked`, `locale`, `fields`, `limit`, `cursor`, `count`. |
| `GET /admin/users/{id}` | One user, every member. |
| `PATCH /admin/users/{id}` | Edit the profile, `email`, `username` or `password`. A new password ends every sign-in and key of that user. `confirmed` marks the account confirmed, or not. `blocked: true` blocks the user, and their sign-ins and keys stop working. Both read back under the same names. |
| `DELETE /admin/users/{id}` | Delete the user with their listings, comments and saved searches. |
| `GET /admin/users/{id}/sessions` | The user's sign-ins and API keys. |
| `DELETE /admin/users/{id}/sessions/{session}` | End one sign-in, or revoke one key. |

```bash
curl -X PATCH $API/admin/users/23 -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" -d '{"blocked": true}'
curl -X DELETE $API/admin/users/23/sessions/9 -H "Authorization: Bearer $KEY"
```

## Categories, currencies, fields and locations

`admin:taxonomy`.

| Endpoint | Does |
|---|---|
| `GET /admin/categories`, `GET /admin/categories/{id}` | Every category, enabled or not, with each language's `name`, `slug` and `description` under `translations`. |
| `POST /admin/categories` | Add one. Needs `translations`. The slug is made from the name. Optional: `parent_id`, `expiration_days`, `price_enabled`, `enabled`. |
| `PATCH /admin/categories/{id}` | Edit. A new slug keeps the old one redirecting. `apply_to_subcategories` copies the expiry and price setting down. `enabled: false` on a top category takes its subcategories and their listings with it. |
| `DELETE /admin/categories/{id}` | Delete with its subcategories and their listings. A large one answers `202`: it is hidden now and emptied in the background. |
| `GET`, `PATCH`, `DELETE /admin/currencies/{code}`, `POST /admin/currencies` | Add, rename or change the symbol of, or delete a currency. A currency a listing uses, or the site default, cannot be deleted (`409`). |
| `GET`, `PATCH`, `DELETE /admin/custom-fields/{id}`, `POST /admin/custom-fields` | Custom fields: `name`, `type`, `slug`, `required`, `searchable`, `options`, `categories`. Deleting a field deletes its values. |
| `GET`, `PATCH`, `DELETE /admin/regions/{id}`, `POST /admin/regions` | Regions. `POST` takes `country` and `name`. |
| `GET`, `PATCH`, `DELETE /admin/cities/{id}`, `POST /admin/cities` | Cities. `POST` takes `region_id` and `name`. |
| `GET`, `PATCH`, `DELETE /admin/areas/{id}`, `POST /admin/areas` | City areas. `POST` takes `city_id` and `name`. |

Deleting a region, city or area deletes the places under it and the listings in them.

Each `POST` answers with a `Location` for the new item. Lists of categories, fields, countries and currencies for apps stay on the public routes.

```bash
curl -X POST $API/admin/categories -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"parent_id": 1, "translations": {"en_US": {"name": "Bikes"}}}'
```

## Settings and jobs

`admin:settings`.

| Endpoint | Does |
|---|---|
| `GET /admin/settings` | The settings the API can change. |
| `PATCH /admin/settings` | Change some of them. All are saved, or none. Answers with the new values. |
| `GET /admin/jobs` | The [background job](/docs/developers/jobs/) queue: `counts` per status, and `dead_letters`, the newest jobs that gave up (`limit`, up to 200; 50 by default). |

A change goes through the settings screen's own form, so its checks and save hooks run. A
refusal is a `422` with the screen's message.

The settings you can read and change:

| Group | Members |
|---|---|
| Site | `site_title`, `site_description`, `contact_email`, `language`, `currency`, `timezone`, `week_start`, `date_format`, `time_format`, `rss_items`, `latest_listings_at_home`, `results_per_page`, `selectable_parent_categories`, `contact_attachments` |
| Comments | `comments_enabled`, `comments_need_account`, `comments_captcha`, `comments_per_page`, `comments_notify_admin`, `comments_notify_seller` |
| API | `api_public_reads`, `api_cors_origins`, `api_rate_limit_default`, `api_rate_limit_anon`, `api_rate_limit_write`, `api_listing_rate`, `api_cache_max_age`, `api_hide_phone`, `api_photo_urls`, `api_user_keys`, `api_registration` |

Never exposed: keys, passwords, webhook secrets, the switch that turns the API off, and
the private-network switch for webhooks. Those stay on the screen.

```bash
curl -X PATCH $API/admin/settings -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/merge-patch+json" \
  -d '{"comments_enabled": true, "results_per_page": 24}'
```

## Keys

`admin:keys`. The same keys as **Settings → API**.

| Endpoint | Does |
|---|---|
| `GET /admin/keys`, `GET /admin/keys/{id}` | Keys, newest first. Never the secret. `kind` is `admin`, `public` or `user`. |
| `POST /admin/keys` | Make a key. Body: `name`, optional `kind` (`admin` by default, or `public`), `scopes`, `expires_at` (`2027-03-01`, or `90d`). A key that expires cannot make a key that outlives it: with no `expires_at`, the new key gets the same expiry. The answer has the `token`, once. |
| `POST /admin/keys/{id}/rotate` | Make a new key with the same kind, scopes and expiry, but never past the calling key's expiry. The old one keeps working until you revoke it. Only for your own keys and public keys. |
| `DELETE /admin/keys/{id}` | Revoke. |

A key made here belongs to the admin who owns the calling key. It cannot hold a scope the
calling key lacks: `403` with the scopes named.

Unlike the screen, these calls do not ask for a password. The key is the proof.

```bash
curl -X POST $API/admin/keys -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"name": "Stock sync", "scopes": ["admin:listings"], "expires_at": "90d"}'
```

## Webhooks

`admin:webhooks`. Endpoints are the same as on **Settings → API → Webhooks**. What the site
sends is in [Webhooks](/docs/developers/api/webhooks/).

| Endpoint | Does |
|---|---|
| `GET /admin/webhook-events` | Every event an endpoint can subscribe to, plugin events too: `type`, `description`, `schema`. |
| `GET /admin/webhooks`, `GET /admin/webhooks/{id}` | Endpoints, newest first. Never the secret. |
| `POST /admin/webhooks` | Add one. Body: `url`, `events` (at least one), optional `description` and `enabled` (`true` by default). The answer has the `secret`, once. |
| `PATCH /admin/webhooks/{id}` | Change `url`, `events`, `description` or `enabled`. `enabled: true` clears a pause and the failure count. |
| `DELETE /admin/webhooks/{id}` | Delete, and drop the deliveries still waiting. |
| `POST /admin/webhooks/{id}/rotate-secret` | A new `secret`, shown once. The old one also signs for 24 hours. |
| `POST /admin/webhooks/{id}/test` | Queue a `ping`. `202` with its `message_id`. |
| `GET /admin/webhooks/{id}/deliveries` | The endpoint and its deliveries still on the job queue, newest first (`limit`, up to 100). Delivered ones leave the queue. |

An endpoint has: `id` (`ep_…`), `url`, `description`, `events`, `enabled`, `status`
(`active`, `paused` or `disabled`), `failures`, `last_status`, `last_attempt_at`,
`last_success_at`, `last_failure_at`, `paused_at`, `paused_reason`, `previous_secret_until`,
`created_by`, `created_at`, `updated_at`. `last_status` is `HTTP <code>`, `Timed out`,
`Could not connect` or why the address was refused; it never quotes the network error.

A delivery has: `job_id`, `message_id`, `type`, `status` (`pending`, `running` or `error`, which
means it gave up), `attempts`, `last_error`, `test`, `created_at`, `next_run_at`.

A bad address, an unknown event or a site with 50 endpoints is a `422`.

```bash
curl -X POST $API/admin/webhooks -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://hooks.example.com/shopclass", "events": ["listing.created", "listing.deleted"]}'
```

```json
{
  "data": {
    "id": "ep_3f9c1a07b2d84e55",
    "url": "https://hooks.example.com/shopclass",
    "events": ["listing.created", "listing.deleted"],
    "enabled": true,
    "status": "active",
    "failures": 0,
    "secret": "whsec_Kq2c1n0b8mJf3VtY7wQz5uXr9sLd4HaE"
  }
}
```

Keep the `secret`. The site does not show it again.
