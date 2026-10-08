---
title: API plugin endpoints
description: "Extend the ShopClass REST API from a plugin: register routes under ext/<slug>/, declare scopes and fields, add data to listings without extra queries, and answer with problem errors."
sidebar:
  order: 5
---

A plugin can add endpoints to the REST API, new scopes, and extra data on listings, users
and categories.

:::note[Extend the API from a plugin, not a theme]
The hooks also work from a theme's `functions.php`. Don't. An app expects the same answer
whichever theme is active, and a theme change would change the API.
:::

## What you may rely on

Only what is marked `@api` in core's source is the plugin contract: the `osc_api_*` helpers,
`ApiCall`, `ApiKit`, the spec keys listed below, the `ProblemException` and `Problem`
factories, the `Response` factories, `ViewContext`'s getters and the documented `Request` and
`Credential` methods. Everything else under `mindstellar\api` is internal and may change in
any release, including core's component schema names.

## Add an endpoint

```php
use mindstellar\api\ApiCall;
use mindstellar\api\ProblemException;
use mindstellar\api\Response;

osc_api_register_route('GET', 'ext/acme-ratings/listings/{id}/rating', array(
    'handler'   => 'acme_ratings_api_show',
    'auth'      => 'public',
    'scope'     => 'listings:read',
    'summary'   => 'A listing\'s average rating',
    'tags'      => array('Ratings'),
    'responses' => array(200 => array('type' => 'object')),
));

function acme_ratings_api_show(ApiCall $call): Response
{
    $id      = (int) $call->arg('id');
    $average = acme_ratings_average($id);
    if ($average === null) {
        throw ProblemException::of('not_found', 'No ratings for this listing.');
    }

    return Response::ok(array('listing_id' => $id, 'average' => $average));
}
```

Call `osc_api_register_route()` when your plugin loads. The route is then
`GET /api/v1/ext/acme-ratings/listings/12/rating`.

### The path

- It must start with `ext/<slug>/`. The slug is yours: lowercase letters, digits and `-`.
  The first plugin to register a route under a slug owns it; another plugin's route there
  is dropped.
- A path that does not, or that would replace a core route, is dropped and logged. The
  rest of your routes still load.
- One exception: a route with both `deprecated` and `sunset` may keep an old path outside
  `ext/` until its sunset day, so a plugin can redirect it after moving. It cannot use a
  path any core route could answer.
- Registering the same method and path twice keeps the first and logs the second. Two
  routes that can match the same path are logged; the one registered first answers.
- `{id}` and `{photo}` match digits. Any other `{name}` matches one path segment. Read a value with `$call->arg('name')`.
- Allowed characters: letters, digits, `_`, `:`, `-` and `{name}`, with `/` between segments.
- Methods: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`. `HEAD` is answered by `GET` routes.

### The spec

| Key | Meaning |
|---|---|
| `handler` | Required. A callable, or `array(Class::class, 'method')`. A non-static method gets a new instance of the class, built with no arguments. To build it with services, register a closure that builds it, or use `$call->kit()`. |
| `auth` | Who may call: `none`, `public`, `user` or `admin`. Default: `public` for `GET`, `admin` for everything else. |
| `scope` | The scope the caller needs. Required on every route with `user` or `admin` auth, `GET` too, or the route is dropped and logged: a key limited to other scopes could call it. |
| `summary`, `description`, `tags` | For the OpenAPI document and the reference. |
| `query` | A JSON Schema object for the query string. Values are typed, then checked. |
| `body` | A JSON Schema for the JSON body. Checked before your handler runs. |
| `responses` | `status => schema`, for the OpenAPI document. |
| `deprecated`, `sunset` | `YYYY-MM-DD` dates. They add `Deprecation` and `Sunset` headers. |
| `where` | `placeholder => regex` for one path segment, e.g. `array('external_id' => '[A-Za-z0-9_-]+')`. Without it `{id}` and `{photo}` match digits and any other `{name}` one segment. |
| `versions` | The API versions the route serves, e.g. `array('v1', 'v2')`. Default: `v1` only. |

Core-only keys (`replayable`, `upload`, `oauth`, `prepare`) drop the route, and so does any key not listed above, so a typo such as `scopes` cannot leave a route open.

The schemas understand `type`, `properties`, `items`, `required`, `enum`, `minimum`,
`maximum`, `minLength`, `maxLength`, `pattern`, `format`, `minItems`, `maxItems`,
`additionalProperties` and `$ref`. A schema the API cannot read drops the route and logs why.

### Your own components

A `$ref` may point at your own components and at these core ones: `Problem`, `Listing`,
`ListingPage`, `Photo`, `PageMeta` and `PageLinks`, the shapes `ApiKit` and `Page::whole()`
answer with. Other core component names are not part of the contract. Register yours with
`osc_api_register_schema()`, which returns the `$ref`:

```php
$rating = osc_api_register_schema('acme-ratings', 'Rating', array(
    'type'       => 'object',
    'properties' => array('average' => array('type' => 'number')),
));
// array('$ref' => '#/components/schemas/ExtAcmeRatingsRating')
```

The component is named `Ext<Slug><Name>`. It may refer to your other components and to
the core components above only.
The first schema registered under a name wins; a different one under the same name is
refused and logged.

### Auth rules

| `auth` | Who gets in |
|---|---|
| `none` | Anyone, no key. Use for health or docs endpoints only. |
| `public` | Any key, or nobody when the owner allowed anonymous reads. **`GET` only.** |
| `user` | A user's key or token. |
| `admin` | An admin key. A moderator's key is refused unless the route names a `scope` moderators may hold. |

**A write can never be `public`.** `POST`, `PUT`, `PATCH` and `DELETE` with `auth => public`
are dropped. Use `user` or `admin`.

A public key holds only `listings:read`. For a read endpoint that apps with a public key
should reach, name `listings:read` as the scope, or name none.

### The handler

```php
function handler(ApiCall $call): Response
```

A handler that needs nothing from the call may take no argument.

| Class | Use |
|---|---|
| `ApiCall` | `request()`, `credential()`, `args()` (the path values), `arg($name)` (one value, or `null`), `intArg($name)`, `input()` (the decoded JSON body, `array()` when none was sent), `kit()` |
| `ApiKit` | `context($call, $object, $members, $includes)` (a `ViewContext` for this caller), `listing($call, $id, $context)` (one listing, or `null` when it does not exist or the caller may not see it), `listings()` (core's listing reader for rows you found yourself; it does not check visibility), `links()` |
| `Request` | `method()`, `path()`, `version()`, `routePath()`, `query()`, `queryString($name)`, `queryInt($name)`, `queryBool($name)`, `queryList($name)`, `queryIds($name)`, `header($name)`, `input()`, `ip()` |
| `Credential` | `kind()`, `scopes()`, `has($scope)`, `userId()`, `adminId()`, `isAdmin()`, `isModerator()`, `isUser()`, `isSession()`, `isAnonymous()` |
| `Response` | `Response::ok($data, $status = 200)`, `Response::created($data, $location)`, `Response::collection($items, $meta, $links)`, `Response::noContent()`, `->withHeader($name, $value)`, and in hooks `->status()`, `->body()`, `->withBodyMember($name, $value)` |
| `RouteSpec` | In hooks: `key()` (`GET ext/acme/x`), `method()`, `path()` |
| `read\Page` | `Page::whole($items, $call->kit()->links(), $call)`: a full list in the standard list envelope |
| `ProblemException` | `of($code, $detail)`, `notFound($detail)`, `field($pointer, $code, $message)`, `tooMany($message, $retryAfter)`, `from($response)` |

`Response::ok()` wraps the data as `{"data": …}`. `Response::collection()` gives
`{"data": […], "meta": …, "links": …}` with only what you pass; use `Page::whole()` for the standard list envelope. Follow the [response rules](/docs/developers/api/#response-shape):
`snake_case`, RFC 3339 UTC times, absolute URLs, `null` for missing values.

The API adds `ETag`, `Cache-Control`, CORS and rate limit headers itself. Do not send them.
A handler that returns anything but a `Response` ends as `server_error`.

`osc_api_url('ext/acme-ratings/listings/12/rating')` gives the full address, with or
without friendly URLs.

### A write endpoint

```php
osc_api_register_route('POST', 'ext/acme-ratings/listings/{id}/rating', array(
    'handler' => 'acme_ratings_api_rate',
    'auth'    => 'admin',
    'scope'   => 'ext:acme-ratings:write',
    'summary' => 'Set a listing\'s rating',
    'body'    => array(
        'type'                 => 'object',
        'required'             => array('stars'),
        'additionalProperties' => false,
        'properties'           => array('stars' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 5)),
    ),
));
```

Declare the `ext:acme-ratings:write` scope as shown under [Scopes](#scopes). A body with the wrong type, or an unknown field, answers `422` with an `errors` entry pointing
at the field, before your handler runs. Write calls also count against the writes limit.

## Scopes

Declare your scopes with the `api_scopes` filter. A scope is `ext:<slug>:<verb>`.

```php
osc_add_filter('api_scopes', function ($scopes) {
    $scopes['ext:acme-ratings:write'] = array(
        'description' => 'Set listing ratings.',
        'audience'    => 'admin',
    );

    return $scopes;
});
```

The `audience` says who may hold the scope. Default `admin`. A scope with another name, or
an unknown audience, is dropped.

| Audience | May hold it |
|---|---|
| `admin` | Full admins' keys |
| `moderator` | Admins' and moderators' keys |
| `user` | Users' keys and tokens, and admins' and moderators' keys |

A public key can only ever hold `listings:read`, whatever you declare. Declared scopes show
up on the admin **New key** form.

## Add data to listings, users and categories

Plugin data goes under `ext.<your-slug>`. Never add a top-level member.

```json
{ "id": 159, "title": "…", "ext": { "acme-ratings": { "average": 4.5 } } }
```

A top-level key a filter adds is dropped, and the callback that added it is logged.

### Declare the field

```php
osc_api_register_field('listing', 'acme-ratings', 'average', array('type' => 'number'), array('public'));
```

| Argument | Meaning |
|---|---|
| object | `listing`, `user` or `category` |
| slug | Your plugin slug |
| name | Lowercase letters, digits and `_` |
| schema | JSON Schema of the value |
| views | `public`, `owner` or `admin`: who sees it. A `public` field is also sent in the owner and admin views. |

A declared field shows in the OpenAPI document and works in `?fields=ext.acme-ratings.average`.
`?fields=ext.acme-ratings` selects all of the plugin's fields. Data you send **without**
declaring it is shown to the admin view only, and is missing from the reference.

### Fill it in

Three filters, one per object. Each takes the data and **must return it**.

| Filter | Arguments |
|---|---|
| `api_listing` | `$data, $item, $context` (`$item` is the listing row) |
| `api_user` | `$data, $user, $context` (`$user` is the user row) |
| `api_category` | `$data, $category, $context` (`$category` is the category row) |

The row is the database row and is **not** part of the contract: its columns may change in any
release. Read what you need from `$data` where you can.

A filter may change the value of a member `$data` already has, and add under `ext`. Anything
else is undone and logged:

- a member it adds is dropped, even a core one
- a core member it removes is put back
- a core member whose JSON type it changes gets its core value back

Only top-level members and their JSON types are checked: values and keys inside an object member are not.

`$context` is a `ViewContext`. These methods are a stable API:

| Method | Returns |
|---|---|
| `viewer()` | The `Credential` of the caller |
| `view()` | `public`, `owner` or `admin` |
| `version()` | The API version of the answer, e.g. `v1` |
| `locale()` | The locale of the answer |
| `fields()` | The `?fields=` choice, or `null` when none. `fields()->wants('ext')` tells you if `ext` is wanted. |
| `include()`, `includes($name)` | The `?include=` values |

### Lists: prefetch, then look up

A listing page runs `api_listing` once per row. A query inside it means 12, 24 or 50 extra
queries. Instead, load everything once in `api_listings_prefetch`, which runs once per list
with every id, before the rows are built.

```php
function acme_ratings_cache(?array $set = null): array
{
    static $cache = array();
    if ($set !== null) {
        $cache = $set + $cache;
    }

    return $cache;
}

function acme_ratings_load(array $ids): void
{
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows  = osc_db_select(
        'SELECT fk_i_item_id, AVG(i_stars) AS average FROM ' . DB_TABLE_PREFIX . 't_acme_rating'
        . ' WHERE fk_i_item_id IN (' . $marks . ') GROUP BY fk_i_item_id',
        $ids
    );
    $found = array_fill_keys($ids, null);
    foreach ($rows as $row) {
        $found[(int) $row['fk_i_item_id']] = round((float) $row['average'], 1);
    }
    acme_ratings_cache($found);
}

osc_add_hook('api_listings_prefetch', function (array $ids, $context) {
    acme_ratings_load($ids);
});

osc_add_filter('api_listing', function (array $data, $item, $context) {
    $id = (int) $data['id'];
    if (!array_key_exists($id, acme_ratings_cache())) {
        acme_ratings_load(array($id));
    }
    $data['ext']['acme-ratings']['average'] = acme_ratings_cache()[$id];

    return $data;
});
```

The prefetch hook does not run for `GET /listings/{id}`, which builds one row. So the filter
falls back to loading that one id, as above.

If the caller asked for fields that leave out `ext`, skip the work:

```php
$fields = $context->fields();
if ($fields !== null && !$fields->wants('ext')) {
    return $data;
}
```

## Hooks around every request

| Hook | Kind | Arguments | Use |
|---|---|---|---|
| `api_request_before` | action | `$request, $route, $credential` | Audit, or refuse by throwing `ProblemException`. Runs after sign-in and rate limits, before validation. |
| `api_response` | filter | `$response, $request, $route` | Change headers or body of a successful answer. **Must return a `Response`.** Anything else is ignored. |
| `api_rate_limit` | filter | `array('max' => 120, 'window' => 60), $credential, $route` | Another limit for a caller or route. |
| `api_cors_origins` | filter | `$origins, $request` | Add allowed origins. A list of origin strings. |
| `api_routes` | filter | `$routes` (`'METHOD path' => spec`) | What `osc_api_register_route()` uses. |
| `api_fields` | filter | `$declared` | What `osc_api_register_field()` uses. |
| `api_schemas` | filter | `$schemas` (`name => schema`) | What `osc_api_register_schema()` uses. |
| `api_problem_codes` | filter | `$codes` (`code => array($status, $title)`) | Your own error codes. |

API requests do not load the active theme's `functions.php`, so hooks a theme adds there (validation, spam checks, routes) do not apply to the API. Put them in a plugin, or return `true` from the `api_theme_functions_enabled` filter.

`api_response` does not run when an `ProblemException` was thrown. A PUT, PATCH or DELETE that sends `If-Match`, on a path whose GET keeps no stored version, also runs it once for that GET, to compare ETags; check `$request->method()` if that matters to you. Plugin routes keep no stored version.

```php
osc_add_hook('api_request_before', function ($request, $route, $credential) {
    if ($credential->kind() === 'public' && $request->queryString('q') === 'scrape') {
        throw ProblemException::of('forbidden', 'This search is not allowed.');
    }
});
```

## Errors

Throw `ProblemException` from a handler or an `api_request_before` listener. The API turns it
into a [problem answer](/docs/developers/api/errors/) with the right status.

```php
throw ProblemException::of('not_found', 'No ratings for this listing.');
```

Use a code from the catalogue, or your own. Yours are named `ext_<slug>_<name>` (the slug's
`-` written `_`) and declared with a status from 400 to 599 and a title:

```php
osc_add_filter('api_problem_codes', function ($codes) {
    $codes['ext_acme_ratings_closed'] = array(409, 'Ratings are closed for this listing.');

    return $codes;
});

throw ProblemException::of('ext_acme_ratings_closed', 'The listing has expired.');
```

An unknown code is a `500` and is logged. For a field error:

```php
throw ProblemException::field('/stars', 'maximum', 'must be at most 5');
```

It is about the body unless you pass `'query'` as the fourth argument. Use a
[field code](/docs/developers/api/errors/#field-codes) or your own `ext_<slug>_<name>` one; any
other is answered as `invalid` and logged.

Any other exception becomes `server_error` and is written to the error log.

## Versions

A route serves `v1` only unless its spec lists more in `versions`. When v2 ships, your route
keeps serving v1 until you add `v2`, and `osc_api_url()` keeps pointing at v1. Pass a
version as its second argument to point elsewhere. Read `$call->request()->version()` or
`$context->version()` if your answer differs by version. See [Versioning](/docs/developers/api/changelog/#versioning).

## Listing Import

[Listing Import](/docs/developers/importing-listings/) is a plugin that uses this: its
endpoints are under `/api/v1/ext/listing-import/`.
