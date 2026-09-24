---
title: Importing listings
description: Create and update listings from a plugin through core's own save path — ItemActions::prepareDataFrom(), import mode, owner and photos — plus the rate limit and address check an import API needs.
sidebar:
  order: 26
---

A plugin that brings listings in from somewhere else should save them the way a posted
listing is saved. Then validation, spam checks, custom fields, expiry, stats and hooks all
apply, and the plugin writes no listing table itself.

That save path is `ItemActions`. It was written for the listing form, so it reads the
request. `prepareDataFrom()` hands it plain data instead.

## Create a listing

```php
$actions = (new ItemActions(true))->asImport();
$actions->prepareDataFrom(array(
    'title'        => array('en_US' => 'Blue bike'),
    'description'  => array('en_US' => 'A good bike with lights.'),
    'catId'        => 12,
    'price'        => '150',
    'currency'     => 'EUR',
    'contactName'  => 'Partner shop',
    'contactEmail' => 'shop@example.com',
    'countryId'    => 'DE',
    'city'         => 'Munich',
    'ownerId'      => 0,
    'meta'         => array(4 => 'Blue'),
    'photos'       => array('/tmp/import/bike-1.jpg'),
), true);

$result = $actions->add();
$itemId = Params::getParamInt('itemId');
```

`add()` returns `1` (saved, waiting for validation), `2` (saved and live) or an error
message. On success the new id is in `Params::getParamInt('itemId')`.

The keys are the names the listing form posts, plus:

| Key | What it is |
|---|---|
| `meta` | Custom field values, keyed by field id |
| `photos` | Paths to local image files. Each is re-encoded into the listing, then **deleted** |
| `ownerId` | The account the listing belongs to; `0` for none |
| `id` | For an edit: the listing to change |

Without `ownerId`, admin mode gives the listing to the account whose e-mail is the contact
e-mail, which is the admin editor's rule. Pass `ownerId` whenever the contact address
comes from outside.

## Import mode

`new ItemActions(true)` is admin mode: no posting wait, no e-mails, no listing limit and no
moderation. `->asImport()` keeps the first two off and turns the other two back on, so an
import cannot skip the owner's listing limit or the site's approval queue.

## Update a listing

```php
$actions = (new ItemActions(true))->asImport();
$actions->prepareDataFrom(array('id' => $itemId) + $fields, false);
$result = $actions->edit();
```

An admin-mode edit needs no secret.

:::caution[The data is trusted]
`prepareDataFrom()` trusts its input as it would an admin's. `id` edits any listing, and
each photo path is read and then deleted. Check data that came from outside before it gets
here: decide the listing id yourself, and only pass photo files your plugin downloaded.
:::

## Other helpers

- `Params::withRequest($values, $fn)` runs `$fn` with the request params replaced by
  `$values`, then puts the real ones back. Use it for other code that reads the request.
- `\mindstellar\location\LocationImporter::normalizeKey($name)` gives a place name the key core matches
  locations by, so "München" and "munchen" find the same city.

## An import API

Two classes in `mindstellar\security` cover what an API that receives listings needs.

**`RateLimit::hit($context, $key, $max, $windowSeconds)`** counts one request for `$key`
and returns `false` once `$max` is passed in the window. The key can be an API key or an
account id; it is stored hashed. If the counter cannot be reached, it allows the request.

```php
if (!RateLimit::hit('acme_api', $apiKeyId, 120, 60)) {
    // answer 429
}
```

**`AddressGuard`** checks an address that someone else sent before the server fetches it,
for example a photo URL in a feed. It passes only http and https on their standard ports,
and only when every IP the host resolves to is public.

```php
$check = (new AddressGuard())->check($url);
if (!$check['ok']) {
    // $check['error'] says why
}
// Connect to $check['ip'] with CURLOPT_RESOLVE, and do not follow redirects blindly.
```

Pin the download to the checked IP. A second DNS answer could otherwise point it at a
private address. Check each redirect target the same way before following it.
