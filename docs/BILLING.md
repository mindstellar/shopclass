# Billing & entitlements

Status: **Shipped in Shopclass 6.2.0**, off by default. Wallet, ledger, orders,
entitlements, the listing quota, the feature registry and the built-in bank-transfer
gateway are all built. The one gap is theming: the bundled theme is its own repository
and does not yet ship `user-billing-*.php` templates, so sites see core's plain fallback
views until it does.

Admin-facing documentation is at
[mindstellar.com/docs/use/currencies-and-paid-listings](https://mindstellar.com/docs/use/currencies-and-paid-listings/).
This document is the contract between core and a payment plugin.

Core owns **entitlements**. Plugins own **money**.

Shopclass has never had a payment surface — paid listings have always been reimplemented
from scratch by each commercial plugin, each with its own tables, its own way of flipping
`t_item.b_premium`, and its own webhook handling. The result is that no two paid-listing
plugins can coexist, and none of them can be reviewed for correctness.

This layer fixes that by defining the narrow seam in core, and nothing else. Core never
sees a card number, a gateway API key, or an HTTP callback signature.

---

## 1. The model

Three concepts, in dependency order:

| Concept | Owner | What it is |
|---|---|---|
| **Order** | core record, plugin drives | An intent to pay: user, amount, currency, gateway, status |
| **Credits** | core | A signed integer balance per user, backed by an append-only ledger |
| **Entitlement** | core | A capability a user holds: `listing.slot` capacity, `listing.premium` until *date* |

The flow is always the same, and every arrow but one is inside core:

```
plugin sells  →  order paid  →  core mints credits  →  user spends credits
                                                    →  core grants entitlement
                                                    →  core enforces at one choke point
```

A gateway plugin implements one interface and reports one thing: *this order is paid*. It
never learns what a premium listing is, what a post quota is, or how either is enforced.
Conversely, core's features are testable end to end with no gateway installed — an admin
granting credits by hand exercises the identical path.

### Why credits sit in the middle

Credits are the only unit core knows how to spend. Without them, every feature would need
its own price, its own currency handling and its own purchase flow, and every gateway
plugin would have to enumerate the features it supports.

They also make subscriptions fall out for free: a gateway with recurring billing simply
mints credits on each renewal webhook. Core needs no subscription engine, no dunning, no
proration — a lapsed subscription is just a user who stopped receiving credits.

---

## 2. Schema

Four new tables plus one column. All money is stored as **micros** — the value ×1,000,000
— matching the existing `t_item.i_price BIGINT` convention. Credits are plain integers.
Never a float, anywhere.

### `t_billing_wallet`

Cached balance, one row per user. The ledger is the source of truth; this row exists to
keep balance reads off a `SUM()` and to serve as the lock target for atomic debits.

```
fk_i_user_id  INT UNSIGNED  PK
i_balance     BIGINT        NOT NULL DEFAULT 0
dt_mod_date   DATETIME      NOT NULL
```

### `t_billing_ledger`

Append-only. Never updated, never deleted — a refund is a new negative row. This is the
audit trail an admin uses when a user disputes a balance.

```
pk_i_id            INT UNSIGNED  PK AUTO_INCREMENT
fk_i_user_id       INT UNSIGNED  NOT NULL
i_amount           BIGINT        NOT NULL        -- signed: credit positive, debit negative
i_balance_after    BIGINT        NOT NULL
s_reason           VARCHAR(32)   NOT NULL        -- purchase|spend|refund|grant|revoke|expire
s_ref_type         VARCHAR(32)   NULL            -- order|item|user
i_ref_id           INT UNSIGNED  NULL
s_idempotency_key  VARCHAR(191)  NULL UNIQUE
dt_date            DATETIME      NOT NULL
INDEX (fk_i_user_id, dt_date)
```

`s_idempotency_key` carries the unique index deliberately. Gateways retry webhooks; a
retried callback must insert nothing rather than double-credit. This is the single most
common defect in hand-rolled payment integrations and the schema should make it
unrepresentable, not merely unlikely.

### `t_billing_order`

```
pk_i_id           INT UNSIGNED  PK AUTO_INCREMENT
fk_i_user_id      INT UNSIGNED  NOT NULL
s_gateway         VARCHAR(64)   NOT NULL
s_external_ref    VARCHAR(191)  NULL             -- gateway's own id
i_amount          BIGINT        NOT NULL         -- micros
s_currency        CHAR(3)       NOT NULL
i_credits         INT UNSIGNED  NOT NULL         -- minted on payment
s_status          VARCHAR(16)   NOT NULL         -- pending|paid|failed|refunded|cancelled
s_meta            TEXT          NULL             -- JSON, plugin-owned
dt_date           DATETIME      NOT NULL
dt_paid_date      DATETIME      NULL
UNIQUE (s_gateway, s_external_ref)
INDEX (fk_i_user_id, s_status)
INDEX (s_status, dt_date)
```

The `(s_status, dt_date)` index exists for the admin orders screen (§6): three of its four
summary counts filter on `s_status` alone, and `search()`'s default listing orders by
`dt_date DESC, pk_i_id DESC` — both table scans without it once an install has any real
order volume.

### `t_user_entitlement`

```
pk_i_id         INT UNSIGNED  PK AUTO_INCREMENT
fk_i_user_id    INT UNSIGNED  NOT NULL
s_feature       VARCHAR(64)   NOT NULL           -- registry id
i_quantity      INT           NULL               -- NULL = unlimited
dt_expiration   DATETIME      NULL               -- NULL = never
s_source        VARCHAR(32)   NOT NULL           -- purchase|grant|plan|default
dt_date         DATETIME      NOT NULL
UNIQUE (fk_i_user_id, s_feature)
INDEX (dt_expiration)
```

`uq_user_feature` is what makes `Entitlements::grant()` a single atomic
`INSERT … ON DUPLICATE KEY UPDATE` rather than a SELECT-then-UPDATE: two concurrent
purchases of the same feature can no longer race each other into losing one grant, because
MySQL applies the merge itself. It is also what keeps the one-row-per-`(user, feature)`
invariant every reader here (`has()`, `quantity()`, `capacity()`) already assumed true —
before the key existed, nothing enforced it.

### `t_item.dt_first_pub_date DATETIME NULL`

Set once, at insert, by `ItemActions::add()`, and never again — `item.bump` moves
`dt_pub_date` on purpose, to resort a listing to the top of every "newest first" query,
and would silently erase the record of when the listing first went live if the two
shared a column. Nothing in this layer reads the column back: the listing quota (§13)
counts live rows through `dt_expiration`, not publish history. It stays anyway — a bump
is a one-way trip, and once `dt_pub_date` moves there is no other record of the original
publish date left to recover, and a nullable, unindexed datetime costs nothing to keep
on the chance a future feature needs it.

### `t_item.dt_premium_expiration DATETIME NULL`

The one schema gap that blocks everything else. `b_premium` today is a boolean with no end
date, so "featured for 30 days" cannot be expressed and premium can only ever be granted
permanently by an admin.

This is a **column on `t_item`, not a join table**, on purpose. `Item::…` builds the
listing-visibility predicate — `(b_premium = 1 || dt_expiration >= now)` — into every
search, category and home query. Adding a joined table there would put a JOIN on the
hottest query in the product. A nullable indexed datetime folds into the existing
predicate for free.

Generalised item upgrades (bump-to-top, highlight, extra photos) come later as their own
table, because none of them sit in the visibility predicate.

### `t_item_upgrade`

One table for every item upgrade, present and future — `s_upgrade` is a registry id, so a
new one is a row, never a schema change.

```
pk_i_id       INT UNSIGNED  PK AUTO_INCREMENT
fk_i_item_id  INT UNSIGNED  NOT NULL
s_upgrade     VARCHAR(64)   NOT NULL           -- registry id: item.bump, item.highlight, ...
dt_expiration DATETIME      NULL               -- NULL = never lapses
dt_date       DATETIME      NOT NULL
UNIQUE (fk_i_item_id, s_upgrade)
INDEX (dt_expiration)
FOREIGN KEY (fk_i_item_id) REFERENCES t_item (pk_i_id) ON DELETE CASCADE
```

The unique key is the point: one row per `(item, upgrade)`, extended on repurchase rather
than duplicated. Cascades with the item, unlike the ledger and orders — an upgrade on a
deleted listing means nothing. See §3 for what the three built-in upgrades do with it.

---

## 3. Contracts

### `PaymentGateway`

The whole plugin-facing surface. `mindstellar\billing\PaymentGateway`:

```php
interface PaymentGateway
{
    public function getId(): string;                  // 'stripe', 'offline'
    public function getName(): string;                // display name
    public function getSupportedCurrencies(): array;  // ['USD', 'EUR']
    public function isConfigured(): bool;             // credentials present and usable

    /** Begin payment: a redirect URL or a block of HTML to render. */
    public function createCheckout(Order $order): CheckoutIntent;

    /** Handle a webhook or return-URL hit. Signature verification lives here. */
    public function handleCallback(array $request): CallbackResult;
}
```

`CallbackResult` carries `{orderId, status, externalRef, amount, currency}`.

**Core re-verifies `amount` and `currency` against the stored order before fulfilling, and
refuses the callback on mismatch.** A callback is attacker-reachable input; treating its
numbers as authoritative is how tampered-IPN bugs get shipped. Signature verification is
the plugin's job — only the plugin knows the gateway's scheme — but amount verification is
core's, because every plugin author forgets it.

### `PaymentGatewayRegistry`

Singleton, matching the shape already used by `WidgetRegistry`, `FieldTypeRegistry` and
`PageTemplateRegistry` — `instance()`, `register()`, `get()`, `all()`, `isValidId()`.
Plugins register on `init`.

### Classes

All under `mindstellar\billing` (`oc-includes/osclass/classes/billing/`):

| Class | Role |
|---|---|
| `PaymentGateway` | The interface plugins implement |
| `PaymentGatewayRegistry` | `instance()` / `register()` / `get()` / `all()` / `available()` |
| `CheckoutIntent` | `redirect(url)` or `html(markup)` |
| `CallbackResult` | `paid()` / `failed()` / `refunded()` / `ignored()` |
| `Order` | Immutable payment intent |
| `Orders` | Persistence for `t_billing_order` |
| `Wallet` | `balance()` / `credit()` / `debit()` / `reverse()` / `history()` |
| `Billing` | `checkout()` / `handleCallback()` / `markPaid()` / `refund()` / `spend()` |
| `Premium` | `expire()` — the sweep behind the hourly cron |
| `Feature` | One registered feature spec — `price()` / `duration()` / `apply()` |
| `FeatureRegistry` | `instance()` / `register()` / `get()` / `all()` / `isValidId()` — what credits can be spent on |
| `Entitlements` | `grant()` / `has()` / `quantity()` / `capacity()` / `consume()` / `canPublish()` — what a user holds |
| `ItemUpgrades` | `grant()` / `active()` / `has()` / `expiresAt()` / `prime()` / `purge()` — what an item holds |
| `Packages` | Persistence for `t_billing_package` — the price list a buyer chooses from at checkout |
| `gateway\OfflineGateway` | Core's reference `PaymentGateway`: bank transfer, settled by hand |

`Wallet::reverse()` is the chargeback path and is deliberately not `debit()`: by the time a
provider claws a payment back the user has usually spent what it bought, so a reversal that
refused to overdraw would simply not happen and the site would have given the goods away. A
negative balance is the honest record, and it blocks further spending until settled.

**`Billing::refund()` reverses credits and nothing else.** It marks the order `refunded`
and calls `Wallet::reverse()` for the credits it minted; it does not touch a single
entitlement, item upgrade, or premium flag those credits bought. A seller who spent the
credits before the refund lands keeps the highlight, the extra listing, the featured spot
— whatever `apply()` already granted stays granted. This is a deliberate policy, not an
oversight: unwinding a purchase's *effects* would mean walking every feature's own
`apply()` in reverse (un-feature a listing mid-lifecycle, claw back a bump nobody
remembers, shrink a photo cap out from under photos already uploaded), each with its own
failure modes, for a path (chargebacks, refund requests) that is already the exceptional
case. A refund therefore costs the site both the money and whatever the credits bought —
the same trade `Wallet::reverse()`'s negative balance already makes, extended to the goods
side of the same transaction. A site that wants stricter behaviour (e.g. revoking a
still-live upgrade on refund) has to build it as a `billing_order_refunded` hook.

### `FeatureRegistry`

Singleton, matching `PaymentGatewayRegistry`'s own shape so the two registries read the
same to a plugin author. Core registers the built-ins on load; a plugin adds its own, or
replaces one of core's by registering the same id again — that is how a site overrides a
built-in feature's price or effect.

```php
FeatureRegistry::instance()->register('listing.premium', array(
    'label'       => 'Featured listing',
    'description' => '',                             // optional
    'consumes'    => Feature::CONSUMES_DURATION,      // ::CONSUMES_QUANTITY | ::CONSUMES_DURATION | ::CONSUMES_CAPACITY
    'scope'       => Feature::SCOPE_ITEM,             // ::SCOPE_USER (default) | ::SCOPE_ITEM
    'price'       => 0,                               // int, or callable(): int
    'duration'    => 0,                               // int, or callable(): int; quantity features leave this at 0
    'apply'       => function (int $userId, array $ctx): bool { /* … */ },
));
```

`Billing::spend()` resolves `price` (and `duration`, for a duration feature) through the
`billing_feature_price` (and `billing_feature_duration`) filter before charging, so a site
can reprice a feature — including one of core's own — without touching this array. Both
filters are told which user the resolution is for (`null` when none is in scope yet), so a
plan or an admin acting on someone's behalf can be priced correctly instead of always
reading the currently logged-in user.

`scope` marks whether a feature is spent against the buyer's own account (`SCOPE_USER`,
the default) or against one of the buyer's items (`SCOPE_ITEM`). It exists so the public
`upgrade` route (§7) can build an allow-list of feature ids it may spend on behalf of an
item id taken from the request — a feature that never declares `SCOPE_ITEM` is simply
unreachable through that route, no matter what the request names.

`consumes = Feature::CONSUMES_CAPACITY` is a third kind, alongside quantity (spent down by
`consume()`) and duration (expires): a ceiling that is *read*, never spent. Buying "10
photos" does not mean ten uploads against a shrinking balance; it means the cap is 10 for as
long as the entitlement is live. `Entitlements::capacity(int $userId, string $feature, int
$default = 0): int` returns the **larger** of `$default` and the biggest `i_quantity` among
the user's unexpired rows for that feature — `$default` is a floor, not merely the answer
for "no row at all". A capacity entitlement can therefore only ever *raise* a seller's
limit, never lower it: without the floor, a global cap of 20 plus a bought entitlement of
10 would leave the paying seller worse off than a seller who bought nothing, and a global
cap read as unlimited by the caller's own convention (`0`, for `osc_max_images_per_item()`)
would be capped outright by the very upsell meant to raise it. A row with `i_quantity IS
NULL` (unlimited) returns `-1` unconditionally — it already beats any finite `$default` —
and every caller must treat `-1` as unlimited rather than compare it numerically, or
unlimited reads as "less than everything". A capacity feature's own `apply` grants the
entitlement (`Entitlements::grant()`), the same as a quantity or duration feature's does;
nothing calls `consume()` on a capacity row, and nothing should. The grant itself carries
no duration — see "Seller limits" below for what that means on repurchase.

Five more ship registered conditionally — each only when its own `billing_<name>_enabled`
preference is on, so a disabled feature is absent from the registry entirely, not merely
free or unpriced. `listing.slot` and `listing.premium` follow this rule via their own
`osc_register_billing_slot()` / `osc_register_billing_premium()` (the admin Pricing save
re-runs both); the item upgrades below share `osc_register_billing_item_upgrades()` (the
admin Upgrades save re-runs it):

| Feature | Consumes | Scope | Effect |
|---|---|---|---|
| `listing.slot` | capacity | user | Raises the seller's listing-slot ceiling (§13) — never spent, read back through `capacity()` |
| `listing.premium` | duration | item | `ItemActions::premium($itemId, true, $days)` — featured for `billing_premium_days` days |
| `item.bump` | quantity | item | Sets `dt_pub_date = NOW()` — moves the listing to the top of every "newest first" query — and grants an `item.bump` row expiring `billing_bump_cooldown_hours` hours out, which **is** the cooldown |
| `item.highlight` | duration | item | Grants an `item.highlight` row expiring `billing_highlight_days` days out |
| `item.urgent` | duration | item | Grants an `item.urgent` row expiring `billing_urgent_days` days out |

All three persist through `ItemUpgrades` (`oc-includes/osclass/classes/billing/ItemUpgrades.php`),
backed by `t_item_upgrade` — one row per `(item, upgrade)`, extended on repurchase rather
than duplicated, thanks to a unique key on that pair. Deliberately not a JSON column on
`t_item`: the expiry sweep needs an indexed `dt_expiration`, and two upgrades bought on one
listing at once would be a read-modify-write race on a shared blob. Deliberately not in
`t_item` at all, unlike `dt_premium_expiration` — none of the three sit in the
listing-visibility predicate every search/category/home query runs, so a join table costs
nothing on that hot path. `ItemUpgrades::prime(array $itemIds)` batch-loads a request-scoped
cache so a theme helper called inside a listing loop costs one query per page rather than
one per item; `active()`/`has()`/`expiresAt()` read that cache when an id was primed and
fall back to a fresh single-item query otherwise, so they are correct even when nothing was
ever primed. Two helpers called back to back on the same *unprimed* item (e.g.
`osc_item_is_highlighted()` then `osc_item_is_urgent()`) still cost one query between them,
not two — the single-item fallback memoizes its own read into the same cache `prime()`
fills, keeping "primed with no rows" distinct from "never looked up".

`osc_prime_item_upgrades(array $items): void` is the theme-facing entry point —
`ItemUpgrades::prime()` itself is not exported by name, so a theme with no `osc_*` helper
to reach it had no way to batch at all. It accepts item rows or bare ids in the same call,
whichever a theme already has to hand, and is a no-op while `osc_billing_enabled()` is off,
so a site that never turned billing on pays nothing for it. Core calls it itself wherever a
result set is built (search, category and home listings) so the common case needs no theme
change; a theme rendering its own custom loop should call it too, once, before the loop —
calling it per item defeats the point.

Every one of the three ships disabled, and *enabled* and *credits* are deliberately separate
preferences: an enabled upgrade priced at 0 credits is free to every seller, not switched
off. `listing.premium` follows the identical rule via `billing_premium_enabled` — this is
now the one rule that holds for every purchasable feature core ships, with no exception.

Every `apply` above requires `$ctx['itemId']` and does **not** check ownership — that is
the caller's job (the public `upgrade` route, §7), because a feature only knows how to
apply itself once asked to, not who is allowed to ask.

### Seller limits

Three more features, user-scoped, each an optional entitlement over a limit that is
otherwise a single global preference. Same conditional-registration pattern as the item
upgrades — each only when its own `billing_<name>_enabled` preference is on
(`osc_register_billing_seller_limits()` in `hBilling.php`; the admin Seller limits save
re-runs it) — and every one ships disabled, so an upgraded install behaves identically
until an admin turns one on:

| Feature | Consumes | Raises | Preference it overrides |
|---|---|---|---|
| `listing.photos` | capacity | Photos allowed per listing | `osc_max_images_per_item()` (`numImages@items`) |
| `listing.no_wait` | duration | Skips the flood wait entirely while held | `osc_items_wait_time()` |
| `listing.runtime` | capacity | Extra days of listing runtime, on top of the category's `i_expiration_days` ceiling | The category expiration ceiling |

**A capacity grant is permanent, and a repurchase adds rather than renews.** Both
`apply` callables above call `Entitlements::grant()` with `$days = null`, so the row's
`dt_expiration` is never set and the entitlement never lapses on its own — there is no
"30-day photo cap" the way `listing.highlight` has a 30-day highlight. Buying the raised
cap a second time does not restart a clock; it adds another `osc_billing_photos_quantity()`
on top of whatever the seller already holds (`i_quantity = i_quantity + …`, the same merge
`Entitlements::grant()` uses everywhere), so five separate 10-photo purchases leave a
seller holding 50, forever, not 10 with a later expiry. `Entitlements::capacity()` still
floors at the global default rather than reading this figure as an absolute cap (above), so
none of this can ever go backwards either. Price a capacity feature with this in mind: it
is a one-way, permanent upsell, not a subscription.

These are *raised ceilings*, not new limits: nothing changes for a seller who holds none of
the three, and the global preference is exactly what an upgraded site enforces until an
admin turns one on. Themes and plugins read the entitlement-aware siblings, never the
entitlement directly:

```php
osc_max_images_for_user(?int $userId = null): int      // -1 = unlimited
osc_items_wait_time_for_user(?int $userId = null): int  // 0 while listing.no_wait is held
osc_item_extra_runtime_days(?int $userId = null): int   // 0 with no listing.runtime entitlement
```

Each falls back to the plain preference helper (`osc_max_images_per_item()` /
`osc_items_wait_time()`) whenever billing is off, no user is given, or the user holds
nothing — so on a site selling none of this, the new helper and the old one always agree.
`osc_max_images_per_item()` and `osc_items_wait_time()` themselves are unchanged and must
stay that way: third-party themes and plugins call them directly and their return values
are a compatibility contract core does not control.

Enforcement lives in three places, all in `ItemActions`: the photo cap in
`uploadItemResources()` (resolved from the item's own owner, not the session, so an admin
uploading on a seller's behalf applies the seller's allowance), the flood wait in `add()`
(guests always get the global wait — this is an anti-flood control and anonymous posting
has no entitlements), and the category expiration ceiling in `prepareData()` (the admin path
and the "keep the old expiration on edit" path are both untouched by this).

---

## 4. Enforcement

Exactly one choke point, because a quota with two enforcement sites has none.

`Entitlements::canPublish(int $userId, array $ctx): bool` is consulted in
`ItemActions::add()`, beside the existing `pre_item_add` hook, and failure flows into the
same `$flash_error` that plugins already hook.

Nothing is consumed on a successful post. The quota is a slot ceiling
(`osc_billing_free_live_listings()` plus whatever `listing.slot` capacity a seller holds,
§13), not a balance — `withinFreeQuota()` already checked it before the insert, and there
is nothing left to spend afterwards. A listing occupies its slot simply by existing and
not having expired (`Entitlements::liveListings()`); it is freed the same way, by
expiring or being deleted, with no sweep and no bookkeeping on either side.

**Setting a limit never touches an existing listing.** Lowering
`billing_free_live_listings`, or a seller losing a bought `listing.slot` entitlement,
never expires, disables or deletes anything already live — it only changes whether the
*next* post is allowed. A seller who is already over a newly-lowered limit keeps every
listing they have; they simply cannot post another one until they free a slot themselves,
by deletion or expiry.

Premium fulfilment reuses `ItemActions::premium($id, $on)` unchanged. Everything
downstream of that method — search ranking, the premium home/category blocks, expiry
exemption, `i_num_premium_views`, the whole `hPremium.php` theme surface — already works
and is not touched by this layer.

### Calendar-correct expiry

Every duration this layer grants — a premium spot, a highlight, an entitlement's
`dt_expiration` — is computed with calendar arithmetic (`strtotime('+N days', $base)`),
never `$days * 86400` raw seconds: a 30-day purchase must expire 30 calendar days later
even when a daylight-saving transition falls in between, not an hour early or late. The one
deliberate exception is `item.bump`'s cooldown, which is specified in **hours**
(`billing_bump_cooldown_hours`) and stays literal elapsed seconds (`$hours * 3600`) on
purpose: "wait 24 real hours" must not itself drift across a clock change the way a
calendar-day duration should.

### Atomic debit

Two concurrent posts must not both spend the last credit. Use the conditional update, not
a read-then-write:

```sql
UPDATE t_billing_wallet SET i_balance = i_balance - ?
 WHERE fk_i_user_id = ? AND i_balance >= ?
```

`Connection::execute()` returns affected rows; zero means insufficient funds, and the
ledger row is only written when it returns one. The deduction and the ledger row are
wrapped in one transaction so neither can exist without the other, but the correctness of
the guard itself does not depend on the isolation level — unlike `SELECT … FOR UPDATE`
followed by a separate update.

---

## 5. Configuration

Preferences live in the `osclass` group with const keys, per the standardised layout.

| Key | Default | Meaning |
|---|---|---|
| `billing_enabled` | `0` | Master switch |
| `billing_free_live_listings` | `0` | Free listing slots per seller; 0 = unlimited |
| `billing_slot_enabled` | `0` | Whether buying an extra listing slot is registered as a feature at all |
| `billing_slot_credits` | `0` | Price of one `listing.slot` purchase |
| `billing_slot_quantity` | `1` | Slots granted per purchase |
| `billing_premium_enabled` | `0` | Whether featuring a listing is registered as a feature at all |
| `billing_premium_credits` | `0` | Price of a featured listing |
| `billing_premium_days` | `30` | Duration granted |
| `billing_currency` | `USD` | ISO 4217 code credits are priced in |
| `billing_offline_enabled` | `0` | Whether the bundled bank-transfer gateway is offered at checkout |
| `billing_offline_instructions` | *(empty)* | Admin-authored payment instructions shown at checkout; empty means the gateway offers itself nowhere, since there is nothing to tell a buyer to pay |
| `billing_bump_enabled` | `0` | Whether bump-to-top is registered as a feature at all |
| `billing_bump_credits` | `0` | Price of a bump |
| `billing_bump_cooldown_hours` | `24` | How long a listing must wait before it can be bumped again |
| `billing_highlight_enabled` | `0` | Whether highlighting is registered as a feature at all |
| `billing_highlight_credits` | `0` | Price of highlighting a listing |
| `billing_highlight_days` | `30` | Duration a highlight runs for |
| `billing_urgent_enabled` | `0` | Whether marking a listing urgent is registered as a feature at all |
| `billing_urgent_credits` | `0` | Price of marking a listing urgent |
| `billing_urgent_days` | `7` | Duration an urgent mark runs for |
| `billing_photos_enabled` | `0` | Whether a raised photo cap is registered as a feature at all |
| `billing_photos_credits` | `0` | Price of the raised cap |
| `billing_photos_quantity` | `10` | Photo cap granted while held |
| `billing_no_wait_enabled` | `0` | Whether waiving the flood wait is registered as a feature at all |
| `billing_no_wait_credits` | `0` | Price of the waiver |
| `billing_no_wait_days` | `30` | Duration the waiver holds once bought |
| `billing_runtime_enabled` | `0` | Whether extra listing runtime is registered as a feature at all |
| `billing_runtime_credits` | `0` | Price of the extra runtime |
| `billing_runtime_days` | `30` | Extra days over the category ceiling granted while held |

**Default off.** An existing install that upgrades sees no behaviour change whatsoever:
posting stays unlimited and free, premium stays admin-only. Nothing in this layer activates
until an admin turns it on.

---

## 6. Admin surface

- **Settings → Billing** — the master switch, a pricing section (free-quota size, listing
  price, currency, and featured-listing enabled/price/duration), an Upgrades section (enabled/price/duration for
  bump, highlight and urgent), a Seller limits section (enabled/price/quantity-or-days for
  the raised photo cap, the flood-wait waiver, and extra listing runtime), and the bundled
  bank-transfer gateway's own enable switch and instructions text
- **Billing → Packages** — the price list a buyer chooses from at checkout: name, price,
  credits, position, enabled
- **Billing → Orders** — list plus a per-order detail view with two hand-actions: mark an
  order paid (also the one path allowed to reopen a `failed` order — a provider retrying
  payment on the same order after an earlier failure is ordinary, and this is the admin
  confirming it by hand; the flash message says which happened, "marked paid" or "was
  failed and is now marked paid") and record a refund a provider has already made (core
  never calls out to request one — see the refund policy note in §3)
- **Billing → Credits** — every user's wallet balance, and a per-user page an admin uses to
  credit or debit it by hand, writing a `grant`/`revoke` ledger row. Not on the Users
  screen — this is its own menu item, hidden entirely (menu and all) while billing is off
- **Items** — the existing mark/unmark premium row action, unchanged; the row carries no
  expiry date of its own to show

## 7. Public routes

Everything a buyer does lives under `?page=billing`, and every one of those actions needs a
logged-in user except the one route a payment provider's own server hits directly:

| `action` | Method | Auth | What |
|---|---|---|---|
| *(default)* | GET | logged in | Wallet balance and ledger |
| `buy` | GET | logged in | Enabled packages plus the configured payment methods |
| `checkout` | POST, CSRF | logged in | Start paying for a package |
| `orders` | GET | logged in | The buyer's own past orders |
| `upgrade` | POST, CSRF | logged in | Apply an item-scoped feature (`feature`, default `listing.premium`) to one of the buyer's own listings (`itemId`) |
| `callback` | GET/POST | **none** — no session, no CSRF | A gateway's webhook or return-URL hit |

Every logged-in action checks `osc_billing_enabled()` first and bounces to the site root
with a flash error when it is off, so nothing downstream has to repeat that check.

**`upgrade` extends a live hold instead of refusing it.** A listing that already holds
the feature is not simply turned away: a duration feature (`listing.premium`,
`item.highlight`, `item.urgent`) still in force lets the purchase go through, and the
flash message says "extended" rather than "applied" on that path — because a seller
topping up a highlight before it lapses is a better outcome than making them wait it out
first. `item.highlight` and `item.urgent` genuinely compound: `ItemUpgrades::grant()`
extends from whichever is later, now or the row's current expiry, so the seller keeps
whatever time was left plus what they just bought. `listing.premium` does not — it goes
through `ItemActions::premium()` unchanged (§4), which always sets the expiration to a
flat `billing_premium_days` days from *now*, not from the current expiry. In the ordinary
case that still reads as "extended" (a fresh 30 days from now lands later than the few
days that were left), but a site that reconfigures `billing_premium_days` down after
sellers already hold longer premium spots should know a repurchase can shorten what they
already had, not just top it up. Two cases refuse outright regardless: a *permanent* hold
(an admin-granted `listing.premium` with no expiry, or any upgrade row with a `NULL`
`dt_expiration`) has nothing to extend, and `item.bump` — which consumes a quantity, not a
duration — keeps refusing while its cooldown row is live, because "extending" a bump would
mean re-bumping the listing on demand and defeating the cooldown's entire point.
`listing.premium`'s own already-held check reads `dt_premium_expiration`, not the
`b_premium` flag: the flag stays `1` from the moment a time-limited feature lapses until
the next hourly sweep (§8), and reading it alone would refuse to re-feature the listing
for that whole window.

`callback` is the deliberate exception, served by a separate controller
(`CWebBillingNonSecure`) rather than a branch of the logged-in one, precisely so it can
never accidentally pick up a CSRF check or a login redirect. `Billing::handleCallback()`
re-verifies the amount and currency against the stored order instead (§3), and the body is
the same short `OK` text for every outcome — success, failure, an unknown gateway, or an
order that does not exist — so the endpoint itself cannot be used to probe whether an order
is real.

**The status code is the one exception, and it is not about probing.** A callback core
never got to look at — no gateway registered, which is what happens when it arrives during
a window with billing switched off — answers **503**, not 200, so the provider's own retry
logic tries again later instead of marking a real payment "delivered" and never sending it
a second time. Every outcome core *did* resolve, including a deliberate ignore (an amount
mismatch, a cross-gateway attempt, a replay of an already-settled event), still answers
200 — replaying a decided outcome must not make a provider retry forever. `CallbackResult`
carries this as `isRetryable()`, decided once by whichever code path built it rather than
guessed from the reason string in the controller.

**The callback URL is a compatibility contract.** A gateway plugin gives this URL —
`?page=billing&action=callback&gateway=<id>` — to its payment provider once, often typed by
hand into a dashboard. Renaming the route, the `gateway` parameter, or the non-secure split
would silently break every already-configured gateway on every site that installed it, with
nothing failing loudly until the next payment does not settle. Treat it like an `osc_*`
helper: changing it is a breaking change, not a refactor.

## 8. Cron

One job: flip `b_premium` off where `dt_premium_expiration < NOW() AND b_premium = 1`, and
purge entitlement and item-upgrade rows whose own expiration has passed. All three are pure
housekeeping with no fulfilment side effects, so they share the existing hourly slot rather
than adding a second (`osc_expire_premium_items()` in `oc-includes/osclass/functions.php`).

## 9. Hooks

| Hook | Kind | Fired |
|---|---|---|
| `billing_order_paid` | action | Order transitions to paid, after credits are minted |
| `billing_order_refunded` | action | Reversal recorded |
| `billing_credits_changed` | action | Any ledger write |
| `billing_feature_applied` | action | `Billing::spend()` succeeds, after the feature's effect lands |
| `item_bumped` | action | `item.bump`'s `apply` succeeds, after `dt_pub_date` moves. Receives the item id, the same way `item_premium_on` does |
| `billing_can_publish` | filter | Veto or override the quota decision, on every call including billing-off |
| `billing_feature_price` | filter | Override a feature's credit price. Receives `($price, $featureId, $userId)` — `$userId` is who the price is being resolved for, and is `null` when no user is in scope (e.g. listing a feature's price with nobody buying yet) |
| `billing_feature_duration` | filter | Override a duration feature's granted days. Receives `($days, $featureId, $userId)`, same `$userId` convention as `billing_feature_price` |

---

## 10. Out of scope

Deliberately not in core, so the security and regulatory surface stays where it belongs:

- **Card data.** Never touched, never stored, never proxied. No PCI scope.
- **Subscription engine.** Recurring gateways mint credits per renewal webhook (§1).
- **Tax, VAT, invoicing.** The order stores enough for a plugin to generate an invoice.
- **Refund initiation.** Core records a refund the gateway reports; it never calls out to
  request one.

## 11. Delivery

Every phase is built: the wallet/ledger/orders substrate and gateway registry, then
entitlements and the listing-slot quota at the `ItemActions::add()` choke point, then the
feature registry and theme-facing helpers, then the bank-transfer reference gateway and the
package catalogue admins price against.

Phase 1 was the keystone — it is the part third-party plugins compile against, and it
shipped without changing any existing behaviour.

## 12. Compatibility

Every change here is **additive** — new tables via migration, new classes, new helpers, new
preference keys. No CSS class, `osc_*` helper or asset path is renamed or removed, so
existing third-party plugins and themes are unaffected.

Theme-facing helpers introduced in Phase 3 land in external theme repositories as well as
core, and need coordinating across those repos.

## 13. The listing quota

The free quota is a limit on how many of a seller's listings may be live at once — "N
listings live at once", not "N publications per M days". It prices shelf space, the way
a physical noticeboard would: a space is either taken or it is not, and it is freed the
moment the listing leaves, by expiry or by deletion. There is nothing to reset and
nothing to count over time; the ceiling is `osc_billing_free_live_listings()` plus
whatever `listing.slot` capacity a seller holds, and `Entitlements::liveListings()` is
just a `COUNT(*)` against `t_item` at the instant it is asked.

**What occupies a slot:** a listing occupies one from the moment it publishes until it
expires or is deleted — full stop. A listing pending moderation (`b_active = 0`) or
disabled by an admin (`b_enabled = 0`) still occupies a slot. This is the surprising
half, and it is deliberate: if a pending listing did not count, a seller could queue
fifty ads awaiting approval — each one free because none of them "count" yet — and flood
the site the instant every one is approved at once. Charging for the slot the moment the
listing is created, not the moment it becomes publicly visible, is what makes that
impossible. Expiry is the one thing that *does* free a slot for free, because an expired
listing is already gone from every page a visitor can reach; there is nothing left to
protect by continuing to charge for it.

**Setting a limit never touches an existing listing.** Lowering
`billing_free_live_listings`, or a seller losing a bought `listing.slot` entitlement,
never expires, disables or deletes anything already live — it only changes whether the
*next* post is allowed (§4). A seller who is already over a newly-lowered limit keeps
every listing they have; they simply cannot post another one until they free a slot
themselves, by deletion or expiry.

**What buys more:** `listing.slot` — a capacity entitlement that raises the ceiling
while held and is never spent, because a slot is a ceiling, not a balance. Nothing is
consumed on publish (§4); there is nothing there to consume.
