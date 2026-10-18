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
        'QuoteLine', 'QuoteMethod', 'QuoteAdjustment', 'QuoteTotals', 'QuoteGateway', 'QuoteProblem',
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
            'OrderAddressInput' => Schema::object($addr, array_values(array_diff(array_keys($addr), ['line2']))),
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
            ], ['kind', 'title', 'options', 'sku', 'qty', 'unit', 'amount', 'listing']) + ['description' => 'A discount line\'s amount is negative.'],
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
                'can_cancel'  => ['type' => 'boolean', 'description' => 'True while the order is pending.'],
            ], ['id', 'number', 'kind', 'label', 'status', 'placed_at', 'paid_at', 'total', 'lines', 'shipping', 'gateway', 'receipt_url', 'can_cancel']),
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
            'QuoteTotals' => Schema::object([
                'subtotal' => $amount,
                'shipping' => $amount,
                'discount' => $amount + ['description' => 'What comes off, not negative.'],
                'tax'      => $amount,
                'fee'      => $amount,
                'total'    => $amount + ['description' => 'What the gateway charges.'],
            ], ['subtotal', 'shipping', 'discount', 'tax', 'fee', 'total']),
            'QuoteGateway' => Schema::object([
                'id'   => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'fee'  => $maybe($money, 'What paying this way adds; null for none.'),
            ], ['id', 'name', 'fee']),
            'QuoteProblem' => Schema::object([
                'line' => ['type' => 'integer', 'description' => 'The position in the request\'s lines.'],
                'code' => ['type' => 'string', 'enum' => ['not_buyable', 'out_of_stock', 'qty']],
            ], ['line', 'code']),
            'Quote' => Schema::object([
                'currency'         => ['type' => 'string'],
                'lines'            => ['type' => 'array', 'items' => Schema::ref('QuoteLine')],
                'shipping_methods' => ['type' => 'array', 'items' => Schema::ref('QuoteMethod')],
                'shipping'         => $maybe(Schema::object(['method' => ['type' => 'string'], 'amount' => $amount], ['method', 'amount']), 'The chosen method.'),
                'discounts'        => ['type' => 'array', 'items' => Schema::ref('QuoteAdjustment')],
                'taxes'            => ['type' => 'array', 'items' => Schema::ref('QuoteAdjustment')],
                'fees'             => ['type' => 'array', 'items' => Schema::ref('QuoteAdjustment')],
                'totals'           => Schema::ref('QuoteTotals'),
                'free_over'        => $maybe(Schema::object(['amount' => $amount, 'remaining' => $amount], ['amount', 'remaining']), 'Free delivery from this subtotal, and how much is left to reach it.'),
                'gateways'         => ['type' => 'array', 'items' => Schema::ref('QuoteGateway')],
                'problems'         => ['type' => 'array', 'items' => Schema::ref('QuoteProblem')],
            ], ['currency', 'lines', 'shipping_methods', 'shipping', 'discounts', 'taxes', 'fees', 'totals', 'free_over', 'gateways', 'problems']),
            'QuoteDocument' => Schema::object(['data' => Schema::ref('Quote')], ['data']),
        ];
    }
}
