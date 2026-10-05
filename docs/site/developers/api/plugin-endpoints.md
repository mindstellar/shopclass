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

## Add an endpoint

```php
use mindstellar\api\ProblemException;
use mindstellar\api\auth\Credential;
use mindstellar\api\Request;
use mindstellar\api\Response;

osc_api_register_route('GET', 'ext/acme-ratings/listings/{id}/rating', array(
    'handler'   => 'acme_ratings_api_show',
    'auth'      => 'public',
    'scope'     => 'listings:read',
    'summary'   => 'A listing\'s average rating',
    'tags'      => array('Ratings'),
    'responses' => array(200 => array('type' => 'object')),
));

function acme_ratings_api_show(Request $request, Credential $credential, array $args): Response
{
    $average = acme_ratings_average((int) $args['id']);
    if ($average === null) {
        throw ProblemException::of('not_found', 'No ratings for this listing.');
    }

    return Response::ok(array('listing_id' => (int) $args['id'], 'average' => $average));
}
```

Call `osc_api_register_route()` when your plugin loads. The route is then
`GET /api/v1/ext/acme-ratings/listings/12/rating`.

### The path

- It must start with `ext/<slug>/`. The slug is yours: lowercase letters, digits and `-`.
- A path that does not, or that would replace a core route, is dropped and logged. The
  rest of your routes still load.
- One exception: a route with `deprecated` set may keep an old path outside `ext/`, so a
  plugin can redirect it for a release after moving. It still cannot replace a core route.
- `{id}` matches digits. Any other `{name}` matches one path segment. Values arrive in `$args`.
- Allowed characters: letters, digits, `_`, `:`, `-` and `{name}`, with `/` between segments.
- Methods: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`. `HEAD` is answered by `GET` routes.

### The spec

| Key | Meaning |
|---|---|
| `handler` | Required. A callable, or `array(Class::class, 'method')`. A non-static method gets a new instance of the class, built with no arguments. |
| `auth` | Who may call: `none`, `public`, `user` or `admin`. Default: `public` for `GET`, `admin` for everything else. |
| `scope` | The scope the caller needs. Optional. |
| `summary`, `description`, `tags` | For the OpenAPI document and the reference. |
| `query` | A JSON Schema object for the query string. Values are typed, then checked. |
| `body` | A JSON Schema for the JSON body. Checked before your handler runs. |
| `responses` | `status => schema`, for the OpenAPI document. |
| `deprecated`, `sunset` | `YYYY-MM-DD` dates. They add `Deprecation` and `Sunset` headers. |

The schemas understand `type`, `properties`, `items`, `required`, `enum`, `minimum`,
`maximum`, `minLength`, `maxLength`, `pattern`, `format`, `minItems`, `maxItems` and
`additionalProperties`. A schema the API cannot read drops the route and logs why.

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
function handler(Request $request, Credential $credential, array $args): Response
```

| Class | Use |
|---|---|
| `Request` | `method()`, `path()`, `query()`, `queryString($name)`, `queryInt($name)`, `queryBool($name)`, `queryList($name)`, `header($name)`, `json()` (decoded body), `ip()` |
| `Credential` | `kind()`, `scopes()`, `has($scope)`, `userId()`, `adminId()`, `isAdmin()`, `isUser()`, `isAnonymous()` |
| `Response` | `Response::ok($data, $status = 200)`, `Response::collection($items, $meta, $links)`, `Response::noContent()`, `->withHeader($name, $value)` |
| `ProblemException` | `throw ProblemException::of($code, $detail)` |

`Response::ok()` wraps the data as `{"data": …}`. `Response::collection()` gives
`{"data": […], "meta": …, "links": …}`. Follow the [response rules](/docs/developers/api/#response-shape):
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

`$context` is a `ViewContext`. These methods are a stable API:

| Method | Returns |
|---|---|
| `viewer()` | The `Credential` of the caller |
| `view()` | `public`, `owner` or `admin` |
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

API requests do not load the active theme's `functions.php`, so hooks a theme adds there (validation, spam checks, routes) do not apply to the API. Put them in a plugin, or return `true` from the `api_theme_functions_enabled` filter.

`api_response` does not run when an `ProblemException` was thrown. A PATCH or DELETE that sends `If-Match` also runs it once for the GET of the same path, to compare ETags; check `$request->method()` if that matters to you.

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

Use a code from the catalogue. An unknown code is a `500`. For field errors:

```php
use mindstellar\api\Problem;

throw ProblemException::from(Problem::validation(array(
    array('pointer' => '/stars', 'code' => 'maximum', 'message' => 'must be at most 5', 'in' => 'body'),
)));
```

Any other exception becomes `server_error` and is written to the error log.

## Listing Import

[Listing Import](/docs/developers/importing-listings/) is a plugin that uses this: its
endpoints are under `/api/v1/ext/listing-import/`.
