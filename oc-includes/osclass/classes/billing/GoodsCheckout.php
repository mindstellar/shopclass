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

namespace mindstellar\billing;

use mindstellar\database\Db;
use mindstellar\exception\InvalidException;
use mindstellar\user\UserStore;

/**
 * Turns a cart into a pending goods order and asks its gateway how to pay. The web and API
 * controllers both place through here. The cart is priced again on the server, so nothing
 * the browser sent reaches an amount. The order and its lines are one transaction; a gateway
 * that gives no checkout leaves the order cancelled, never pending. A bank transfer or cash on
 * delivery order takes its stock at placement (Billing::HOLDS_STOCK); any other takes it when paid.
 *
 * Fires `cart_checkout_before` ($cart) before the write and `billing_order_placed`
 * ($orderId, $userId) once the gateway has answered.
 */
final class GoodsCheckout
{
    /** Address fields an order takes, with their longest length. line2 may be empty. */
    public const ADDRESS = array(
        'name'     => 100,
        'phone'    => 40,
        'line1'    => 200,
        'line2'    => 200,
        'city'     => 100,
        'state'    => 100,
        'postcode' => 20,
        'country'  => 100,
    );

    private CartPricer $pricer;

    public function __construct(?CartPricer $pricer = null)
    {
        $this->pricer = $pricer ?? new CartPricer();
    }

    /**
     * Place the order.
     *
     * @param array<int,mixed>    $lines   {variant, qty} each
     * @param array<string,mixed> $address ADDRESS keys
     *
     * @return array{order:Order,next:CheckoutIntent}
     * @throws InvalidException with `/lines/n` (not_buyable, out_of_stock, qty), `/lines` (empty),
     *                          `/address/<field>`, `/shipping_method` (unknown) or `/gateway` (unavailable)
     * @throws \mindstellar\database\DbException
     */
    public function place(int $userId, array $lines, array $address, string $shippingMethod, string $gatewayId): array
    {
        $user = $userId > 0 ? UserStore::find($userId) : null;
        if ($user === null) {
            throw new InvalidException('', 'unknown_user', _m('Sign in to place an order.'));
        }
        if (!osc_billing_enabled()) {
            throw new InvalidException('/gateway', 'unavailable', _m('This payment method is not available.'));
        }
        $address = self::address($address);

        $quote  = $this->pricer->quote($lines, array(
            'address'         => $address,
            'shipping_method' => $shippingMethod,
            'gateway'         => $gatewayId,
            'user_id'         => $userId,
        ));
        $errors = array();
        foreach ($quote->problems() as $problem) {
            $errors[] = array('pointer' => '/lines/' . $problem['line'], 'code' => $problem['code'], 'message' => self::problemText($problem['code']));
        }
        if ($errors === array() && $quote->lines() === array()) {
            $errors[] = array('pointer' => '/lines', 'code' => 'empty', 'message' => _m('Your cart is empty.'));
        }
        $chosen = $quote->shipping();
        if ($quote->shippingMethods() === array() ? $shippingMethod !== '' : ($chosen === null || $chosen['id'] !== $shippingMethod)) {
            $errors[] = array('pointer' => '/shipping_method', 'code' => 'unknown', 'message' => _m('Choose a delivery method.'));
        }
        $gateways = PaymentGatewayRegistry::getInstance()->available($quote->currency());
        if (!isset($gateways[$gatewayId])) {
            $errors[] = array('pointer' => '/gateway', 'code' => 'unavailable', 'message' => _m('This payment method is not available.'));
        }
        if ($errors !== array()) {
            throw InvalidException::all($errors);
        }

        $cart = $quote->cart();
        osc_run_hook('cart_checkout_before', $cart);

        $meta = array(
            '_kind'     => Order::KIND_GOODS,
            '_label'    => self::label($quote->lines()),
            '_address'  => $address,
            '_shipping' => array('method' => $chosen['id'] ?? null, 'label' => $chosen['label'] ?? null),
            '_buyer'    => array(
                'email' => (string) ($user['s_email'] ?? ''),
                'name'  => (string) ($user['s_name'] ?? ''),
                'phone' => (string) ($user['s_phone_mobile'] ?? ''),
            ),
        );
        $order = Db::transaction(static function () use ($userId, $gatewayId, $quote, $meta): Order {
            $order = OrderStore::create($userId, $gatewayId, $quote->total(), $quote->currency(), 0, $meta);
            OrderLineStore::add($order->getId(), self::lines($quote));
            if (in_array($gatewayId, Billing::HOLDS_STOCK, true)) {
                self::hold($order->getId(), $quote);
            }

            return $order;
        });

        $intent = Billing::checkout($order);
        if ($intent === null) {
            OrderStore::cancel($order->getId());
            throw new InvalidException('/gateway', 'unavailable', _m('This payment method is not available right now. Try another.'));
        }
        osc_run_hook('billing_order_placed', $order->getId(), $userId);

        return array('order' => $order, 'next' => $intent);
    }

    /**
     * Take the stock of an order paid outside the site. A short line refuses the order, and the
     * caller's transaction rolls everything back.
     *
     * @throws InvalidException with `/lines/n` out_of_stock
     * @throws \mindstellar\database\DbException
     */
    private static function hold(int $orderId, Quote $quote): void
    {
        $short = OrderStock::take($orderId);
        if ($short === array()) {
            return;
        }
        $errors = array();
        $quoted = $quote->lines();
        foreach (OrderLineStore::itemLines($orderId) as $n => $line) {
            if (isset($short[$line['id']], $quoted[$n])) {
                $errors[] = array('pointer' => '/lines/' . $quoted[$n]['line'], 'code' => 'out_of_stock', 'message' => self::problemText('out_of_stock'));
            }
        }

        throw InvalidException::all($errors !== array() ? $errors : array(array('pointer' => '/lines', 'code' => 'out_of_stock', 'message' => self::problemText('out_of_stock'))));
    }

    /**
     * What an order is for, for a gateway's charge line: "Tee × 2 and 1 more".
     *
     * @param array<int,array<string,mixed>> $lines item lines
     */
    public static function label(array $lines): string
    {
        $first = $lines[0] ?? null;
        if ($first === null) {
            return '';
        }
        $label = (int) $first['qty'] > 1 ? sprintf(_m('%1$s × %2$d'), $first['title'], (int) $first['qty']) : (string) $first['title'];
        if (count($lines) > 1) {
            $label = sprintf(_m('%1$s and %2$d more'), $label, count($lines) - 1);
        }

        return mb_substr($label, 0, 200, 'UTF-8');
    }

    /**
     * The address trimmed to ADDRESS; every field but line2 is needed.
     *
     * @param array<string,mixed> $address
     *
     * @return array<string,string>
     * @throws InvalidException
     */
    private static function address(array $address): array
    {
        $out    = array();
        $errors = array();
        foreach (self::ADDRESS as $field => $max) {
            $value = $address[$field] ?? '';
            $value = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '') : '';
            if ($value === '' && $field !== 'line2') {
                $errors[] = array('pointer' => '/address/' . $field, 'code' => 'required', 'message' => _m('This field is needed.'));
            } elseif (mb_strlen($value, 'UTF-8') > $max) {
                $errors[] = array('pointer' => '/address/' . $field, 'code' => 'maxLength', 'message' => sprintf(_m('Keep this under %d characters.'), $max + 1));
            }
            $out[$field] = $value;
        }
        if ($errors !== array()) {
            throw InvalidException::all($errors);
        }

        return $out;
    }

    /**
     * The order's lines: the goods, then shipping, tax, discount and fee.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function lines(Quote $quote): array
    {
        $out = array();
        foreach ($quote->lines() as $line) {
            $out[] = array(
                'kind'       => OrderLineStore::KIND_ITEM,
                'item_id'    => $line['item_id'],
                'variant_id' => $line['variant'],
                'title'      => $line['title'],
                'options'    => $line['options'],
                'sku'        => $line['sku'],
                'qty'        => $line['qty'],
                'unit'       => $line['unit'],
                'amount'     => $line['amount'],
            );
        }
        foreach (array(OrderLineStore::KIND_SHIPPING, OrderLineStore::KIND_TAX, OrderLineStore::KIND_DISCOUNT, OrderLineStore::KIND_FEE) as $kind) {
            foreach ($quote->extraLines($kind) as $line) {
                $out[] = array(
                    'kind'   => $kind,
                    'title'  => $line['label'],
                    'qty'    => 1,
                    'unit'   => $line['amount'],
                    'amount' => $line['amount'],
                    'meta'   => array('id' => $line['id']) + $line['meta'],
                );
            }
        }

        return $out;
    }

    private static function problemText(string $code): string
    {
        switch ($code) {
            case 'out_of_stock':
                return _m('Not enough left in stock.');
            case 'qty':
                return sprintf(_m('Choose 1 to %d of each item.'), CartPricer::MAX_QTY);
            default:
                return _m('This item is no longer for sale.');
        }
    }
}
