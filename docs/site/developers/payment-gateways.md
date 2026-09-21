---
title: Payment gateways
description: Take payments in ShopClass by registering a gateway, and give it a declared settings page — walked through with the bundled Test Payments plugin.
sidebar:
  order: 22
---

Core sells credits, keeps the wallet and applies upgrades. It never touches money.
A **gateway plugin** does one job: take the payment and report back "this order
is paid". Core then mints the credits.

The bundled **Test Payments** plugin (`oc-content/plugins/test-gateway/`) is a
complete gateway that moves no money. Read it next to this page. It has three
files that matter:

| File | Shows |
|---|---|
| `TestGateway.php` | The `PaymentGateway` implementation |
| `settings.php` | A declared settings page |
| `index.php` | The wiring: registration, routes, admin notice |

## Registering a gateway

Implement `mindstellar\billing\PaymentGateway` and register it on `init`, while
billing is on:

```php
use mindstellar\billing\PaymentGatewayRegistry;

osc_add_hook('init', static function () {
    if (osc_billing_enabled()) {
        PaymentGatewayRegistry::instance()->register(new AcmePayGateway());
    }
});
```

The interface has six methods:

| Method | Returns |
|---|---|
| `getId()` | A permanent slug stored on every order, e.g. `'test'` |
| `getName()` | The label at checkout and in the admin |
| `getSupportedCurrencies()` | ISO 4217 codes. A malformed one makes `register()` throw |
| `isConfigured()` | `false` keeps it listed in the admin but off the checkout |
| `createCheckout(Order $order)` | A `CheckoutIntent`: a redirect, or markup to show |
| `handleCallback(array $request)` | A `CallbackResult`: paid, failed, refunded or ignored |

### Checkout

The order is already saved as pending when `createCheckout()` runs, so its id is
safe to hand to the provider. Test Payments redirects to its own hosted page:

```php
public function createCheckout(Order $order): CheckoutIntent
{
    return CheckoutIntent::redirect(self::checkoutUrl($order->getId()));
}
```

Return `CheckoutIntent::html($markup)` instead to render in place, as core's bank
transfer gateway does. Core prints that markup unescaped, so escape everything in it.

### The callback

Providers post to one core route:

```
index.php?page=billing&action=callback&gateway=<your id>
```

It has no session and no CSRF token, so **anyone can post to it**. Your
`handleCallback()` must prove the request came from the provider. Test Payments
signs every field with HMAC-SHA256 and refuses anything else:

```php
public function handleCallback(array $request): CallbackResult
{
    if (!self::enabled()) {
        return CallbackResult::ignored('test payments are switched off');
    }
    $fields = self::parse($request);            // shape of every field
    if ($fields === null) {
        return CallbackResult::ignored('malformed callback');
    }
    if (!hash_equals(self::sign($fields), (string) $request['sig'])) {
        return CallbackResult::ignored('bad signature');
    }
    if (abs(time() - (int) $fields['ts']) > self::window()) {
        return CallbackResult::ignored('callback expired');
    }

    // Declined and refunded are handled here too; see the file.
    // Echo the provider's own figures. Core compares them with the order.
    return CallbackResult::paid((int) $fields['order'], $fields['ref'], (int) $fields['amount'], $fields['currency']);
}
```

A real provider gives you the secret. Test Payments has none, so it derives a key
from the install's signing key:

```php
hash_hmac('sha256', 'test-gateway-callback', \mindstellar\security\SigningKey::get());
```

### What core checks for you

After your gateway accepts a callback, `Billing::handleCallback()`:

- refuses a **paid** result whose amount or currency differs from the order — but
  only if you pass them, so always pass them;
- refuses an order that belongs to another gateway;
- settles an order only while it is pending, and keys the credit on the order,
  so a **replayed** callback credits nothing;
- lets only a paid order be refunded.

Core does **not** compare money on a failed or refunded result. Check those
yourself if the provider sends figures, as `TestGateway::handleCallback()` does.

Return `CallbackResult::ignored()` for pings, duplicates and anything you refuse.
The route answers `200 OK` with the same body for every decision, so a response
never reveals whether an order exists.

### Pages of your own

The test checkout page and its buttons are [controller routes](/docs/developers/routes/#controller-routes).
Two things to know:

- A route hook request does not run `init`, so register the gateway again inside
  the handler.
- Check the buyer owns the order, and call `osc_csrf_check()` on every POST.

**Pay**, **Decline** and **Refund** pass a signed payload to `Billing::handleCallback()` —
the same call the callback route makes. **Leave pending** and **Fail with error** send
nothing. The page can also print a `curl` command that posts that
payload to the real route, for replay tests.

## Declaring its settings page

`settings.php` returns the spec; `index.php` registers it:

```php
osc_register_settings_page(TestGateway::PAGE, require __DIR__ . '/settings.php');
```

Core draws the form, checks CSRF and capability, validates, saves under the
`test-gateway` preference section and adds **Plugins → Test payments**. The plugin
has no form markup and no save handler.

It uses five field types. The parts worth copying, labels and help left out:

```php
array('type' => 'checkbox', 'name' => 'enabled'),
array('type' => 'text', 'name' => 'name', 'default' => __('Test payment', 'test-gateway'), 'maxlength' => 60,
      'depends' => 'enabled', 'required' => true),
array('type' => 'select', 'name' => 'mode', 'default' => 'choose',
      'options' => array('choose' => __('Test checkout page', 'test-gateway'), 'auto' => __('Instant, no page', 'test-gateway'))),
array('type' => 'radio', 'name' => 'auto_outcome', 'default' => 'paid',
      'options' => array('paid' => __('Paid', 'test-gateway'), 'declined' => __('Declined', 'test-gateway'), 'pending' => __('Left pending', 'test-gateway')),
      'depends' => 'mode', 'depends_value' => 'auto'),
array('type' => 'number', 'name' => 'window', 'suffix' => __('minutes', 'test-gateway'),
      'min' => 1, 'max' => 1440, 'default' => 30, 'required' => true),
```

- `depends => 'enabled'` hides *Name* and ignores its submitted value while test mode is off.
- `depends_value => 'auto'` shows *Outcome* only for that one select value.
- The *Currencies* field adds `sanitize` and `validate` callables for a rule no
  type covers.

Read values anywhere, typed by field:

```php
if (osc_settings_value('test-gateway', 'enabled')) {       // bool
    $minutes = osc_settings_value('test-gateway', 'window'); // int
}
```

Point the plugin list's **Configure** link at the page, and delete the values on
uninstall:

```php
use mindstellar\settings\SettingsPageRegistry;

osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url('test-gateway'));
});

osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', static function () {
    foreach (array_keys(SettingsPageRegistry::instance()->fields('test-gateway')) as $name) {
        osc_delete_preference($name, 'test-gateway');
    }
});
```

## Trying it

1. Turn billing on and add a credit package under **Billing → Packages**.
2. Install **Test Payments**, open **Plugins → Test payments** and tick *Test mode*.
3. As a user, buy a package with the test payment method.
4. Press **Pay**, **Decline**, **Leave pending** or **Fail with error**. A paid order
   shows **Refund** on the same page.
5. Check **Billing → Orders** and the order's credit movements.

An admin notice stays on every admin page while test mode is on.
`tests/test-gateway.php` covers the signature, age, amount, replay and
switched-off checks against a real database.
