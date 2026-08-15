# Billing & entitlements

Core owns **entitlements**. Plugins own **money**. This document specifies the contract
between them.

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
| **Entitlement** | core | A capability a user holds: `listing.publish` ×5, `listing.premium` until *date* |

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
```

### `t_user_entitlement`

```
pk_i_id         INT UNSIGNED  PK AUTO_INCREMENT
fk_i_user_id    INT UNSIGNED  NOT NULL
s_feature       VARCHAR(64)   NOT NULL           -- registry id
i_quantity      INT           NULL               -- NULL = unlimited
dt_expiration   DATETIME      NULL               -- NULL = never
s_source        VARCHAR(32)   NOT NULL           -- purchase|grant|plan|default
dt_date         DATETIME      NOT NULL
INDEX (fk_i_user_id, s_feature)
```

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
$default = 0): int` returns the largest `i_quantity` among the user's unexpired rows for
that feature, or `$default` when there is none. A row with `i_quantity IS NULL` (unlimited)
returns `-1` — every caller must treat `-1` as unlimited rather than compare it numerically,
or unlimited reads as "less than everything". A capacity feature's own `apply` grants the
entitlement (`Entitlements::grant()`), the same as a quantity or duration feature's does;
nothing calls `consume()` on a capacity row, and nothing should.

Core's one unconditional built-in (like the core widget and field types) — it exists and
is overridable whether or not billing is switched on:

| Feature | Consumes | Scope | Effect |
|---|---|---|---|
| `listing.publish` | quantity | user | Grants one more `listing.publish` entitlement — one extra listing beyond the free quota |

Four more ship registered conditionally — each only when its own `billing_<name>_enabled`
preference is on, so a disabled feature is absent from the registry entirely, not merely
free or unpriced. `listing.premium` follows this rule too, via its own
`osc_register_billing_premium()` (the admin Pricing save re-runs it); the item upgrades
below share `osc_register_billing_item_upgrades()` (the admin Upgrades save re-runs it):

| Feature | Consumes | Scope | Effect |
|---|---|---|---|
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
ever primed.

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
same `$flash_error` that plugins already hook. Consumption happens **after** a successful
insert, never before — a listing that fails validation must not burn a credit.

Premium fulfilment reuses `ItemActions::premium($id, $on)` unchanged. Everything
downstream of that method — search ranking, the premium home/category blocks, expiry
exemption, `i_num_premium_views`, the whole `hPremium.php` theme surface — already works
and is not touched by this layer.

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
| `billing_free_posts_per_period` | `0` | 0 = unlimited |
| `billing_period_days` | `30` | Quota window |
| `billing_publish_credits` | `1` | Price of one extra listing beyond the free quota |
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
- **Billing → Orders** — read-only list: user, gateway, amount, status, external ref
- **Users** — balance column, plus manual credit/debit writing a `grant`/`revoke` ledger row
- **Items** — the existing mark/unmark premium row action, now showing the expiry date

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

`callback` is the deliberate exception, served by a separate controller
(`CWebBillingNonSecure`) rather than a branch of the logged-in one, precisely so it can
never accidentally pick up a CSRF check or a login redirect. `Billing::handleCallback()`
re-verifies the amount and currency against the stored order instead (§3), and the response
is the same short `OK` body for every outcome — success, failure, an unknown gateway, or an
order that does not exist — so the endpoint itself cannot be used to probe whether an order
is real.

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

## 11. Phases

| Phase | Contents |
|---|---|
| **1** | Wallet, ledger, orders, `PaymentGateway` + registry, `dt_premium_expiration`, expiry cron, admin orders/balance UI. No enforcement. *Built.* |
| **2** | Entitlements, `listing.publish` quota, the `ItemActions::add()` choke point, settings. *Built.* |
| **3** | Feature registry generalisation; theme helpers (`osc_user_credits()`, buy/wallet pages). *Core's half is built: `FeatureRegistry`, the theme-facing helpers (`osc_user_credits()`, `osc_billing_packages()`, `osc_billing_wallet_url()`/`buy_url()`/`orders_url()`/`upgrade_url()`, `osc_item_can_be_featured()`), and core's own fallback wallet/buy/orders views under `oc-includes/osclass/gui/billing/`. Still open: the bundled theme is its own external repository, and does not yet ship its own `user-billing-*.php` templates — until it does, every site sees core's plain fallback rather than a themed one.* |
| **4** | Reference gateway: **offline / bank transfer**, plus the package catalogue admins price it against. No external dependency, no API keys — an admin marks the order paid. It proves the interface end to end and is genuinely useful for markets where card payment is not the norm. *Built.* |

Phase 1 is the keystone: it is the part third-party plugins compile against, and it ships
without changing any existing behaviour.

## 12. Compatibility

Every change here is **additive** — new tables via migration, new classes, new helpers, new
preference keys. No CSS class, `osc_*` helper or asset path is renamed or removed, so
existing third-party plugins and themes are unaffected.

Theme-facing helpers introduced in Phase 3 land in external theme repositories as well as
core, and need coordinating across those repos.
