---
title: Tax per line and checkout fields
description: "How a tax plugin reports tax for each item and shipping line, how core checks it, and how it asks the buyer for a tax number at checkout."
sidebar:
  order: 25
---

A tax plugin does two things in shop mode: it adds the order's tax lines, and it can say which part of
each tax line belongs to which item. Core uses the second to put a Tax column on invoices and to refund
the right tax on a credit note. It can also ask the buyer for fields such as a tax number.

Everything here needs Shopclass 7.1.0.beta4 or newer. Guard it with `method_exists` and `class_exists`;
older cores keep working with order-level tax only.

## Order tax lines stay the source of the totals

Add tax lines on the `cart_tax_lines` event (`CartTaxLines`) as before, one per rate and part, for example
`tax.cgst.9`. The line tax below is only a breakdown of them.

## Line tax

Describe one item or shipping line with `mindstellar\billing\LineTax` and hand it over with `setLineTax()`:

```php
use mindstellar\billing\LineTax;

osc_add_hook('cart_tax_lines', static function ($event) {
    if (!method_exists($event, 'setLineTax')) {
        return;
    }
    $event->setLineTax('item', 0, new LineTax(
        false,          // included in the price?
        100000000,      // taxable base, in micros
        array(
            array('tax' => 'tax.cgst.9', 'label' => 'CGST 9%', 'bp' => 900, 'amount' => 9000000),
            array('tax' => 'tax.sgst.9', 'label' => 'SGST 9%', 'bp' => 900, 'amount' => 9000000),
        ),
        '6109'          // optional tax code, such as an HSN
    ));
});
```

- `kind` is `item` (ref is the cart position) or `shipping` (ref is the shipping line id).
- A line has 1 to 4 parts. `tax` is the id of the order tax line the part belongs to. `bp` is 0 to 10000.
  The label is at most 60 characters and the code at most 40.
- `LineTax::$amount` is the sum of the parts. A bad shape throws `InvalidArgumentException`.

### The sum check

Before writing the order, core checks every tax line: the parts that name it, over all item and shipping
lines, must add up to that line's amount (its `unit` when the tax is included), and `included` must match.
If anything is off, core drops all line tax for that order and logs one `E_USER_WARNING`. The order still
places with its tax lines, and invoices and refunds use proportional tax, as before. Money never depends on
a plugin's unchecked sum.

The checked line tax is stored in each order line's meta under `tax`, and frozen into the invoice. Orders
made before this feature keep proportional tax.

## Refunds

A credit note that returns units of a line takes that line's own tax, per part. Returning 1 of 3 units
takes a third; the note that returns the last unit takes the exact rest. A note for a typed amount only,
or for an order without line tax, splits tax in proportion to the amount, as before. A tax line never goes
below zero.

## Checkout fields

Ask the buyer for extra details on the `checkout_fields` event (`CheckoutFields`). It fires while the cart
is priced, so a field can depend on the buyer's country.

```php
osc_add_hook('checkout_fields', static function ($event) {
    $event->addField('tax_id', __('Tax number'), false, 20, '', true, 'off');
});
```

`addField($id, $label, $required, $max, $help, $invoice, $autocomplete)`. The id is `[a-z0-9_]{1,32}`.
At most 8 fields are kept. `invoice` decides whether the value prints under Billed to on the invoice.

The quote lists the fields as `checkout_fields`. The theme sends the values as `fields` when it places the
order. Unknown ids are dropped, values are plain text up to 100 characters, and a missing required field
fails with a `required` error at `/fields/<id>`.

Check or tidy a value on the `checkout_starting` event (`CheckoutStarting`):

```php
osc_add_hook('checkout_starting', static function ($event) {
    $id = strtoupper(trim($event->field('tax_id')));
    if ($id !== '' && !preg_match('/^[A-Z0-9]{4,20}$/', $id)) {
        $event->refuse('/fields/tax_id', 'invalid', __('Check the tax number.'));
        return;
    }
    $event->setField('tax_id', $id);
});
```

The values are stored on the order as `s_meta._fields` (`{id: {label, value, invoice}}`, empty values
left out) and shown on the order in the API as `fields`. Core only checks the format a plugin asks for; it
never calls an outside tax service.

## In the API

- The quote has `checkout_fields` and, on each line, `tax` (`included`, `base`, `amount`, `rate_text`,
  `parts`) or `null`.
- An order has `fields` and the same `tax` on its lines.

See [Hooks and filters](/docs/developers/hooks/) for the event reference.
