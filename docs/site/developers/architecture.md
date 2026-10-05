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

## Modules

Each module lives under `mindstellar\` in `oc-includes/osclass/classes/<module>/`.

| Module | Holds | State |
|---|---|---|
| `auth` | `SignIn`, `SignOut`, `Reauth`, `AuthStamp`, `AdminPassword`, `Actor` | Done |
| `user` | `AccountService` (sign-up and its confirmation link, profile, e-mail change, password, account state, delete), `AccountInput`, `Usernames` | Done |
| `listing` | `ListingService`, `PhotoService`, `ListingMailService` (contact the seller, share with a friend), `ListingInput`, `ListingPolicy`, `ListingStatus`, `ListingValidator`, `ListingStats` | Done |
| `comment` | `CommentService`, `CommentPolicy`, `SavedComment` | Done |
| `moderation` | `ListingModeration`, `CommentModeration`: what an admin does to a listing's or comment's status | Done |
| `category` | `CategoryService` | Done |
| `currency` | `CurrencyService` | Done |
| `fields` | `FieldService`, `FieldSlug`, `FieldTypeRegistry`: custom fields and their types | Done |
| `location` | `LocationService`, locations admin and import | Done |
| `validation` | The refusals every service throws (below) | Done |
| `api` | The REST API: HTTP only, no rules of its own | Done |

### Names

A class's last word says what kind it is, in every module:

| Ending | Kind |
|---|---|
| `…Service` | Holds the rules for one area and does its writes. |
| `…Policy` | Yes/no answers. |
| `…Store` | Saves and reads rows. |
| `…Registry` | Plugins and themes register entries in it. |
| `…Exception` | Thrown. |
| `…Input`, `…Body` | A web request or an API body, read into the data a service takes. |
| `…Controller`, `…Serializer` | API request handling and output. |
| `…Manager` | Holds a shared resource and hands it out, such as `StorageManager` and `ConnectionManager`. |

Use one of these before making up a new ending.

### Rules for new code

- **Base classes** are `abstract` and live in `base/` (`mindstellar\base\`): `Model`,
  `Registry`, `SettingsScreen`. The name is the family's ending alone.
- **Table classes.** A table used by several modules has its class in `model/`
  (`mindstellar\model\`). A table one module owns has its class in that module, ending in
  `Store` (`billing\OrderStore`, `search\AlertStore`).
- **Making objects.** Use `new`. A class that must be shared, such as a registry or the
  database connection, offers `instance()`. Do not add `newInstance()`: it returns a shared
  object, not a new one. Released classes keep it only as a deprecated wrapper for plugins.
- **Strict types.** Every file in a `mindstellar\` namespace starts with
  `declare(strict_types=1);`. `tests/strict-types.php` fails on a new file without it.
- **Renames keep the old name.** A released class that moves or is renamed is added to
  `OSC_RENAMED_CLASSES` in `compatibility.php`, which makes the alias only when code asks for
  the old name. An exception gets a direct `class_alias`, because `catch` does not autoload.
  A released public method stays as a deprecated wrapper.

## Refusals

A service refuses with one of `mindstellar\validation\{InvalidException,
NotFoundException, ConflictException, ForbiddenException, BlockedException}`, all subclasses of `RefusedException`. A web controller shows the message; the API turns
each into its problem response (`422`, `404`, `409`, `403`, `429`). `BlockedException` is too many
wrong passwords, or too many writes in an hour, such as comments.

```php
use mindstellar\listing\ListingService;
use mindstellar\validation\RefusedException;

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
| Delete your own comment | `CommentService::delete()` | `pre_item_delete_comment_post`, `delete_comment` |
| Sign up, or create a user on the users screen | `AccountService::register()` | the sign-up form and the API fire `before_user_register` first; then `register_email_taken`, `user_add_flash_error`, then `user_register_failed` on a refusal; otherwise `pre_user_post`, `hook_email_admin_new_user`, `hook_email_user_validation`, `user_register_completed` |
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

$item = Item::newInstance()->findByPrimaryKey($id);
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
