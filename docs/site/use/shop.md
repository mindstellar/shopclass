---
title: Running a shop
description: "Turn on shop mode, add products with variants and stock, handle orders from paid to delivered, and see which plugins add shipping, tax, coupons, e-mail and analytics."
sidebar:
  order: 16
---

Shop mode turns ShopClass into a store. You add the products, shoppers fill a cart and
check out, and you ship the orders. It needs a theme made for shops, such as Aisle.

## 1. Turn it on

1. **Settings → General → Site is a**: pick **Shop**. In shop mode only admins add products.
   A shop also sells digital goods. Credit packages, credits and paying to publish are hidden
   in a shop and come back when you switch to classifieds. A classifieds site keeps the cart,
   orders and payment plugins too, but its billing **Orders** tab shows only the unpaid-order
   cancel days and the order number prefix.
2. **Settings → Billing**: switch **Enable billing** on and set your currency.
3. **Settings → Billing → Shop** (called **Orders** on a classifieds site): set the delivery charge, the amount that makes delivery free,
   cash on delivery and its fee, how long unpaid orders wait before they are cancelled, how long a
   card payment holds the stock, and the order number prefix.
   **Cash on delivery only in** limits cash on delivery to the countries, regions or cities you pick
   (a buyer's address must be inside one); empty offers it everywhere you deliver.
   **Store address** (country, region, city) is used for a product with no location of its own.
   **Product weights in** picks grams and kilograms or ounces and pounds for every weight field.
4. **Appearance**: activate the shop theme. If it asks for plugins, one screen installs and turns
   them on for you.
5. Install a payment method (see below), or use the built-in bank transfer and cash on delivery.

## 2. Add a product

Add a product as an admin. The form is split into sections. The **On this page** list in
the side column jumps to each one.

- **Product**: name, category and description.
- **Photos**.
- **Price and stock**: the price and currency. Switch on **Sell online** so buyers can add the
  product to a cart, then set the old price, SKU and stock. With it off, buyers see the price and
  contact you.
- **Options**: for sizes or colours (see below). Shown once **Sell online** is on.
- **Shipping**: weight and shipping class. Shown once **Sell online** is on. A product in a
  digital category has no shipping; it is delivered as a download.
- **Details**: the fields of the chosen category, such as custom fields and downloadable files.
- **Location**: optional and folded away. Leave it empty and the product uses the store address.
- **Seller and contact**: folded away; it opens by itself if something in it needs fixing.
- **Publishing** (side column): status, dates and expiry.

**Save** stores the product; **Cancel** goes back to the list.

For a product that comes in sizes or colours, add **option rows**: one row per combination, such
as Black / M, each with its own SKU, price, stock and an optional old price, shown crossed out.
The shopper picks the options on the product page.

- **Stock** is counted per row. At 0 the row is out of stock. Leave it blank to not count.
- **Weight** and **Shipping class** are for the Shipping plugin. Weight is typed in grams, or in
  ounces when **Product weights in** is ounces and pounds.
- Every order takes the stock when it is placed. A shopper cannot order the last unit someone else
  holds. A card order still unpaid after **Hold stock for card payments (minutes)** is cancelled
  and its stock goes back on sale.

## 3. Orders

**Billing → Orders** lists every order. Open one to see its items, address and buyer.

A number beside **Billing** and **Orders** counts the orders waiting to ship, and the dashboard lists the five oldest.

| Status | Meaning |
|---|---|
| **Pending** | Placed, not paid. Bank transfer and cash on delivery orders wait here. Any pending order can be cancelled. |
| **Paid** | Money received. It shows in **To ship** until you send it. |
| **Processing** | You are preparing it. Set it with **Start processing**, or it starts when the first parcel ships. A cash on delivery order can start before it is paid. It stays in **To ship**. |
| **Shipped** | Every item has shipped. You marked it shipped with a carrier and tracking number (you can edit them later; shipping with none asks you to confirm). Cash on delivery is shipped before it is paid. |
| **Delivered** | You marked it delivered. A cash on delivery order is marked paid now. |
| **Cancelled** | Closed before payment, by you, the buyer, or because a card order was not paid within the hold minutes. The stock goes back on sale. |
| **Refunded** | The money went back. The stock goes back on sale. |
| **Failed** | The payment provider reported a failed card payment. The order is closed and its stock goes back on sale. |

To ship an order in parts, open it and use **Ship this order** (**Send another parcel** after the first): set the quantity in **Send now** for each line and add the carrier and tracking. The order stays **Processing** until the last item ships, then becomes **Shipped**. Each parcel keeps its own tracking, which you can edit. **Mark packed** is a note for you; it changes no status and sends no mail. The order page also lists its status history. Orders from before 7.1 have none.

### Invoices and credit notes

Every paid order gets a numbered invoice, such as `INV-2026-00042`. Each refund gets a credit note, such as `CN-2026-00003`, with its own numbers. Both restart on 1 January. The buyer opens them from the order in their account and from the receipt mail.

**Settings → Billing → Receipts** turns invoices off and sets the two prefixes. **Billing → Invoices** lists them by type and date, with a CSV download for your accountant. A document is frozen when it is made: later changes to the order or to your business details do not alter it. Orders paid before 7.1 have no invoice.

### Refunding part of an order

Open the order and use **Refund part**. Enter an amount, or the units to refund, and a reason, and choose whether the units go back on sale (on by default for items not yet shipped). If the payment plugin supports it, the money goes back through the provider; otherwise the refund is recorded and you send the money yourself. Each partial refund makes a credit note and a mail to the buyer. The order shows **Partly refunded** until the credit notes add up to what was paid, then it becomes **Refunded**. Refund part needs an invoice: an order without one, such as one paid before 7.1 or while invoices were off, can only be refunded in full. A credit pack order is refunded whole.

A card payment that arrives after its order was cancelled is refunded by the payment plugin.

Unpaid bank transfer and cash on delivery orders are cancelled after **Cancel unpaid orders after (days)**. A
shopper may hold at most the number of open unpaid bank transfer orders you allow. Cash on delivery and card orders are not limited.

## 4. The order mails

The buyer is e-mailed when an order is:

1. **placed** (bank transfer and cash on delivery, before payment),
2. **paid**, with the receipt and invoice link,
3. **part shipped**, one mail per parcel with its items and tracking,
4. **shipped**, when the last item goes, with the carrier and tracking link,
5. **delivered**,
6. **cancelled**,
7. **partly refunded**, with the credit note link,
8. **refunded**.

You get a "New order" mail for each order. A mail that cannot be sent waits on the job queue and
is tried again, once per order and kind. Developers change the wording with the `MailComposing`
event; see [Order mails](/docs/developers/hooks/#order-mails).

## 5. Plugins that add to the shop

Each one is free, works on plain shared hosting and does nothing until you set it up.

| Plugin | What it adds |
|---|---|
| **Shipping** | Delivery zones by country, state or postcode, Standard and Express, weight prices, free delivery, delivery dates and tracking links. |
| **Tax** | Tax lines from a default, country and category rates, prices with or without tax, and a tax invoice. |
| **Tax GST** | India GST on top of Tax: CGST and SGST in your state, IGST elsewhere, GSTIN and HSN on the invoice. |
| **Promotions** | Coupon codes, automatic discounts, a first-order offer and use limits. |
| **Shop emails** | A newsletter sign-up list with double opt-in and CSV export, and one reminder for a signed-in shopper's left cart. |
| **Analytics** | Google Analytics 4 and Meta Pixel shop events, with a consent bar. Nothing loads before the visitor accepts. |
| **Digital Goods** | Downloadable files for a product, with links in the receipt. |
| **Stripe**, **PayPal**, **Razorpay** | Card and wallet payments. Buyers pay on the provider's page. The installer offers all three; none is ticked by default. |

## What shoppers see in their account

A signed-in shopper gets a buyer account: the dashboard with their recent orders, Your
orders, profile, password and delete account. The seller pages (listings, public profile,
alerts, credits and API access) are hidden, and a link to one leads to the dashboard. With
Digital Goods on, a Downloads entry lists what they bought. An admin signed in sees everything.

**Addresses** keeps up to 10 delivery addresses. The default one is also the account's own
address. At checkout the saved addresses show as cards with the default chosen; "Use a new
address" opens the form, and "Save this address" (ticked) adds it to the book. The form follows the country: it asks for a state or county only where people write one, names the postcode field as that country does (Postcode, ZIP code, PIN code) and checks its shape. State is a list of the site's regions for that country, or a text box when there are none. When the site has only one country, the Country box is hidden. Phone numbers may start with `+` and a country code. The first address
saved becomes the default. A classifieds site has no Addresses page.

## 6. Customise

If your theme supports it, **Appearance → Customise** edits the home page sections and the look
beside a live preview. Nothing shows to visitors until you press **Publish**. See
[Customise your theme](/docs/use/themes-and-widgets/#customise-your-theme).

## Before you open

- Place a test order with bank transfer, then ship and deliver it, and read each mail.
- Check that [cron](/docs/configure/cron/) or the auto-cron runs: hold days, retries and reminders
  depend on it.
- Write your delivery and refund terms into your [Terms page](/docs/use/pages/).
- For the money side, see [currencies and paid listings](/docs/use/currencies-and-paid-listings/).
