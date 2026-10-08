---
title: Set up the REST API
description: "Turn the ShopClass REST API on or off, make and revoke API keys, let users make personal keys and sign up, send webhooks to other systems, allow photos by web address, allow browser apps with CORS, set rate limits, and fix a missing Authorization header."
sidebar:
  order: 8
---

The REST API lets apps, scripts and other sites read your listings, categories and
locations as JSON. Signed-in users can also post listings, upload photos, comment and save
searches through it. Webhooks tell other systems when something changes. It is **off by default**:
switch it on with **Turn on the REST API**. Every call needs a key or a user's token.

All of it is on one screen: **Admin → Settings → API**. Only full admins can open it.

## Where the API lives

| Friendly URLs | Address |
|---|---|
| On | `https://example.com/api/v1/listings` |
| Off | `https://example.com/index.php?page=api&path=v1/listings` |

The screen shows your site's own address at the top. Developers read the same
address from the `friendly_urls` and `links` fields of `GET /api/v1/`. The `api` field of the
same call tells an app which of the switches below are on: `registration`, `personal_keys`,
`photo_urls` and `public_reads`.

Developer docs: [REST API](/docs/developers/api/).

## Access

| Setting | What it does |
|---|---|
| **Turn on the REST API** | Off by default. When off, every call to `/api/v1` is refused with `api_disabled`. Keys are kept. |
| **Allow reading public data without a key** | Off by default. See below. |
| **Allowed origins (CORS)** | Websites whose pages may call the API from a browser. See below. |

### Anonymous reads

With this off, a caller needs a key even to read public listings. That is the
default on purpose: with no key, anyone can copy every listing on your site with one
script, and you cannot tell them apart or stop one of them.

Turn it on only if you want an open data feed. The per-address limit still applies.
Keys keep working either way.

## Keys

A key says who is calling and what it may do. The key itself is shown **once**, when
you make it. Only a hash is stored, so a lost key cannot be looked up: make a new one.

| Type | Looks like | Use it for |
|---|---|---|
| **Public key** | `scp_…` | A mobile app or a web page. It can only read public data, so it is safe to ship inside the app. |
| **Admin key** | `sck_…` | Your own scripts and servers. It acts as the admin who made it, with the scopes you tick. Keep it secret. |

If an app or page uses a public key and it leaks or is abused, revoke that one key.
Nothing else is affected.

### Make a key

1. Open **Admin → Settings → API** and go to **New key**.
2. Give it a **Name**: what uses it, such as "Mobile app".
3. Choose **Admin key** or **Public key**. A public key has no scopes to pick.
4. For an admin key, tick the **Scopes**. Give it the fewest it needs.
   [What each scope allows](/docs/developers/api/authentication/#scopes).
5. Optional: set an **Expires** date. Leave it empty for a key that works until you revoke it.
6. Type your password. If you use two-step sign-in, type a code too.
7. Press **Create key** and copy the key from the yellow box.

Moderators cannot use this screen. A key made by a moderator account could only
hold the moderator scopes.

### Rotate a key

**Rotate** makes a new key with the same type, scopes and expiry. The old key keeps
working until you revoke it, so you can switch your app over first and revoke after.
You can rotate your own keys and any public key. Revoke another admin's key and ask them to
make a new one.

### Revoke a key

**Revoke** stops a key at once, and cannot be undone. Both rotate and revoke ask for
your password again.

A key also stops when it expires, when its owner is disabled or deleted, or when its owner
is demoted below the scopes it holds.

When an admin uses **Sign out of all devices** on their profile, every admin and public key
they made is revoked too. The page says how many first. Other admins' keys are not touched.

## Browser apps (CORS)

A web page on another site can call the API only if you list its address in **Allowed
origins**. One per line, scheme and host only:

```text
https://app.example.com
https://staging.example.com:8443
```

- No path and no trailing slash.
- `*` allows any site, but only for calls with no key or a public key. A call carrying an
  admin key still needs its site listed exactly.
- Leave it empty if only servers and mobile apps use your API. Those do not need CORS.

The API never allows cookies from another site. A browser page there sends a key in the
`Authorization` header, so use a **public key** there and never put an admin key in a page.

Your own theme needs no key to act for the visitor who is signed in. It prints
`osc_api_session_meta()` in the page head and sends the page token with each call. This works
only from your site's own address, never through CORS, and cannot change passwords, e-mail
addresses or keys. See [Same-site session](/docs/developers/api/authentication/#same-site-session-theme-javascript).

## Rate limits

Limits count requests per minute.

| Setting | Default | Counted per |
|---|---|---|
| **Per key or signed-in user** | 120 | key |
| **Per address, without a key** | 60 | IP address (a whole `/64` for IPv6) |
| **Writes, per key or user** | 30 | key |

A public key is counted per key **and** address, so one busy visitor cannot use up the
limit for everyone using your app. A user's personal keys and sign-ins share one count, so
more keys do not raise a user's limit.

Posting has its own hourly cap, set below.

| Setting | Default | Counted per |
|---|---|---|
| **New listings, per user** | `0` | user, an hour. `0` uses 30, or 10 while your listing form asks for a captcha. One address may post three times that. |

Comments (20 an hour per user) and photo downloads by address (30 an hour) have fixed caps.

Over the limit, the API answers `429` with a `Retry-After` header. An address that keeps
sending wrong keys is also shut out for 15 minutes.

## Responses

| Setting | What it does |
|---|---|
| **Public cache time** | Seconds a browser or CDN may keep a public answer. Default 60. `0` turns shared caching off. |
| **Let new listings name photos by web address** | Off by default. When on, a listing can send photo addresses and your site downloads them. Only public addresses on the usual ports are fetched, with no redirects. When off, apps must upload each photo. |
| **Leave the seller phone number out of listings** | By default the API shows a phone number wherever your theme does. Tick this to hide it from everyone except the listing's owner and admin keys with the `admin:listings` scope. |

Seller e-mail addresses are shown only on listings whose owner chose to show them. Admin keys with `admin:listings` see all of them.

## Users

Apps can act for your users. A user signs in with their password and the app gets a short
access token and a longer refresh token. Admins never sign in this way: they use keys.

| Setting | What it does |
|---|---|
| **Let users make personal API keys** | Off by default. Lets a user make a key for their own scripts. Each key must expire within a year, holds only some of the user's rights, and stops working when the password changes. Making one asks for the password again. |
| **Allow sign-up through the API** | Off by default. Lets an app create accounts. It needs your site's registration on too. The new user gets the same activation e-mail as the sign-up form sends. Limited to 5 sign-ups an hour per address and 100 an hour for the site. |

The API shows no captcha, so sign-up and posting have the hourly limits above instead.
Posting still follows your rules: moderation, the listing limit, bans and spam checks.

### Token lifetimes

| Token | Lives |
|---|---|
| Access token | 15 minutes |
| Refresh token | 30 days since last use |

These are fixed.

### What users see

Users open **API access** in their account menu. The entry shows when the API is on and
either the user has a signed-in app or key, or you allow personal keys.

- A list of the apps signed in and the keys made: name, last used, address it last came from, when it ends and its rights.
- **Sign out** or **Revoke key** on each. Signing out ends the app's refresh token, so it stops
  within 15 minutes.
- When personal keys are on, a **Make a key** form: name, last day (within a year), rights and
  the user's password. The key is shown once.

In **Settings → API**, personal keys show in the key list with the type **User**. You can
revoke them there, but not rotate them.

## Webhooks

A webhook sends a signed POST to another system when something happens here: a listing is
posted, edited or deleted, a comment is added, a user signs up or changes their profile.
Use it to keep a stock system, a chat channel or a search index in step.

The **Webhooks** section of **Settings → API** lists your endpoints: the address, the events,
the last delivery and the status.

| Status | Meaning |
|---|---|
| **Active** | Events are sent. |
| **Off** | You switched it off. Nothing is sent. |
| **Paused** | The site switched it off after 8 failed deliveries in a row, or when the receiver answered `410 Gone`. |

### Add an endpoint

1. Go to **New webhook endpoint**.
2. **Address**: where to send events, such as `https://example.com/webhooks`.
3. **Description**: optional, such as "Stock sync".
4. Tick the **Events** it should get.
5. Type your password, and a code if you use two-step sign-in.
6. Press **Add endpoint** and copy the **signing secret** from the yellow box.

The secret starts `whsec_`. It is shown once. Give it to whoever builds the receiver: they
use it to check each delivery really came from your site.
[How to verify](/docs/developers/api/webhooks/#verify-the-signature).

### What each button does

| Button | What it does |
|---|---|
| **Edit** | Change the address, description or events. Shows the deliveries still waiting or given up. |
| **Send test** | Sends one test event now, even if the endpoint is off. Check **Last delivery**. |
| **Switch off** / **Switch on** | Stops or restarts sending. Switching on clears a pause. |
| **Rotate secret** | Makes a new secret, asks for your password. For 24 hours each delivery carries two signatures, so the receiver can switch over without a gap. |
| **Delete** | Stops sending and drops the deliveries still waiting. |

### When a delivery fails

The receiver must answer `2xx` within 15 seconds. If it does not, the site tries again after
about 1 minute, 5 minutes, 30 minutes, 2 hours, 5 hours, then every 10 hours: 12 tries over
about 72 hours. A receiver can ask for a later retry with `Retry-After`. A delivery that fails
every time stops, and stays in **Tools → System info → Jobs** with its last error.

A receiver that answers `410 Gone` pauses the endpoint at once, with one e-mail.

Deliveries go out when cron runs. With **Automatic cron process** on, an API call or a page
view starts it, at most every 5 minutes; a real cron line is faster and steadier.

When 8 deliveries in a row fail, the endpoint is paused and your site's contact address gets
one e-mail. Fix the receiver, then press **Switch on**. Events that happened while it was
paused are not sent.

### Private network addresses

By default the site sends only to public addresses on the standard ports (80 and 443). It
refuses `localhost`, `192.168.x.x`, `10.x.x.x` and the like. This stops a webhook from being
used to probe your own network.

To send to a server on your own network, such as `http://192.168.1.20/hook`, tick
**Allow webhook addresses on a private network** under **Webhooks** in the same screen.
It is off by default. Turn it on only if you need it. The port rule still holds.

Limits: 50 endpoints a site. Redirects are never followed.

Developer docs: [Webhooks](/docs/developers/api/webhooks/), and
[admin endpoints](/docs/developers/api/admin/#webhooks) to manage endpoints from a script.

## Apache: the Authorization header

Some Apache setups (PHP-FPM, CGI) hide the `Authorization` header from PHP. Then every
key is refused with `401`, even a good one.

The `.htaccess` ShopClass writes already has the fix. If your file is older, add this line
under `RewriteEngine On`:

```apache
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

**Admin → Settings → Permalinks** shows the full block to copy. If you cannot change
`.htaccess`, a public key also works as `?api_key=scp_…` on a GET.

## nginx

Nothing to add. nginx passes `Authorization` to PHP, and the usual
`try_files $uri $uri/ /index.php?$args;` rule already sends `/api/…` to ShopClass.

## From the command line

Make, list and revoke keys over SSH. No password is asked: shell access is the
permission. See the [CLI reference](/docs/cli/#api-keys).

```bash
php oc-cli.php api:key:create --admin=jane --name="Mobile app" --kind=public
php oc-cli.php api:key:list
php oc-cli.php api:key:revoke 4
```

## Checking it works

```bash
curl -H "Authorization: Bearer scp_YOUR_KEY" https://example.com/api/v1/
```

You should get your site's name, locales, the `api` switches and links. A `401` means the key is wrong or
the `Authorization` header never arrived. A `403` with `api_disabled` means the API is
switched off.

Limits, scopes and error codes are in [Authentication](/docs/developers/api/authentication/)
and [Errors](/docs/developers/api/errors/).
