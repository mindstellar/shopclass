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
| `Billing` | `checkout()` / `handleCallback()` / `markPaid()` / `refund()` |
| `Premium` | `expire()` — the sweep behind the hourly cron |

`Wallet::reverse()` is the chargeback path and is deliberately not `debit()`: by the time a
provider claws a payment back the user has usually spent what it bought, so a reversal that
refused to overdraw would simply not happen and the site would have given the goods away. A
negative balance is the honest record, and it blocks further spending until settled.

### `BillingFeatureRegistry`

*Phase 2 — not yet built.*

What credits can be spent on. Core registers the built-ins; plugins may add their own.

```php
[
  'id'      => 'listing.premium',
  'label'   => 'Featured listing',
  'consumes'=> 'duration',              // 'quantity' | 'duration'
  'apply'   => callable(int $userId, array $ctx): bool,
]
```

Built-ins core ships:

| Feature | Effect |
|---|---|
| `listing.publish` | One more listing allowed in the current period |
| `listing.premium` | `ItemActions::premium()` + `dt_premium_expiration` = now + N days |

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
| `billing_premium_credits` | `0` | Price of a featured listing |
| `billing_premium_days` | `30` | Duration granted |

**Default off.** An existing install that upgrades sees no behaviour change whatsoever:
posting stays unlimited and free, premium stays admin-only. Nothing in this layer activates
until an admin turns it on.

---

## 6. Admin surface

- **Settings → Billing** — the switches above
- **Billing → Orders** — read-only list: user, gateway, amount, status, external ref
- **Users** — balance column, plus manual credit/debit writing a `grant`/`revoke` ledger row
- **Items** — the existing mark/unmark premium row action, now showing the expiry date

## 7. Cron

One job: flip `b_premium` off where `dt_premium_expiration < NOW() AND b_premium = 1`.
Slots into the existing scheduled-cleanup infrastructure.

## 8. Hooks

| Hook | Kind | Fired |
|---|---|---|
| `billing_order_paid` | action | Order transitions to paid, after credits are minted |
| `billing_order_refunded` | action | Reversal recorded |
| `billing_credits_changed` | action | Any ledger write |
| `billing_can_publish` | filter | Veto or override the quota decision |
| `billing_feature_price` | filter | Override a feature's credit price |

---

## 9. Out of scope

Deliberately not in core, so the security and regulatory surface stays where it belongs:

- **Card data.** Never touched, never stored, never proxied. No PCI scope.
- **Subscription engine.** Recurring gateways mint credits per renewal webhook (§1).
- **Tax, VAT, invoicing.** The order stores enough for a plugin to generate an invoice.
- **Refund initiation.** Core records a refund the gateway reports; it never calls out to
  request one.

## 10. Phases

| Phase | Contents |
|---|---|
| **1** | Wallet, ledger, orders, `PaymentGateway` + registry, `dt_premium_expiration`, expiry cron. No enforcement. *Built; admin orders/balance UI still outstanding.* |
| **2** | Entitlements, `listing.publish` quota, the `ItemActions::add()` choke point, settings. |
| **3** | Feature registry generalisation; theme helpers (`osc_user_credits()`, buy/wallet pages). |
| **4** | Reference gateway: **offline / bank transfer**. No external dependency, no API keys — an admin marks the order paid. It proves the interface end to end and is genuinely useful for markets where card payment is not the norm. |

Phase 1 is the keystone: it is the part third-party plugins compile against, and it ships
without changing any existing behaviour.

## 11. Compatibility

Every change here is **additive** — new tables via migration, new classes, new helpers, new
preference keys. No CSS class, `osc_*` helper or asset path is renamed or removed, so
existing third-party plugins and themes are unaffected.

Theme-facing helpers introduced in Phase 3 land in external theme repositories as well as
core, and need coordinating across those repos.
