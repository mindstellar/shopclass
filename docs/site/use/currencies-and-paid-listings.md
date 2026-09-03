---
title: Currencies & paid listings
description: Set up currencies in ShopClass and sell featured listings, bumps, extra photos and listing slots through the built-in billing layer.
sidebar:
  order: 11
---

## Currencies

**Listings → Currencies** lists the currencies sellers can price in. Each has a
code, name and description; add the ones your market uses and delete the rest.

The list shows how many listings use each currency, so you can see what is safe
to remove. Deleting a currency that listings are priced in leaves those listings
without a valid one — check the count first.

A single-country site should offer exactly one. Every extra currency is another
decision on the publish form and another thing to compare in search results.

## Paid listings

**New in 6.2.0.** ShopClass can sell credits and charge for listings without a
payment gateway in core. It is **off by default** — nothing changes until you
turn it on.

The settings move once you switch it on: while billing is **off** they live at
**Settings → Billing**; enabling it promotes **Billing** to a top-level menu with
**Orders**, **Packages**, **Credits** and **Settings** under it, and removes the
entry from Settings. If you cannot find the screen you were just on, that is why.

The split matters: **core owns entitlements, plugins own money.** Core decides
what a seller is entitled to — a featured listing for fourteen days, three extra
photos, a bump — and a payment plugin decides how they paid for it. Core never
sees a card number or a gateway API key.

That is why upgrades are priced in **credits**: an abstract unit a payment
plugin sells, rather than a currency core would have to charge in.

### Turning it on

**Settings → Billing → Enable billing** is the master switch. Until a payment method is installed,
the screen tells you plainly: *No payment methods installed*, with **Browse
plugins** to find one. Each installed method shows as **Ready** or **Needs
setting up**.

**Bank transfer / cash** is built in and needs no plugin — you write your own
payment instructions and settle each order by hand once the money arrives, with
no card processor or API keys involved. Right for a local site with a
handful of paying sellers, and a way to test the whole flow before choosing a
gateway.

### What you can sell

| Upgrade | What the seller gets |
|---|---|
| **Featured listings** | Promoted placement, for a set number of days |
| **Highlight** | Visual emphasis in listing results, for a set number of days |
| **Urgent** | An urgency marker, for a set number of days |
| **Bump to top** | Back to the top of results, with a cooldown between bumps |
| **Extra photos** | A raised photo cap on one listing |
| **Extra runtime** | More days than the category's limit allows |
| **Extra listing slots** | More listings than the free allowance |
| **Skip the posting wait** | Waives the flood-control delay between posts |

Each is sold independently — turn on only what you actually want to sell — and
each has its own price in credits and, where relevant, its own duration.

### Credit bundles

**Billing → Packages** defines the bundles buyers choose from at checkout — how
much credit each contains and what it costs. Without at least one package there
is nothing for a buyer to purchase.

### What buyers see

Sellers get a **wallet** page with their credit balance and history, a page to
buy bundles, and a list of their past orders. On their own listings they get a
**Feature this listing** action that spends credits to run it as featured for a
set number of days.

Orders are visible to you at **Billing → Orders**, and balances at
**Billing → Credits**.

### Seller limits

**Free listings per seller** and the **photo cap** set what sellers get for
nothing. These are what make the upgrades worth buying: with unlimited free
listings and no photo cap, nobody needs extra slots or extra photos.

Set the free allowance to what a casual seller needs and a dealer does not.

### Before charging anyone

- Take a [backup](/docs/use/backups-and-maintenance/).
- Test the whole flow end to end with bank transfer first, including what a
  seller sees when their upgrade expires.
- Write your refund terms into your [Terms page](/docs/use/pages/) — the payment
  plugin will not do it for you.
- Confirm that expiry actually runs: entitlements expire on the **hourly cron**.
  Without [cron](/docs/configure/cron/), a featured listing stays featured
  forever and you have sold something you cannot take back.

### For developers

The contract between core and a payment plugin — what an entitlement is, how a
plugin grants one, and what core guarantees about it — is specified in
[`docs/BILLING.md`](https://github.com/mindstellar/shopclass/blob/master/docs/BILLING.md).
