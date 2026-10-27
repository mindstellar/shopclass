<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\api\schema;

use mindstellar\billing\CartPricer;
use mindstellar\billing\event\CartProblems;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;

/**
 * The component schemas of the cart quote and the account's orders. Schema lists them with its
 * own.
 */
final class OrderSchema
{
    /** Every component name, known without building a schema. */
    public const NAMES = [
        'Amount', 'OrderAddress', 'OrderAddressInput', 'OrderLineInput', 'OrderInput',
        'OrderLine', 'OrderGateway', 'Order', 'OrderNext',
        'OrderDocument', 'OrderPage', 'OrderPlaced',
        'QuoteLine', 'QuoteMethod', 'QuoteAdjustment', 'QuoteTax', 'QuoteTotals', 'QuoteGateway', 'QuoteProblem',
        'Quote', 'QuoteDocument',
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function components(): array
    {
        $text   = static fn (int $max, int $min = 0): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $maybe  = static fn (array $schema, string $description = ''): array => ['type' => [$schema['type'], 'null']] + $schema + ($description === '' ? [] : ['description' => $description]);
        $amount = Schema::ref('Amount');
        $money  = Schema::object([
            'amount'    => ['type' => 'string'],
            'currency'  => ['type' => 'string'],
            'formatted' => ['type' => 'string'],
        ], ['amount', 'currency', 'formatted']);
        $addr = [];
        foreach (GoodsCheckout::ADDRESS as $field => $max) {
            $addr[$field] = $text($max);
        }
        $photo = $maybe(Schema::object([
            'id'        => ['type' => 'integer'],
            'thumbnail' => ['type' => 'string'],
            'preview'   => ['type' => 'string'],
            'normal'    => ['type' => 'string'],
        ], ['id', 'thumbnail', 'preview', 'normal']), 'The listing\'s first photo.');
        $sold = $maybe(Schema::object([
            'id'    => ['type' => 'integer'],
            'url'   => ['type' => 'string'],
            'photo' => $photo,
        ], ['id', 'url', 'photo']), 'The listing the line sold, while it is live.');
        $shipping = $maybe(Schema::object([
            'method'       => Schema::nullable('string'),
            'label'        => Schema::nullable('string'),
            'address'      => $maybe(Schema::object($addr, array_keys($addr))),
            'carrier'      => Schema::nullable('string'),
            'tracking'     => Schema::nullable('string'),
            'tracking_url' => Schema::nullable('string', 'https only.'),
            'shipped_at'   => Schema::time(),
            'delivered_at' => Schema::time(),
        ], ['method', 'label', 'address', 'carrier', 'tracking', 'tracking_url', 'shipped_at', 'delivered_at']), 'Null for a credits order.');

        return [
            'Amount' => $money + ['description' => 'An amount: a decimal string, its currency code and the site\'s formatting.'],

            'OrderAddress'      => Schema::object($addr, array_keys($addr)),
            'OrderAddressInput' => Schema::object($addr + [
                'id'   => ['type' => 'string', 'maxLength' => 16, 'description' => 'One of your saved addresses (GET account/addresses); the other fields are then ignored.'],
                'save' => ['type' => 'boolean', 'description' => 'true: add this typed address to your address book once the order is placed, unless it is there or the book is full. It must then pass the address book\'s checks.'],
            ]) + ['description' => 'Send id, or the address with every field but line2 when the cart ships.'],
            'OrderLineInput'    => Schema::object([
                'variant' => ['type' => 'integer', 'minimum' => 1],
                'qty'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => CartPricer::MAX_QTY],
            ], ['variant', 'qty']) + ['description' => 'A variant and a quantity. A price sent with it is ignored.'],
            'OrderInput' => Schema::object([
                'lines'           => ['type' => 'array', 'minItems' => 1, 'maxItems' => CartPricer::MAX_LINES, 'items' => Schema::ref('OrderLineInput')],
                'address'         => Schema::ref('OrderAddressInput'),
                'shipping_method' => $text(64, 1),
                'gateway'         => $text(64, 1),
                'note'            => $text(500),
                'coupon'          => $text(200) + ['description' => 'A coupon code, read as GET cart/quote reads it.'],
            ], ['lines', 'address', 'shipping_method', 'gateway']) + ['description' => 'Prices, totals and stock are worked out on the server; an amount sent is ignored.'],

            'OrderLine' => Schema::object([
                'kind'    => ['type' => 'string', 'enum' => OrderLineStore::KINDS],
                'title'   => ['type' => 'string'],
                'options' => Schema::nullable('object', 'Option name => value, for an item line.'),
                'sku'     => Schema::nullable('string'),
                'qty'     => ['type' => 'integer'],
                'unit'    => $amount,
                'amount'  => $amount,
                'listing' => $sold,
                'included' => ['type' => 'boolean', 'description' => 'True on a tax line for tax already in the prices: its amount is 0 and its unit is the tax.'],
            ], ['kind', 'title', 'options', 'sku', 'qty', 'unit', 'amount', 'listing', 'included']) + ['description' => 'A discount line\'s amount is negative.'],
            'OrderGateway' => Schema::object(['id' => ['type' => 'string'], 'name' => ['type' => 'string']], ['id', 'name']),
            'Order'        => Schema::object([
                'id'          => ['type' => 'integer'],
                'number'      => ['type' => 'string', 'description' => 'What the buyer sees: "#1042".'],
                'kind'        => ['type' => 'string', 'enum' => ['goods', 'credits']],
                'label'       => ['type' => 'string', 'description' => 'What the order is for: "Tee x 2 and 1 more".'],
                'status'      => ['type' => 'string', 'enum' => Order::STATUSES],
                'placed_at'   => Schema::time(),
                'paid_at'     => Schema::time(),
                'total'       => $amount,
                'lines'       => ['type' => 'array', 'items' => Schema::ref('OrderLine')],
                'shipping'    => $shipping,
                'gateway'     => Schema::ref('OrderGateway'),
                'receipt_url' => Schema::nullable('string', 'The printable receipt, once the order is paid.'),
                'can_cancel'  => ['type' => 'boolean', 'description' => 'True while the buyer may cancel it: a pending bank transfer or cash on delivery order.'],
                'pay_instructions' => Schema::nullable('string', 'How to pay a pending bank transfer order: the shop\'s instructions and the reference to quote.'),
                'can_pay'     => ['type' => 'boolean', 'description' => 'True while the buyer may start paying again: a pending card order.'],
            ], ['id', 'number', 'kind', 'label', 'status', 'placed_at', 'paid_at', 'total', 'lines', 'shipping', 'gateway', 'receipt_url', 'can_cancel', 'pay_instructions', 'can_pay']),
            'OrderNext' => Schema::object([
                'kind'    => ['type' => 'string', 'enum' => ['redirect', 'html']],
                'payload' => ['type' => 'string', 'description' => 'redirect: the URL to send the buyer to. html: the gateway\'s own markup, to print as it is.'],
            ], ['kind', 'payload']),
            'OrderDocument' => Schema::object(['data' => Schema::ref('Order')], ['data']),
            'OrderPage'     => Schema::wholeList('Order'),
            'OrderPlaced'   => Schema::object(['data' => Schema::ref('Order'), 'next' => Schema::ref('OrderNext')], ['data', 'next']),

            'QuoteLine' => Schema::object([
                'variant'    => ['type' => 'integer'],
                'qty'        => ['type' => 'integer'],
                'listing'    => Schema::object([
                    'id'    => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'url'   => ['type' => 'string'],
                    'photo' => $photo,
                ], ['id', 'title', 'url', 'photo']),
                'options'    => Schema::nullable('object', 'Option name => value.'),
                'unit'       => $amount,
                'amount'     => $amount,
                'available'  => ['type' => 'boolean', 'description' => 'False when there is not enough in stock; the line then stays out of the totals.'],
                'stock_left' => Schema::nullable('integer', 'Set when stock is tracked and 5 or fewer are left, or fewer than asked for.'),
            ], ['variant', 'qty', 'listing', 'options', 'unit', 'amount', 'available', 'stock_left']),
            'QuoteMethod' => Schema::object([
                'id'     => ['type' => 'string'],
                'label'  => ['type' => 'string'],
                'eta'    => Schema::nullable('string'),
                'amount' => $amount,
            ], ['id', 'label', 'eta', 'amount']),
            'QuoteAdjustment' => Schema::object([
                'id'     => ['type' => 'string'],
                'label'  => ['type' => 'string'],
                'amount' => $amount,
            ], ['id', 'label', 'amount']) + ['description' => 'A discount\'s amount is negative.'],
            'QuoteTax' => Schema::object([
                'id'       => ['type' => 'string'],
                'label'    => ['type' => 'string'],
                'amount'   => $amount,
                'included' => ['type' => 'boolean', 'description' => 'True when the tax is already in the prices: shown, not added to the total.'],
            ], ['id', 'label', 'amount', 'included']),
            'QuoteTotals' => Schema::object([
                'subtotal'     => $amount,
                'shipping'     => $amount,
                'discount'     => $amount + ['description' => 'What comes off, not negative.'],
                'tax'          => $amount + ['description' => 'Tax added on top of the prices.'],
                'tax_included' => $amount + ['description' => 'Tax already in the prices; the total does not add it again.'],
                'fee'          => $amount,
                'total'        => $amount + ['description' => 'What the gateway charges.'],
            ], ['subtotal', 'shipping', 'discount', 'tax', 'tax_included', 'fee', 'total']),
            'QuoteGateway' => Schema::object([
                'id'   => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'fee'  => $maybe($money, 'What paying this way adds; null for none.'),
            ], ['id', 'name', 'fee']),
            'QuoteProblem' => Schema::object([
                'line'    => Schema::nullable('integer', 'The position in the request\'s lines; null for a problem with the address, coupon or delivery.'),
                'code'    => ['type' => 'string', 'pattern' => '^[a-z_]{1,32}$', 'description' => 'For a line: not_buyable, out_of_stock or qty. Plugins add their own, such as coupon_expired.'],
                'pointer' => ['type' => ['string', 'null'], 'enum' => array_merge(CartProblems::POINTERS, [null]), 'description' => 'The field a plugin\'s problem is about; null for a line problem.'],
                'message' => Schema::nullable('string', 'What to show the shopper, for a plugin\'s problem; null for a line problem.'),
            ], ['line', 'code', 'pointer', 'message']),
            'Quote' => Schema::object([
                'currency'         => ['type' => 'string'],
                'lines'            => ['type' => 'array', 'items' => Schema::ref('QuoteLine')],
                'needs_shipping'   => ['type' => 'boolean', 'description' => 'False when every line is a download: no address, delivery or cash on delivery.'],
                'shipping_methods' => ['type' => 'array', 'items' => Schema::ref('QuoteMethod')],
                'shipping'         => $maybe(Schema::object(['method' => ['type' => 'string'], 'amount' => $amount], ['method', 'amount']), 'The chosen method.'),
                'discounts'        => ['type' => 'array', 'items' => Schema::ref('QuoteAdjustment')],
                'taxes'            => ['type' => 'array', 'items' => Schema::ref('QuoteTax')],
                'fees'             => ['type' => 'array', 'items' => Schema::ref('QuoteAdjustment')],
                'totals'           => Schema::ref('QuoteTotals'),
                'free_over'        => $maybe(Schema::object(['amount' => $amount, 'remaining' => $amount], ['amount', 'remaining']), 'Free delivery from this subtotal, and how much is left to reach it.'),
                'gateways'         => ['type' => 'array', 'items' => Schema::ref('QuoteGateway')],
                'problems'         => ['type' => 'array', 'items' => Schema::ref('QuoteProblem')],
            ], ['currency', 'lines', 'needs_shipping', 'shipping_methods', 'shipping', 'discounts', 'taxes', 'fees', 'totals', 'free_over', 'gateways', 'problems']),
            'QuoteDocument' => Schema::object(['data' => Schema::ref('Quote')], ['data']),
        ];
    }
}
