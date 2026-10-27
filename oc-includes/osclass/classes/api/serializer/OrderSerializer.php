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

namespace mindstellar\api\serializer;

use mindstellar\billing\CartPricer;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Quote;
use mindstellar\billing\Receipts;
use mindstellar\currency\CurrencyService;

/**
 * A priced cart and the account's orders as the API sends them. Every amount is
 * `{amount, currency, formatted}`; the stored micros never leave as numbers.
 */
final class OrderSerializer
{
    /** @var array<string,string> currency code => the symbol the site shows */
    private array $symbols = array();

    public function __construct(private Links $links, private ListingSerializer $listings, private CurrencyService $currencies)
    {
    }

    /**
     * An amount in micros.
     *
     * @return array{amount:string,currency:string,formatted:string}
     */
    public function amount(int $micros, string $currency): array
    {
        $currency = strtoupper($currency);
        if (!isset($this->symbols[$currency])) {
            $row                      = $this->currencies->find($currency);
            $symbol                   = Format::text($row['s_description'] ?? null);
            $this->symbols[$currency] = $symbol ?? $currency;
        }

        return array(
            'amount'    => (string) Format::amount($micros),
            'currency'  => $currency,
            'formatted' => $this->links->price($micros, $this->symbols[$currency]),
        );
    }

    /**
     * What buyers call an order: the id with the shop's optional prefix, "#1042".
     */
    public static function number(int $orderId): string
    {
        return osc_billing_order_number($orderId);
    }

    /**
     * The quote as the cart shows it.
     *
     * @param array<int,array{id:string,name:string,fee:int}> $gateways the payment methods on offer, each with its fee in micros
     *
     * @param array{count:int,limit:int}|null                 $openOrders  the signed-in buyer's unpaid bank transfer orders; null for a guest
     *
     * @return array<string,mixed>
     */
    public function quote(Quote $quote, array $gateways, ?array $openOrders = null): array
    {
        $currency = $quote->currency();
        $money    = fn (int $micros): array => $this->amount($micros, $currency);
        $lines    = array();
        foreach ($quote->lines() as $line) {
            $listing = $line['listing'];
            $lines[] = array(
                'variant'    => (int) $line['variant'],
                'qty'        => (int) $line['qty'],
                'listing'    => array(
                    'id'    => (int) $listing['id'],
                    'title' => (string) $listing['title'],
                    'url'   => $this->links->listing($listing['item']),
                    'photo' => $this->photo($listing['photo']),
                ),
                'options'    => $line['options'],
                'unit'       => $money((int) $line['unit']),
                'amount'     => $money((int) $line['amount']),
                'available'  => (bool) $line['available'],
                'stock_left' => $line['stock_left'],
            );
        }
        $adjust = static fn (array $rows): array => array_map(static fn (array $row): array => array(
            'id'     => (string) $row['id'],
            'label'  => (string) $row['label'],
            'amount' => $row['amount'],
        ), $rows);
        $withAmount = static fn (array $rows) => array_map(static fn (array $row): array => array('amount' => $money((int) $row['amount'])) + $row, $rows);
        $totals     = $quote->totals();
        $chosen     = $quote->shipping();
        $freeOver   = $quote->freeOver();

        return ($openOrders === null ? array() : array('open_orders' => array('count' => $openOrders['count'], 'limit' => $openOrders['limit']))) + array(
            'currency'         => $currency,
            'lines'            => $lines,
            'needs_shipping'   => $quote->needsShipping(),
            'shipping_methods' => array_map(static fn (array $m): array => array(
                'id'     => (string) $m['id'],
                'label'  => (string) $m['label'],
                'eta'    => $m['eta'],
                'amount' => $money((int) $m['amount']),
            ), $quote->shippingMethods()),
            'shipping'         => $chosen === null ? null : array('method' => (string) $chosen['id'], 'amount' => $money((int) $chosen['amount'])),
            'discounts'        => $adjust($withAmount($quote->extraLines(OrderLineStore::KIND_DISCOUNT))),
            'taxes'            => array_map(static fn (array $row): array => array(
                'id'       => (string) $row['id'],
                'label'    => (string) $row['label'],
                'amount'   => $money((int) $row['amount']),
                'included' => ($row['included'] ?? false) === true,
            ), $quote->extraLines(OrderLineStore::KIND_TAX)),
            'fees'             => $adjust($withAmount($quote->extraLines(OrderLineStore::KIND_FEE))),
            'totals'           => array_map($money, array(
                'subtotal'     => $totals['subtotal'],
                'shipping'     => $totals['shipping'],
                'discount'     => $totals['discount'],
                'tax'          => $totals['tax'],
                'tax_included' => $totals['tax_included'],
                'fee'          => $totals['fee'],
                'total'        => $totals['total'],
            )),
            'free_over'        => $freeOver === null ? null : array('amount' => $money($freeOver['amount']), 'remaining' => $money($freeOver['remaining'])),
            'gateways'         => array_map(static fn (array $g): array => array(
                'id'   => $g['id'],
                'name' => $g['name'],
                'fee'  => $g['fee'] > 0 ? $money($g['fee']) : null,
            ), $gateways),
            'problems'         => array_map(static fn (array $p): array => array(
                'line'    => $p['line'],
                'code'    => $p['code'],
                'pointer' => $p['pointer'],
                'message' => $p['message'],
            ), array_values($quote->problems())),
        );
    }

    /**
     * Orders as the API sends them, with their lines and the listings' current links in
     * three queries however many orders there are.
     *
     * @param Order[] $orders
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function orders(array $orders): array
    {
        $goods = array();
        foreach ($orders as $order) {
            if ($order->isGoods()) {
                $goods[] = $order->getId();
            }
        }
        $lines = OrderLineStore::ofOrders($goods);
        $refs  = $this->listingRefs($lines);

        return array_map(fn (Order $order): array => $this->order($order, $lines[$order->getId()] ?? array(), $refs), $orders);
    }

    /**
     * @param array<int,array<string,mixed>>                          $lines the order's stored lines
     * @param array<int,array{id:int,url:string,photo:array|null}>    $refs  live listings by id
     *
     * @return array<string,mixed>
     */
    private function order(Order $order, array $lines, array $refs): array
    {
        $currency = $order->getCurrency();
        $out      = array();
        foreach ($lines as $line) {
            $item  = $line['kind'] === OrderLineStore::KIND_ITEM;
            $meta  = is_array($line['meta'] ?? null) ? $line['meta'] : array();
            $out[] = array(
                'kind'    => (string) $line['kind'],
                'title'   => (string) $line['title'],
                'options' => $item && is_array($line['options'] ?? null) && $line['options'] !== array() ? $line['options'] : null,
                'sku'     => $item && ($line['sku'] ?? '') !== '' ? (string) $line['sku'] : null,
                'qty'     => (int) $line['qty'],
                'unit'    => $this->amount((int) $line['unit'], $currency),
                'amount'  => $this->amount((int) $line['amount'], $currency),
                'listing' => $item && !empty($line['item_id']) ? ($refs[(int) $line['item_id']] ?? null) : null,
                'included' => $line['kind'] === OrderLineStore::KIND_TAX && ($meta['included'] ?? false) === true,
            );
        }
        $gateway = PaymentGatewayRegistry::getInstance()->get($order->getGateway());

        return array(
            'id'          => $order->getId(),
            'number'      => self::number($order->getId()),
            'kind'        => $order->isGoods() ? 'goods' : 'credits',
            'label'       => $order->getLabel(),
            'status'      => $order->getStatus(),
            'placed_at'   => Format::time($order->getDate()),
            'paid_at'     => Format::time($order->getPaidDate()),
            'total'       => $this->amount($order->getAmount(), $currency),
            'lines'       => $out,
            'shipping'    => self::shipping($order),
            'gateway'     => array('id' => $order->getGateway(), 'name' => $gateway === null ? $order->getGateway() : $gateway->getName()),
            'receipt_url' => Receipts::available($order) ? Receipts::url($order->getId()) : null,
            'can_cancel'  => $order->buyerMayCancel(),
            'pay_instructions' => PaymentGatewayRegistry::getInstance()->instructions($order),
            'can_pay'     => $order->canPayAgain() && $gateway !== null && $gateway->isConfigured(),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function shipping(Order $order): ?array
    {
        $f = $order->fulfilment();
        if ($f === null) {
            return null;
        }
        $address = null;
        if (is_array($f['address'])) {
            $address = array();
            foreach (array_keys(\mindstellar\billing\GoodsCheckout::ADDRESS) as $field) {
                $address[$field] = (string) ($f['address'][$field] ?? '');
            }
        }
        $url = $f['tracking_url'];

        return array(
            'method'       => $f['method'],
            'label'        => $f['label'],
            'address'      => $address,
            'carrier'      => $f['carrier'],
            'tracking'     => $f['tracking'],
            'tracking_url' => is_string($url) && str_starts_with(strtolower($url), 'https://') ? $url : null,
            'shipped_at'   => Format::time($f['shipped_at']),
            'delivered_at' => Format::time($f['delivered_at']),
        );
    }

    /**
     * The live listings these lines sold: id, current URL and first photo. A listing that is
     * gone or no longer live has no entry.
     *
     * @param array<int,array<int,array<string,mixed>>> $lines lines by order id
     *
     * @return array<int,array{id:int,url:string,photo:array<string,mixed>|null}>
     * @throws \mindstellar\database\DbException
     */
    private function listingRefs(array $lines): array
    {
        $ids = array();
        foreach ($lines as $set) {
            foreach ($set as $line) {
                if (!empty($line['item_id'])) {
                    $ids[(int) $line['item_id']] = true;
                }
            }
        }
        if ($ids === array()) {
            return array();
        }
        $rows   = CartPricer::listings(array_keys($ids), '');
        $photos = CartPricer::photos(array_keys($rows));
        $refs   = array();
        foreach ($rows as $id => $row) {
            $refs[$id] = array(
                'id'    => $id,
                'url'   => $this->links->listing(array(
                    'pk_i_id'          => $id,
                    's_title'          => $row['title'],
                    's_city'           => (string) ($row['s_city'] ?? ''),
                    'fk_i_category_id' => (int) $row['fk_i_category_id'],
                )),
                'photo' => $this->photo($photos[$id][0] ?? null),
            );
        }

        return $refs;
    }

    /**
     * @param array<string,mixed>|null $row a photo row
     *
     * @return array<string,mixed>|null
     */
    private function photo(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $photo = $this->listings->photos(array($row))[0];
        unset($photo['original']);

        return $photo;
    }
}
