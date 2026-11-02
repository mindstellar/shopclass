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

use mindstellar\billing\event\OrderProcessing;
use mindstellar\billing\event\OrderShipped;
use mindstellar\billing\event\ShipmentAdded;
use mindstellar\billing\event\TrackingUrlResolving;
use mindstellar\boot\CoreServices;
use mindstellar\database\Db;

/**
 * The parcels of a goods order (`_shipments` in its meta) and its packed label (`_packed`).
 * Each write runs under the order's row lock; `_shipping` mirrors the newest parcel.
 */
final class Shipments
{
    private function __construct()
    {
    }

    /**
     * Send a parcel of an order that can ship (Order::canShip()). The first parcel that leaves
     * lines open moves it to processing; the one that ships the last open unit moves it to shipped.
     * With no URL given, a `TrackingUrlResolving` listener may supply one, kept only when it is https.
     *
     * @param int                   $orderId
     * @param array<int,int|string>|null $lines Quantity by order line id (zero is skipped); null ships
     *                                          everything still open
     * @param string                $carrier  Up to 100 characters
     * @param string                $tracking Up to 100 characters
     * @param string                $url      The parcel's tracking page; the caller checks it is https
     *
     * @return int|null the parcel's number, or null when the order cannot ship or a line is not an
     *                  open item line of the order, a quantity is more than is open, or nothing is sent
     */
    public static function add(int $orderId, ?array $lines, string $carrier, string $tracking, string $url): ?int
    {
        $order = OrderStore::find($orderId);
        if ($order === null || !$order->canShip()) {
            return null;
        }
        $wanted = null;
        if ($lines !== null) {
            $wanted = self::quantities($lines);
            if ($wanted === null || $wanted === array()) {
                return null;
            }
        }
        $ordered = array();
        foreach (OrderLineStore::itemLines($orderId) as $line) {
            $ordered[(int) $line['id']] = (int) $line['qty'];
        }

        $carrier  = self::clip($carrier, 100);
        $tracking = self::clip($tracking, 100);
        $url      = trim($url);
        if ($url === '') {
            $found = new TrackingUrlResolving($carrier, $tracking);
            CoreServices::dispatcher()->dispatch($found);
            $url = $found->url();
        }
        $url   = mb_substr($url, 0, 500, 'UTF-8');
        $actor = OrderHistory::actor($order->getUserId());
        $from  = array(Order::STATUS_PAID, Order::STATUS_PROCESSING);
        if ($order->getGateway() === gateway\CashOnDeliveryGateway::ID) {
            $from[] = Order::STATUS_PENDING;
        }

        $n      = 0;
        $old    = '';
        $now    = '';
        $userId = OrderStore::transition(
            $orderId,
            $from,
            static function (array $meta) use ($ordered, &$now): string {
                return $now = self::complete($meta, $ordered) ? Order::STATUS_SHIPPED : Order::STATUS_PROCESSING;
            },
            static function (array $meta, string $status) use ($wanted, $ordered, $carrier, $tracking, $url, $actor, &$n, &$old): ?array {
                $open = $ordered;
                foreach (self::view($meta)->shippedQty() as $id => $qty) {
                    if (isset($open[$id])) {
                        $open[$id] -= $qty;
                    }
                }
                $send = $wanted ?? array_filter($open, static fn (int $qty): bool => $qty > 0);
                foreach ($send as $id => $qty) {
                    if (!isset($open[$id]) || $qty > $open[$id]) {
                        return null;
                    }
                }
                $list = self::view($meta)->shipments();
                $n    = count($list) === 0 ? 1 : max(array_map(static fn (array $s): int => (int) ($s['n'] ?? 0), $list)) + 1;
                $at   = date('Y-m-d H:i:s');
                $old  = $status;

                $list[] = array(
                    'n'        => $n,
                    'at'       => $at,
                    'by'       => $actor['by'],
                    'id'       => $actor['id'],
                    'lines'    => array_map(static fn (int $id, int $qty): array => array('line' => $id, 'qty' => $qty), array_keys($send), array_values($send)),
                    'carrier'  => $carrier,
                    'tracking' => $tracking,
                    'url'      => $url,
                );
                $meta[OrderStore::SHIPMENTS] = $list;

                return self::mirror($meta, $carrier, $tracking, $url, $at);
            }
        );
        if ($userId === null) {
            return null;
        }

        Db::afterCommit(static function () use ($orderId, $userId, $n, $old, $now): void {
            $events = CoreServices::dispatcher();
            if ($now === Order::STATUS_PROCESSING && $old !== Order::STATUS_PROCESSING) {
                $events->dispatch(new OrderProcessing($orderId, $userId));
            }
            $events->dispatch(new ShipmentAdded($orderId, $userId, $n));
            if ($now === Order::STATUS_SHIPPED) {
                $events->dispatch(new OrderShipped($orderId, $userId));
            }
        });

        return $n;
    }

    /**
     * Correct the carrier, tracking number and link of one parcel, in processing or shipped. With
     * $n null it is the order's newest parcel, or its one tracking when it has no parcel list, and
     * the order must be shipped. The status stays.
     *
     * @param int      $orderId
     * @param int|null $n        The parcel's number
     * @param string   $carrier  Up to 100 characters
     * @param string   $tracking Up to 100 characters
     * @param string   $url      The parcel's tracking page; the caller checks it is https
     *
     * @return bool false when the order is not in those statuses or has no parcel $n
     */
    public static function setTracking(int $orderId, ?int $n, string $carrier, string $tracking, string $url): bool
    {
        $carrier  = self::clip($carrier, 100);
        $tracking = self::clip($tracking, 100);
        $url      = self::clip($url, 500);
        $from     = $n === null ? array(Order::STATUS_SHIPPED) : array(Order::STATUS_PROCESSING, Order::STATUS_SHIPPED);

        return self::metaOnly($orderId, $from, static function (array $meta) use ($n, $carrier, $tracking, $url): ?array {
            $list = self::view($meta)->shipments();
            $last = array_key_last($list);
            $at   = null;
            foreach ($list as $i => $shipment) {
                if ($n === null ? $i === $last : (int) ($shipment['n'] ?? 0) === $n) {
                    $list[$i] = array('carrier' => $carrier, 'tracking' => $tracking, 'url' => $url) + $shipment;
                    $at       = $i;
                }
            }
            if ($at === null && ($n !== null || $list !== array())) {
                return null;
            }
            if ($at !== null) {
                $meta[OrderStore::SHIPMENTS] = $list;
            }
            if ($at === null || $at === $last) {
                $shipping = is_array($meta['_shipping'] ?? null) ? $meta['_shipping'] : array();
                $meta['_shipping'] = array('carrier' => $carrier, 'tracking' => $tracking, 'url' => $url) + $shipping;
            }

            return $meta;
        });
    }

    /**
     * Mark an order that can ship as packed, or clear the mark. A label for the admin only.
     *
     * @param int  $orderId
     * @param bool $on
     *
     * @return bool whether the mark is now as asked
     */
    public static function markPacked(int $orderId, bool $on): bool
    {
        $order = OrderStore::find($orderId);
        if ($order === null || !$order->canShip()) {
            return false;
        }
        $actor = OrderHistory::actor($order->getUserId());

        return self::metaOnly($orderId, array($order->getStatus()), static function (array $meta) use ($on, $actor): array {
            if (!$on) {
                unset($meta[OrderStore::PACKED]);
            } elseif (!is_array($meta[OrderStore::PACKED] ?? null)) {
                $meta[OrderStore::PACKED] = array('at' => date('Y-m-d H:i:s'), 'by' => $actor['by'], 'id' => $actor['id']);
            }

            return $meta;
        });
    }

    /**
     * Change an order's meta under the row lock without moving its status.
     *
     * @param string[]                      $from
     * @param callable(array,string):?array $change
     */
    private static function metaOnly(int $orderId, array $from, callable $change): bool
    {
        return OrderStore::transition($orderId, $from, static fn (array $meta, string $status): string => $status, $change) !== null;
    }

    /**
     * Whether every item line went out in full.
     *
     * @param array<string,mixed> $meta
     * @param array<int,int>      $ordered Quantity by line id
     */
    private static function complete(array $meta, array $ordered): bool
    {
        $shipped = self::view($meta)->shippedQty();
        foreach ($ordered as $id => $qty) {
            if (($shipped[$id] ?? 0) < $qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * An order holding only $meta, to read its parcels.
     *
     * @param array<string,mixed> $meta
     */
    private static function view(array $meta): Order
    {
        return new Order(0, 0, '', null, 0, 'USD', 0, Order::STATUS_PENDING, $meta);
    }

    /**
     * $meta with `_shipping` set to the newest parcel.
     *
     * @param array<string,mixed> $meta
     *
     * @return array<string,mixed>
     */
    private static function mirror(array $meta, string $carrier, string $tracking, string $url, string $at): array
    {
        $shipping = is_array($meta['_shipping'] ?? null) ? $meta['_shipping'] : array();
        $meta['_shipping'] = array(
            'carrier'    => $carrier,
            'tracking'   => $tracking,
            'url'        => $url,
            'shipped_at' => $at,
        ) + $shipping;

        return $meta;
    }

    /**
     * Positive quantities by line id; null when a key or quantity is not a whole number, or one is negative.
     *
     * @param array<int|string,int|string> $lines
     *
     * @return array<int,int>|null
     */
    private static function quantities(array $lines): ?array
    {
        $out = array();
        foreach ($lines as $id => $qty) {
            $id  = filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            $qty = filter_var($qty, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
            if ($id === false || $qty === false) {
                return null;
            }
            if ($qty > 0) {
                $out[$id] = $qty;
            }
        }

        return $out;
    }

    private static function clip(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max, 'UTF-8');
    }
}
