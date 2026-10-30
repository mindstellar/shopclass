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

use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\boot\CoreServices;
use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\event\DispatcherInterface;
use mindstellar\exception\InvalidException;
use mindstellar\user\AddressBook;
use mindstellar\user\UserStore;

/**
 * Turns a cart into a pending goods order and asks its gateway how to pay. The web and API
 * controllers both place through here. The cart is priced again on the server, so nothing
 * the browser sent reaches an amount. The order and its lines are one transaction; a gateway
 * that gives no checkout leaves the order cancelled, never pending. Every order takes its stock at
 * placement, so a short line is refused before the buyer pays. A card order still unpaid after
 * billing_card_hold_minutes is cancelled by a queued job; Billing::HOLDS_STOCK orders wait billing_hold_days.
 *
 * Fires `cart_checkout_before` ($cart) before the write and `billing_order_placed`
 * ($orderId, $userId) once the gateway has answered.
 */
final class GoodsCheckout
{
    /** Address fields an order takes, with their longest length: the address book's. line2 may be empty. */
    public const ADDRESS = AddressBook::ADDRESS;

    private CartPricer $pricer;

    private ?DispatcherInterface $events;

    public function __construct(?CartPricer $pricer = null, ?DispatcherInterface $events = null)
    {
        $this->pricer = $pricer ?? Container::root()->get(CartPricer::class);
        $this->events = $events;
    }

    /**
     * Place the order.
     *
     * @param array<int,mixed>    $lines   {variant, qty} each
     * @param array<string,mixed> $address ADDRESS keys
     * @param string              $note    what the buyer asked of the seller, kept with the order
     * @param string|null         $coupon  the code the buyer entered; the cart filters see CartPricer::coupon() of it
     *
     * @return array{order:Order,next:CheckoutIntent}
     * @throws InvalidException with `/lines/n` (not_buyable, out_of_stock, qty), `/lines` (empty),
     *                          `/address/<field>`, `/address/id` (unknown), `/shipping_method` (unknown), `/gateway` (unavailable, too_many_open),
     *                          or `rejected` at a `cart_problems` entry's pointer, with its message
     * @throws \mindstellar\database\DbException
     */
    public function place(int $userId, array $lines, array $address, string $shippingMethod, string $gatewayId, string $note = '', ?string $coupon = null): array
    {
        $user = $userId > 0 ? UserStore::find($userId) : null;
        if ($user === null) {
            throw new InvalidException('', 'unknown_user', _m('Sign in to place an order.'));
        }
        if (!osc_billing_enabled()) {
            throw new InvalidException('/gateway', 'unavailable', _m('This payment method is not available.'));
        }
        [$raw, $save] = self::chosenAddress($userId, $address);
        $address      = self::address($raw, false);

        $quote  = $this->pricer->quote($lines, array(
            'address'         => $address,
            'shipping_method' => $shippingMethod,
            'gateway'         => $gatewayId,
            'user_id'         => $userId,
            'coupon'          => $coupon,
        ));
        $ships = $quote->needsShipping();
        if ($ships) {
            self::address($raw, true);
            if ($save) {
                AddressBook::check($raw, '/address');
            }
        }
        $errors = array();
        foreach ($quote->problems() as $problem) {
            $errors[] = $problem['pointer'] === null
                ? array('pointer' => '/lines/' . $problem['line'], 'code' => $problem['code'], 'message' => $problem['message'] ?? self::problemText($problem['code']))
                : array('pointer' => $problem['pointer'], 'code' => 'rejected', 'message' => (string) $problem['message']);
        }
        if ($errors === array() && $quote->lines() === array()) {
            $errors[] = array('pointer' => '/lines', 'code' => 'empty', 'message' => _m('Your cart is empty.'));
        }
        $chosen = $quote->shipping();
        if ($ships && ($quote->shippingMethods() === array() ? $shippingMethod !== '' : ($chosen === null || $chosen['id'] !== $shippingMethod))) {
            $errors[] = array('pointer' => '/shipping_method', 'code' => 'unknown', 'message' => _m('Choose a delivery method.'));
        }
        $gateways = self::gateways($quote);
        if (!isset($gateways[$gatewayId])) {
            $errors[] = array('pointer' => '/gateway', 'code' => 'unavailable', 'message' => _m('This payment method is not available.'));
        }
        if (isset($gateways[$gatewayId]) && $gatewayId === gateway\OfflineGateway::ID && self::holdsTooMany($userId)) {
            $errors[] = array('pointer' => '/gateway', 'code' => 'too_many_open', 'message' => _m('Pay for or cancel your open bank transfer orders first.'));
        }
        if ($errors !== array()) {
            throw InvalidException::all($errors);
        }

        $cart = $quote->cart();
        $events  = $this->events ??= CoreServices::dispatcher();
        $started = $events->dispatch(new CheckoutStarting($cart));
        if ($started->refusals() !== array()) {
            throw InvalidException::all($started->refusals());
        }

        $meta = array(
            '_kind'     => Order::KIND_GOODS,
            '_label'    => self::label($quote->lines()),
            '_shipping' => array('method' => $chosen['id'] ?? null, 'label' => $chosen['label'] ?? null),
            '_buyer'    => array(
                'email' => (string) ($user['s_email'] ?? ''),
                'name'  => (string) ($user['s_name'] ?? ''),
                'phone' => (string) ($user['s_phone_mobile'] ?? ''),
            ),
        );
        if ($ships) {
            $meta['_address'] = $address;
        } elseif ($address['country'] !== '' || $address['state'] !== '') {
            $meta['_address'] = array_filter(array_intersect_key($address, array('state' => 1, 'country' => 1)), static fn (string $v): bool => $v !== '');
        }
        $note = mb_substr(trim($note), 0, 500, 'UTF-8');
        if ($note !== '') {
            $meta['_note'] = $note;
        }
        $order = Db::transaction(static function () use ($userId, $gatewayId, $quote, $meta): Order {
            $order = OrderStore::create($userId, $gatewayId, $quote->total(), $quote->currency(), 0, $meta);
            OrderLineStore::add($order->getId(), self::lines($quote));
            self::hold($order->getId(), $quote);
            if (!in_array($gatewayId, Billing::HOLDS_STOCK, true)) {
                Billing::queueCardHold($order->getId());
            }

            return $order;
        });

        $intent = Billing::checkout($order);
        if ($intent === null) {
            // The buyer never saw this order placed, so no cancel mail goes for it.
            OrderStore::markMailSent($order->getId(), Receipts::MAIL_CANCELLED);
            OrderStore::cancel($order->getId());
            throw new InvalidException('/gateway', 'unavailable', _m('This payment method is not available right now. Try another.'));
        }
        if ($save && $ships) {
            try {
                (new AddressBook())->keep($userId, $raw);
            } catch (\mindstellar\database\DbException $e) {
                // The order is placed; a failed save must not report it as failed.
                trigger_error('Address book: ' . $e->getMessage(), E_USER_WARNING);
            }
        }
        CoreServices::dispatcher()->dispatch(new OrderPlaced($order->getId(), $userId));

        return array('order' => $order, 'next' => $intent);
    }

    /**
     * The address to deliver to, and whether to save it: a saved address by `id`, or the typed one.
     *
     * @param array<string,mixed> $address
     *
     * @return array{0:array<string,mixed>,1:bool}
     * @throws InvalidException `/address/id` unknown for an id that is not one of the buyer's
     * @throws \mindstellar\database\DbException
     */
    private static function chosenAddress(int $userId, array $address): array
    {
        $id = $address['id'] ?? null;
        if ($id === null || $id === '') {
            return array($address, ($address['save'] ?? false) === true);
        }
        $saved = is_string($id) ? (new AddressBook())->find($userId, $id) : null;
        if ($saved === null) {
            throw new InvalidException('/address/id', 'unknown', _m('That address is not in your address book any more.'));
        }

        return array(AddressBook::forOrder($saved), false);
    }

    /**
     * Whether the buyer already holds the most unpaid bank transfer orders. Cash on delivery and
     * card orders are not limited.
     */
    private static function holdsTooMany(int $userId): bool
    {
        $open = self::openOrders($userId);

        return $open['limit'] > 0 && $open['count'] >= $open['limit'];
    }

    /**
     * The buyer's unpaid bank transfer orders and the most they may hold; a limit of 0 means none.
     *
     * @return array{count:int,limit:int}
     */
    public static function openOrders(int $userId): array
    {
        return array(
            'count' => OrderStore::countForUser($userId, array(Order::STATUS_PENDING), array(gateway\OfflineGateway::ID)),
            'limit' => osc_billing_open_hold_orders(),
        );
    }

    /**
     * The payment methods a quote may pay through, by id. Cash on delivery needs something to
     * deliver, to an address inside the areas set for it.
     *
     * @return array<string,PaymentGateway>
     */
    public static function gateways(Quote $quote): array
    {
        $gateways = PaymentGatewayRegistry::getInstance()->available($quote->currency());
        if (!$quote->needsShipping() || !CodAreas::allows($quote->cart()['address'] ?? array())) {
            unset($gateways[gateway\CashOnDeliveryGateway::ID]);
        }

        return $gateways;
    }

    /**
     * Take the order's stock. A short line refuses the order, and the caller's transaction rolls
     * everything back.
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
     * The address trimmed to ADDRESS; with $required, every field but line2 is needed.
     *
     * @param array<string,mixed> $address
     *
     * @return array<string,string>
     * @throws InvalidException
     */
    private static function address(array $address, bool $required): array
    {
        $out    = array();
        $errors = array();
        foreach (self::ADDRESS as $field => $max) {
            $value   = AddressBook::clean($field, $address[$field] ?? '');
            $problem = AddressBook::problem($field, $value, $max, $required && $field !== 'line2');
            if ($problem !== null) {
                $errors[] = array('pointer' => '/address/' . $field, 'code' => $problem[0], 'message' => $problem[1]);
            }
            $out[$field] = $value;
        }
        if ($errors !== array()) {
            throw InvalidException::all($errors);
        }

        return $out;
    }

    /**
     * The order's lines: the goods, then shipping, tax, discount and fee. A tax already in the
     * prices is stored with amount 0 and the tax as its unit, meta `included`, so the lines
     * still add up to the order's total.
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
                $included = ($line['included'] ?? false) === true;
                $out[]    = array(
                    'kind'   => $kind,
                    'title'  => $line['label'],
                    'qty'    => 1,
                    'unit'   => $line['amount'],
                    'amount' => $included ? 0 : $line['amount'],
                    'meta'   => array('id' => $line['id']) + ($included ? array('included' => true) : array()) + $line['meta'],
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
