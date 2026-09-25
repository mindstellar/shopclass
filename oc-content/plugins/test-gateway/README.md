# Test Payments

A payment gateway that moves no money. Use it to test credit packages, listing upgrades,
failed orders and refunds without a provider account. **Never enable it on a live site.**

It is also the worked example for two developer APIs:

- registering a payment gateway (`TestGateway.php`)
- declaring a settings page (`settings.php`)

See `docs/site/developers/payment-gateways.md` in the Shopclass repository.

## Use

1. Turn billing on and add a credit package (**Billing**).
2. Install and enable this plugin, then open **Plugins → Test payments** and tick **Offer test payments at checkout**.
3. As a user, buy a package and choose the test payment method.
4. On the test checkout page press **Pay**, **Decline**, **Leave pending** or **Fail with error**.
   A paid order can be refunded from the same page.

With **Checkout** set to **Instant, no page**, the outcome is applied at once, with no checkout page.

## How it verifies

Each button builds a callback signed with HMAC-SHA256 (a key derived from the install's
signing key) and hands it to `Billing::handleCallback()`, the same call core's webhook route
makes. The gateway refuses a bad signature, a callback older than the configured window, and
a refund or decline whose amount or currency differs from the order. Core refuses a paid
callback with the wrong amount or currency and never settles an order twice.

Tick **Show the signed callback on the checkout page** to get a `curl` command that posts to the real callback URL.
