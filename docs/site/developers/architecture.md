---
title: Architecture
description: "How core is laid out: thin controllers, services that hold the rules and fire the hooks, policies, models, and the mindstellar\\ modules. Which classes a plugin should call."
sidebar:
  order: 30
---

Core is being moved into a layered shape, one module at a time. This page says what the
layers are, which modules exist so far, and what a plugin should call.

## The layers

| Layer | Holds | Never |
|---|---|---|
| **Controllers**: `CWeb*`, `CAdmin*`, `mindstellar\api\controller\*` | Read the request, call one service, render a page, a flash message or JSON. | Hold a business rule. |
| **Services**: e.g. `ListingService`, `PhotoService` | The rules. They fire the hooks, open the transaction and send the e-mails. | Read `Params`, the session or cookies. |
| **Policies**: e.g. `ListingPolicy` | Yes/no answers: may this person see, change or post this? | Write anything. |
| **Models**: the `Item`, `User`… DAOs and `mindstellar\model\*` | SQL. | Decide anything. |

A controller tells a service who is acting with an `mindstellar\auth\Actor`: the signed-in
user, the admin, or a guest, with their address and, for a guest listing, the edit secret.

```php
use mindstellar\auth\Actor;

$actor = new Actor($userId, $adminId, $ip, $secret);   // any of them may be null or ''
Actor::user(7);                                        // a signed-in user
Actor::admin(0);                                       // admin rights, nobody signed in (CLI, import)
```

A new table class extends `mindstellar\base\Model` and names its table in `TABLE`; `table()`
gives a query on it through the parameterized DB API. Legacy models extend `DAO`. API
controllers have no base class: the kernel checks the key, scope, limits and input before one
runs.

The direction is one way: controllers → services → policies and models. A service never
calls a controller, and core never imports `mindstellar\api`. The API is one more set of
controllers.

Tests hold these rules:

- `tests/core-no-api-import.php`: core never imports the API.
- `tests/no-raw-table-access.php`: table SQL stays in models and stores.
- `tests/services-no-request-state.php`: services, policies, stores and queries never read the request.
- `tests/query-store-layering.php`: queries only read and call no legacy model; stores use no query.
- `tests/db-errors-not-swallowed.php`: new code does not hide a database error.
- `tests/controller-actions.php` and `tests/controller-method-size.php`: each action has its own short method.
- `tests/strict-types.php` and `tests/classmap-current.php`: new classes are strict and loadable.
- `tests/classes-folder-map.php`: every class folder is in the folder map below.
- `tests/hook-contract.php` and `tests/api-contract.php`: hook names and arguments, and the API surface plugins use.

## A request, start to finish

A page on the site:

1. `index.php` loads `oc-load.php`. There, `Rewrite::getInstance()->init()` turns a friendly URL into the
   `page` and `action` request values, using the `CoreRoutes` table and the routes plugins add.
2. `mindstellar\routing\FrontController::run()` checks maintenance mode, then asks
   `PageDispatcher::web()` for the controller of `page`.
3. `PageRoutes::web()` maps each `page` to a controller, such as `item` → `CWebItem`.
   Plugins add pages with the `page_routes` filter.
4. The controller's `doModel()` picks the code for `action`, reads the request into the data
   a service takes (`ListingInput::read()`), and calls the service
   (`ListingService::create()`).
5. The service checks the rules, writes in one transaction and fires the hooks. The
   controller then renders a page or redirects.

Admin pages take the same path from `oc-admin/index.php`, with `PageDispatcher::admin()` and
`PageRoutes::admin()`. Admin ajax calls go to one handler class per area in
`mindstellar\admin\ajax`, listed in `AjaxRegistry`.

A REST API request:

1. The `api` page goes to `CWebApi`, which hands the request to
   `mindstellar\apikey\ApiAccess::serve()`.
2. `mindstellar\api\Kernel::handle()` finds the route, checks the key, scope, rate limit and
   body, then calls the route's controller, such as `ListingWritesController::create()`.
3. The controller turns the body into the same data (`ListingWriter` → `ListingInput::fromArray()`)
   and calls the same service as the web page.

So a listing posted on the web and one posted through the API share everything from the
service down.

Only two kinds of class read the request, the session or the visitor's address: the
`…Input` classes and `mindstellar\auth\Actor`. A service gets what it needs from them. Two
bridges exist for hooks that still read global state: `Params::withRequest()` runs code with
other request values, and `ViewScope::withItem()` runs a hook with the listing the
`osc_item_*()` helpers read.

## Folders

Every class lives in `oc-includes/osclass/classes/`. A folder is a module: its classes are
in the `mindstellar\<folder>` namespace. The old classes plugins use have no namespace: all of
`controller`, `actions` and `datatables`, the files in the root, and some of `model`, `form` and
`logger`.
`tests/classes-folder-map.php` fails when a folder is missing from this table.

| Folder | Holds |
|---|---|
| `actions` | `ItemActions`, `UserActions`: old wrappers kept for plugins; each calls a service |
| `admin` | Admin panel code: ajax actions (`ajax/`), settings screens (`form/`), shared form and editor parts (`ui/`), stats, system checks |
| `api` | The REST API: HTTP only, no rules of its own. Core never uses it, so it can be switched off |
| `apikey` | API keys, sign-in tokens and scopes. Core manages them (settings, CLI, account pages) even with the API off, so they are not under `api` |
| `auth` | Signing in and out, re-asking for a password, and `Actor`: who is acting |
| `backup` | Making, storing and restoring backups |
| `base` | Abstract base classes and traits other classes extend: `Model`, `Registry`, `SettingsScreen`, `Cache` (the object-cache drivers), `ActionMap` |
| `billing` | Packages, orders, entitlements, payment gateways and receipts |
| `cache` | The object cache, its drivers (`drivers/`), the Redis clients they use, and page-cache purges |
| `category` | Categories: `CategoryService`, `CategoryStore`, `CategoryQuery` |
| `cli` | `oc-cli.php` commands |
| `comment` | Listing comments: posting, rules and reads |
| `controller` | Web and admin page controllers (`CWeb*`, `admin/CAdmin*`) and their base classes |
| `currency` | Currencies and `Money` |
| `database` | The database connection, `Db` and `QueryBuilder`, the legacy `DAO`, schema checks, row hashes that tell when a row changed |
| `datatables` | The admin list tables |
| `exception` | The refusals every service throws (below) |
| `fields` | Custom fields and their types |
| `form` | Old form renderers kept for themes, `FormBuilder`, and the site's custom forms (`builder/`) |
| `job` | The background job queue |
| `language` | Installing and storing languages |
| `listing` | Listings and their photos: posting, editing, rules, reads and mail |
| `location` | Countries, regions, cities: reads, admin and import |
| `logger` | The admin log and error logging |
| `market` | The plugin and theme market: catalogue, install, compatibility |
| `migration` | Running the database migrations |
| `model` | Tables several modules use: the old `Item`, `User`… classes (kept for plugins; add no methods) and new `mindstellar\model` stores |
| `moderation` | What an admin does to a listing's or comment's status, and the keyword block list |
| `pages` | Static pages and page templates |
| `privacy` | Where a person's data lives, so a copy can be handed back |
| `routing` | URLs: routes, the front controller and reserved slugs |
| `search` | Search and saved-search alerts; the SQL is built in `query/` |
| `security` | CSRF, rate limits, captcha, ban rules, two-factor sign-in, signed links |
| `settings` | The registry plugins add settings pages to, and image fields on those pages |
| `storage` | File storage (local, S3), uploads and the media library |
| `theme` | Themes and their views |
| `upgrade` | Core, plugin and theme updates |
| `user` | User accounts: sign-up, profile, password, delete |
| `utility` | Small tools with no module: escaping, dates, files, the clock |
| `webhook` | Outgoing webhooks: endpoints, delivery and retries |
| `widgets` | Widget types plugins register, and saved widgets |

### Names

A class's last word says what kind it is, in every module:

| Ending | Kind |
|---|---|
| `…Service` | Holds the rules for one area and does its writes. |
| `…Policy` | Yes/no answers. |
| `…Store` | Saves rows of one table, and reads them by id. |
| `…Query` | Reads for one screen or the API, across tables. New ones never write. |
| `…Registry` | Plugins and themes register entries in it. |
| `…Exception` | Thrown. |
| `…Input`, `…Body` | A web request or an API body, read into the data a service takes. |
| `…Controller`, `…Serializer` | API request handling and output. |
| `…Manager` | Holds a shared resource and hands it out, such as `StorageManager` and `ConnectionManager`. |
| `…Ajax` | One area's admin ajax actions, listed in `AjaxRegistry`. |

Use one of these before making up a new ending.

Some older names stay, because plugins and site settings use them: the `CWeb*` and
`CAdmin*` controllers, and the `Item`, `User`,
`Category`… table classes in `classes/model/` (the legacy DAOs). `Item` is the table and its
legacy class; new code about listings is named `Listing…`. A few interfaces also end in
`Store` without being a table: the settings form stores, and the API's idempotency and
rate-limit stores.

### Where new code goes

| You are adding | Put it in |
|---|---|
| A page or an action on a page | A method on its `CWeb*` or `CAdmin*` controller in `classes/controller/` |
| An admin ajax action | The area's `…Ajax` class in `classes/admin/ajax/` |
| An API endpoint | `classes/api/controller/` |
| A rule, or a write | The module's `…Service` |
| A yes/no question | The module's `…Policy` |
| A table one module owns | The module's `…Store` |
| A table several modules use | `classes/model/` (`mindstellar\model\`) |
| A read for one screen or the API | The module's `…Query` |
| An admin settings page | A `…SettingsScreen` in `classes/admin/form/` |
| A helper for themes and plugins | The matching `helpers/h*.php`, as a thin call into a class |

Never add a method to a legacy DAO in `classes/model/` or to `ItemActions` or `UserActions`.
They are kept for plugins.

### Reading rows

- A `…Store` or `…Query` returns `null` when there is no row, and lets a `DbException`
  through. A legacy DAO method keeps what it returns today (`false` or an empty array).
- A `…Store` returns the database's own types. Use `Db::stringifyRow()` only for a row that
  goes to a theme or a hook, where code compares strings such as `'1'`.
- Core's new code reads tables through Stores and Queries with `mindstellar\database\Db`.
  The `osc_db_*` helpers are for plugins.

Pick the read by what you need:

| You need | Use |
|---|---|
| Some columns of one listing, maybe locked for a write | `ListingStore::find($id, $columns, $lock)` |
| A listing's status flags | `ListingQuery::statusRow($id)` |
| A listing as the edit form reads it | `ListingQuery::editRows($id, $withTexts)` |
| A listing with its texts, category name and place, for a theme or a hook | `Item::getInstance()->findByPrimaryKey($id)` |
| A listing as the API answers with it | `mindstellar\api\read\ListingReader` |
| The listing the current page shows | `osc_item()` |
| One user's bare row | `UserStore::find($id)` |
| Several users' columns | `UserQuery::byIds($ids, $columns)` |
| A user with their descriptions, cached | `UserQuery::find($id)` |

### Rules for new code

- **Base classes** are `abstract` and live in `base/` (`mindstellar\base\`): `Model`,
  `Registry`, `SettingsScreen`. The name is the family's ending alone.
- **Table classes.** A table used by several modules has its class in `model/`
  (`mindstellar\model\`). A table one module owns has its class in that module, ending in
  `Store` (`billing\OrderStore`, `search\AlertStore`).
- **Making objects.** Use `new`. A class that must be shared, such as a registry or the
  database connection, offers `getInstance()`. Do not add `newInstance()`: it returns a shared
  object, not a new one. Since 7.0, released classes keep it, and `instance()`, only as
  deprecated wrappers: a plugin that also supports 6.x keeps calling `newInstance()`.
- **Strict types.** Every file in a `mindstellar\` namespace starts with
  `declare(strict_types=1);`. `tests/strict-types.php` fails on a new file without it.
- **Class loading.** A class in a `mindstellar\` namespace loads by its path. A class with no
  namespace loads only from Composer's class map: run `composer dump-autoload` and commit
  `oc-includes/vendor/composer/`.
- **Code style.** Use `[]` for arrays in new code. Do not reformat lines you are not changing.
- **Renames keep the old name.** A released class that moves or is renamed is added to
  `OSC_RENAMED_CLASSES` in `compatibility.php`, which makes the alias only when code asks for
  the old name. An exception gets a direct `class_alias`, because `catch` does not autoload.
  A released public method stays as a deprecated wrapper.

## Refusals

A service refuses with one of `mindstellar\exception\{InvalidException,
NotFoundException, ConflictException, ForbiddenException, BlockedException}`, all subclasses of `RefusedException`. A web controller shows the message; the API turns
each into its problem response (`422`, `404`, `409`, `403`, `429`). `BlockedException` is too many
wrong passwords, or too many writes in an hour, such as comments.

```php
use mindstellar\listing\ListingService;
use mindstellar\exception\RefusedException;

try {
    $saved = (new ListingService())->create($data, $actor);
} catch (RefusedException $e) {
    osc_add_flash_error_message($e->getMessage());
}
```

## Where hooks fire

Hooks fire from services, so a listing posted on the web and one posted through the API
fire the same hooks in the same order. Tests pin that order.

| Write | Service | Hooks, in order |
|---|---|---|
| Post a listing | `ListingService::create()` | `item_add_prepare_data`, `pre_item_add`, `pre_item_add_error`, `uploaded_file` per photo, the new-listing e-mail hooks, `item_increase_stat`, `posted_item` |
| Edit a listing | `ListingService::update()` | `item_edit_prepare_data`, `pre_item_edit`, `pre_item_edit_error`, `uploaded_file` per photo, `edited_item` |
| Delete a listing | `ListingService::delete()` | `before_delete_item`, `delete_item`, `after_delete_item` |
| Add photos to a listing | `PhotoService::add()` | `uploaded_file` per photo, `edited_item` |
| Delete a photo | `PhotoService::delete()` | `delete_resource` |
| Post a comment | `CommentService::post()` | `pre_item_add_comment_post`, the `action_throttle_limit` filter, `before_add_comment`, the new-comment e-mail hooks, `add_comment` |
| Delete your own comment | `CommentService::delete()` | `pre_item_delete_comment_post` (only once the caller is the signed-in author), `delete_comment` |
| Sign up, or create a user on the users screen | `AccountService::register()` | a visitor's sign-up is refused when sign-ups are off or the e-mail or address is banned, after `before_user_register`; then `register_email_taken`, `user_add_flash_error`, then `user_register_failed` on a refusal; otherwise `pre_user_post`, `hook_email_admin_new_user`, `hook_email_user_validation`, `user_register_completed` |
| Edit a profile | `AccountService::update()` | `pre_user_post`, `user_edit_flash_error`, `user_edit_completed` |
| Ask to change the e-mail | `AccountService::requestEmailChange()` | `hook_email_new_email` |
| Delete an account | `AccountService::delete()` | `before_user_delete`, `delete_user`, `after_delete_user` |
| Block, unblock, activate a user | `AccountService::disable()` and the others | `disable_item` per listing, then `disable_user` (and so on) |
| Moderate a comment | `CommentModeration` | `activate_comment`, `deactivate_comment`, `enable_comment`, `disable_comment`, `edit_comment`, `delete_comment` |

Each of these writes runs in one database transaction. E-mails sent from a hook wait until it
commits, and a failure rolls back the whole write. Don't call a slow remote service from these
hooks: the transaction stays open while it runs. Queue a [background job](/docs/developers/jobs/)
instead.

## What a plugin should call

New code calls the services:

```php
use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\PhotoService;

$item = Item::getInstance()->findByPrimaryKey($id);
if (ListingPolicy::canManage($item, $actor)) {
    (new PhotoService())->delete($photoId, $id, $actor);
}
```

`ItemActions` and `UserActions` stay, with the same methods. Each method that has moved is
now a thin call into its service, and its docblock names it
(`compatibility: use ListingService::create()`). `ItemActions::add_comment()` calls
`CommentService::post()`; `UserActions::add()` calls `AccountService::register()` and `UserActions::edit()` calls
`AccountService::update()`. Your existing
calls keep working; switch when you next touch that code.

Themes and simple plugins can keep using the `osc_*` helpers; see
[Helper functions](/docs/developers/helpers/) for the full list.

Classes that moved keep their old name for a release, marked `@deprecated`:

| Old | New |
|---|---|
| `mindstellar\security\UserReauth` | `mindstellar\auth\Reauth` |
| `mindstellar\security\ItemAccess` | `mindstellar\listing\ListingPolicy` |
| `Item::liveConditions()` | `mindstellar\listing\ListingStatus::liveConditions()` |

## One rule, one place

A listing is live when it is enabled, active, not spam, and premium or not yet expired.
`ListingStatus` is that rule. Search, the category counts, the totals, the API and webhooks
all read it. An expired listing still has its own page, with a notice, for everyone; search
and lists leave it out.

Two more shared rules live in `mindstellar\security`:

- A rate limit counts an IPv6 client by its /64, so it cannot step around the limit by
  changing addresses. Key per-address limits on `AddressBucket::of($ip)`.
- A request to an address a user gave (a webhook, a photo by URL) goes through
  `AddressGuard::check()`, then cURL with `AddressGuard::curlOptions()`, which connects only
  to the checked IP.
