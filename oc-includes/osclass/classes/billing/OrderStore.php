<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

use InvalidArgumentException;
use mindstellar\base\Model;
use mindstellar\billing\event\OrderCancelled;
use mindstellar\billing\event\OrderDelivered;
use mindstellar\billing\event\OrderShipped;
use mindstellar\billing\event\TrackingUrlResolving;
use mindstellar\boot\CoreServices;
use mindstellar\currency\CurrencyCode;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\database\QueryBuilder;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\ListingStore;
use mindstellar\model\KeyValue;

/**
 * Persistence for t_billing_order.
 *
 * Queries run through the parameterized osc_db_* / QueryBuilder API; this does not
 * extend the legacy DAO layer.
 *
 * @package mindstellar\billing
 */
final class OrderStore extends Model
{
    /** Unprefixed table name. */
    protected const TABLE = 't_billing_order';

    /** s_meta key core sets when a refund was sent to the provider. */
    public const REFUND_REQUESTED = '_refund_requested';

    /** s_meta key core sets once the order's receipt e-mail went out. */
    public const RECEIPT_SENT = '_receipt_sent';

    /** s_meta key core sets when an order was paid but its listing could not be published. */
    public const PUBLISH_FAILED = '_publish_failed';

    /** Cache key and lifetime (seconds) of the "to ship" count shown in the admin menu. */
    public const TO_SHIP_CACHE_KEY = 'osclass_orders_to_ship';
    private const TO_SHIP_CACHE_TTL = 60;

    /** Key-value group linking an unpaid listing to its open order. */
    private const LISTING_GROUP = 'listing_unpaid';

    /**
     * Record a new pending order.
     *
     * @param int    $userId
     * @param string $gateway  Registered gateway id
     * @param int    $amount   Micros (value x 1,000,000)
     * @param string $currency ISO 4217
     * @param int    $credits  Credits minted on payment
     * @param array  $meta     Plugin-owned metadata, JSON-encoded on write
     *
     * @return Order
     * @throws InvalidArgumentException on a non-positive amount or malformed currency
     */
    public static function create(
        int $userId,
        string $gateway,
        int $amount,
        string $currency,
        int $credits,
        array $meta = array()
    ): Order {
        if ($amount < 0) {
            throw new InvalidArgumentException('Orders: amount cannot be negative');
        }
        if (!CurrencyCode::valid($currency)) {
            throw new InvalidArgumentException('Orders: currency must be an ISO 4217 code');
        }

        $now = date('Y-m-d H:i:s');
        $id  = self::table()->insert(array(
            'fk_i_user_id'   => $userId,
            's_gateway'      => $gateway,
            's_external_ref' => null,
            'i_amount'       => $amount,
            's_currency'     => $currency,
            'i_credits'      => $credits,
            's_status'       => Order::STATUS_PENDING,
            's_meta'         => $meta === array() ? null : json_encode($meta),
            'dt_date'        => $now,
        ));
        self::forgetToShip();

        return new Order($id, $userId, $gateway, null, $amount, $currency, $credits, Order::STATUS_PENDING, $meta, $now);
    }

    /**
     * One order, or null when no order has that id.
     *
     * @param int $id
     *
     * @return Order|null
     */
    public static function find(int $id): ?Order
    {
        $row = self::table()->where('pk_i_id', $id)->first();

        return $row === null ? null : Order::fromRow($row);
    }

    /**
     * Remember the open order that will publish a held listing, until the hold ends.
     *
     * @param int $itemId
     * @param int $orderId
     * @param int $expiresAt Unix time the link lapses
     */
    public static function linkListing(int $itemId, int $orderId, int $expiresAt): void
    {
        (new KeyValue())->set(self::LISTING_GROUP, (string) $itemId, json_encode(array('order' => $orderId)), $expiresAt);
    }

    /**
     * The open order linked to a held listing, or null when the link lapsed or the order is gone.
     *
     * @param int $itemId
     *
     * @return Order|null
     */
    public static function listingOrder(int $itemId): ?Order
    {
        $row  = (new KeyValue())->get(self::LISTING_GROUP, (string) $itemId);
        $data = $row === null || $row['value'] === null ? null : json_decode($row['value'], true);

        return is_array($data) && isset($data['order']) ? self::find((int) $data['order']) : null;
    }

    /**
     * Drop the link once the listing is published or gone.
     *
     * @param int $itemId
     */
    public static function unlinkListing(int $itemId): void
    {
        (new KeyValue())->delete(self::LISTING_GROUP, (string) $itemId);
    }

    /**
     * Look an order up by the gateway's own reference. This is how a webhook that
     * carries only the provider's id finds its way back to our record.
     *
     * @param string $gateway
     * @param string $externalRef
     *
     * @return Order|null
     */
    public static function findByGatewayRef(string $gateway, string $externalRef): ?Order
    {
        $row = self::table()
            ->where('s_gateway', $gateway)
            ->where('s_external_ref', $externalRef)
            ->first();

        return $row === null ? null : Order::fromRow($row);
    }

    /**
     * Move an order to $status, optionally stamping the gateway's reference.
     *
     * Guarded by default on the current status being pending, so a replayed webhook
     * cannot re-settle an order that already settled -- the update matches no row and
     * returns false. The caller treats that as "already handled", not as an error.
     * A move to failed gives back the stock the order holds.
     *
     * @param int      $id
     * @param string   $status      One of the Order::STATUS_* constants
     * @param string|null $externalRef The gateway's own reference, when it has one
     * @param string[] $from Statuses eligible to transition from. Widen this only for
     *                       the admin "mark paid" escape hatch reopening a `failed`
     *                       order (Billing::markPaid()'s $allowFailed) -- no gateway
     *                       callback route passes anything but the default.
     *
     * @return bool whether this call was the one that changed the row
     * @throws InvalidArgumentException on an unknown status
     */
    public static function settle(
        int $id,
        string $status,
        ?string $externalRef = null,
        array $from = array(Order::STATUS_PENDING)
    ): bool {
        if (!in_array($status, Order::STATUSES, true)) {
            throw new InvalidArgumentException('Orders: unknown status "' . $status . '"');
        }

        $data = array('s_status' => $status);
        if ($externalRef !== null) {
            $data['s_external_ref'] = $externalRef;
        }
        if ($status === Order::STATUS_PAID) {
            $data['dt_paid_date'] = date('Y-m-d H:i:s');
        }

        $write = static function () use ($id, $from, $data, $status): int {
            try {
                $changed = self::table()
                    ->where('pk_i_id', $id)
                    ->whereIn('s_status', $from)
                    ->update($data);
            } catch (DbException $e) {
                // uq_gateway_ref: this external reference is already attached to another
                // order, which means the callback is being replayed against the wrong
                // record. Refuse rather than overwrite.
                return 0;
            }
            if ($changed === 1 && $status === Order::STATUS_FAILED) {
                OrderStock::release($id);
            }

            return $changed;
        };
        $changed = $status === Order::STATUS_FAILED ? Db::transaction($write) : $write();

        if ($changed === 1) {
            self::forgetToShip();
        }

        return $changed === 1;
    }

    /**
     * Store the gateway's reference on an order that is still pending, such as a checkout
     * session id made in createCheckout(). A paid callback can then find the order by it.
     * Attaching again replaces the ref, so a re-checkout can store its new session.
     *
     * @param int    $orderId
     * @param string $gatewayId The gateway the order must belong to
     * @param string $ref       Printable ASCII without spaces, up to 191 characters
     *
     * @return bool whether a row changed: false when the order is not pending or not
     *              this gateway's, the ref is malformed, or another order already has it
     */
    public static function attachRef(int $orderId, string $gatewayId, string $ref): bool
    {
        if (!preg_match('/^[\x21-\x7E]{1,191}$/', $ref)) {
            return false;
        }

        try {
            $changed = self::table()
                ->where('pk_i_id', $orderId)
                ->where('s_gateway', $gatewayId)
                ->where('s_status', Order::STATUS_PENDING)
                ->update(array('s_external_ref' => $ref));
        } catch (DbException $e) {
            return false; // uq_gateway_ref: the ref belongs to another order
        }

        return $changed === 1;
    }

    /**
     * Mark a paid order as sent to the provider for a refund, or clear the mark. While
     * it is set, core does not ask the provider again; see Billing::refundThroughGateway().
     *
     * @param int  $orderId
     * @param bool $on
     *
     * @return bool whether the mark is now as asked
     */
    public static function markRefundRequested(int $orderId, bool $on = true): bool
    {
        return self::changeMeta($orderId, static function (array $meta) use ($on): array {
            if ($on) {
                $meta[self::REFUND_REQUESTED] = date('Y-m-d H:i:s');
            } else {
                unset($meta[self::REFUND_REQUESTED]);
            }

            return $meta;
        }, Order::SETTLED);
    }

    /**
     * Record that the order's receipt e-mail was sent.
     *
     * @param int $orderId
     *
     * @return bool whether the mark is stored
     */
    public static function markReceiptSent(int $orderId): bool
    {
        return self::changeMeta($orderId, static function (array $meta): array {
            $meta[self::RECEIPT_SENT] = date('Y-m-d H:i:s');

            return $meta;
        });
    }

    /**
     * Record that an order was paid but its listing could not be published.
     *
     * @param int    $orderId
     * @param string $reason  Short plain text
     *
     * @return bool whether the order exists
     */
    public static function notePublishFailed(int $orderId, string $reason): bool
    {
        return self::withMeta($orderId, static function (array $meta) use ($reason): array {
            $meta[self::PUBLISH_FAILED] = array('reason' => $reason, 'at' => date('Y-m-d H:i:s'));

            return $meta;
        });
    }

    /**
     * Where a listing order's listing stands: "held" (waiting for payment), "live" (it went
     * live at some point) or "gone" (deleted).
     *
     * @param int $itemId
     *
     * @return string
     */
    public static function listingState(int $itemId): string
    {
        $row = ListingStore::find($itemId, array('b_enabled', 'b_active', 'b_spam', 'dt_first_pub_date'));
        if ($row === null) {
            return 'gone';
        }

        return ListingStatus::of($row) === ListingStatus::UNPAID ? 'held' : 'live';
    }

    /**
     * Record that one of an order's notice e-mails (placed, shipped, admin) was sent.
     *
     * @param int    $orderId
     * @param string $kind    Letters only, e.g. "shipped"
     *
     * @return bool whether the mark is stored
     */
    public static function markMailSent(int $orderId, string $kind): bool
    {
        if (!preg_match('/^[a-z]{1,20}$/', $kind)) {
            return false;
        }

        return self::changeMeta($orderId, static function (array $meta) use ($kind): array {
            $meta['_mail_' . $kind] = date('Y-m-d H:i:s');

            return $meta;
        });
    }

    /**
     * Store one metadata value on an order, for a gateway plugin. Other keys are kept.
     *
     * Keys starting with an underscore belong to core and are refused.
     *
     * @param int                        $orderId
     * @param string                     $key   Lower-case letters, digits, _ . - (up to 64), not starting with _
     * @param bool|int|float|string|null $value A scalar (a string up to 255 characters), or null to remove the key
     *
     * @return bool whether the value is now stored (false on a bad key or value, or a missing order)
     */
    public static function setMeta(int $orderId, string $key, $value): bool
    {
        if (!preg_match('/^[a-z0-9.-][a-z0-9_.-]{0,63}$/', $key)) {
            return false;
        }
        if ($value !== null && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
            return false;
        }
        if (is_string($value) && mb_strlen($value, 'UTF-8') > 255) {
            return false;
        }
        if (is_float($value) && !is_finite($value)) {
            return false;
        }

        return self::changeMeta($orderId, static function (array $meta) use ($key, $value): array {
            if ($value === null) {
                unset($meta[$key]);
            } else {
                $meta[$key] = $value;
            }

            return $meta;
        });
    }

    /**
     * Read s_meta, apply $change and write it back only if s_meta is unchanged since the
     * read. Tried twice, so one write racing this one does not lose either change.
     *
     * @param int                   $orderId
     * @param callable(array):array $change
     * @param string[]|null         $statuses Only an order in one of these statuses is changed
     * @param string|null           $to       Also move the order to this status, in the same write
     * @param-out array<string,mixed>|null $row
     * @param array<string,mixed>|null $row   Set to the order's user id and status as read
     * @param array<string,mixed>   $set      More columns to write with the move
     *
     * @return bool whether the change is stored
     */
    private static function changeMeta(int $orderId, callable $change, ?array $statuses = null, ?string $to = null, ?array &$row = null, array $set = array()): bool
    {
        for ($try = 0; $try < 2; $try++) {
            if (Db::inTransaction()) {
                // A locking read: a plain one would open the caller's snapshot before the write.
                $sql    = 'SELECT s_meta, fk_i_user_id, s_status FROM ' . self::tableName() . ' WHERE pk_i_id = ?';
                $params = array($orderId);
                if ($statuses === array()) {
                    $sql .= ' AND 1 = 0';
                } elseif ($statuses !== null) {
                    $sql   .= ' AND s_status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
                    $params = array_merge($params, array_values($statuses));
                }
                $row = Db::selectOne($sql . ' FOR UPDATE', $params);
            } else {
                $read = self::table()->select('s_meta', 'fk_i_user_id', 's_status')->where('pk_i_id', $orderId);
                if ($statuses !== null) {
                    $read = $read->whereIn('s_status', $statuses);
                }
                $row = $read->first();
            }
            if ($row === null) {
                return false;
            }

            $old  = $row['s_meta'];
            $meta = $old !== null && $old !== '' ? json_decode((string) $old, true) : array();
            if (!is_array($meta)) {
                $meta = array();
            }
            $new = $change($meta);
            $new = $new === array() ? null : json_encode($new);
            if ($new === $old && $to === null) {
                return true;
            }

            $write = self::table()->where('pk_i_id', $orderId)->where('s_status', $row['s_status']);
            $write = $old === null ? $write->whereNull('s_meta') : $write->where('s_meta', $old);
            $data  = array('s_meta' => $new);
            if ($to !== null) {
                $data = $set + $data + array('s_status' => $to);
            }
            if ($write->update($data) === 1) {
                if ($to !== null) {
                    self::forgetToShip();
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Change an order's meta while its row is locked, for core work that must happen once per
     * order (taking and giving back stock). $change runs inside the transaction and may write
     * elsewhere; what it returns is stored when it differs from what it was given.
     *
     * @param int                   $orderId
     * @param callable(array):array $change
     *
     * @return bool false when no order has that id
     * @throws DbException
     */
    public static function withMeta(int $orderId, callable $change): bool
    {
        return Db::transaction(static function () use ($orderId, $change): bool {
            $row = Db::selectOne('SELECT s_meta FROM ' . self::tableName() . ' WHERE pk_i_id = ? FOR UPDATE', array($orderId));
            if ($row === null) {
                return false;
            }
            $meta = $row['s_meta'] !== null && $row['s_meta'] !== '' ? json_decode((string) $row['s_meta'], true) : array();
            $meta = is_array($meta) ? $meta : array();
            $new  = $change($meta);
            if ($new !== $meta) {
                self::table()->where('pk_i_id', $orderId)->update(array('s_meta' => $new === array() ? null : json_encode($new)));
            }

            return true;
        });
    }

    /**
     * A refund arrives after settlement, so it starts from a settled status (paid,
     * shipped or delivered) rather than pending.
     *
     * @param int $id
     *
     * @return bool whether this call was the one that changed the row
     */
    public static function refund(int $id): bool
    {
        $changed = self::table()
            ->where('pk_i_id', $id)
            ->whereIn('s_status', Order::SETTLED)
            ->update(array('s_status' => Order::STATUS_REFUNDED));
        if ($changed === 1) {
            self::forgetToShip();
        }

        return $changed === 1;
    }

    /**
     * A paid goods order was sent, or a cash on delivery one before it is paid. With no URL
     * given, a `TrackingUrlResolving` listener may supply one, kept only when it
     * is https. Fires `OrderShipped` once the write commits.
     *
     * @param int    $id
     * @param string $carrier  Up to 100 characters
     * @param string $tracking Up to 100 characters
     * @param string $url      The parcel's tracking page; the caller checks it is https
     *
     * @return bool false when the order cannot ship (Order::canShip())
     */
    public static function ship(int $id, string $carrier, string $tracking, string $url): bool
    {
        $order = self::find($id);
        if ($order === null || !$order->canShip()) {
            return false;
        }
        $carrier  = mb_substr(trim($carrier), 0, 100, 'UTF-8');
        $tracking = mb_substr(trim($tracking), 0, 100, 'UTF-8');
        $url      = trim($url);
        if ($url === '') {
            $found = new TrackingUrlResolving($carrier, $tracking);
            CoreServices::dispatcher()->dispatch($found);
            $url = $found->url();
        }
        $at     = date('Y-m-d H:i:s');
        $userId = self::move($id, $order->getStatus(), Order::STATUS_SHIPPED, static function (array $meta) use ($carrier, $tracking, $url, $at): array {
            $shipping = is_array($meta['_shipping'] ?? null) ? $meta['_shipping'] : array();
            $meta['_shipping'] = array(
                'carrier'    => $carrier,
                'tracking'   => $tracking,
                'url'        => mb_substr($url, 0, 500, 'UTF-8'),
                'shipped_at' => $at,
            ) + $shipping;

            return $meta;
        });
        if ($userId === null) {
            return false;
        }
        Db::afterCommit(static fn () => CoreServices::dispatcher()->dispatch(new OrderShipped($id, $userId)));

        return true;
    }

    /**
     * A shipped order arrived. A cash on delivery order not paid yet is paid now, as the
     * courier collected. Fires `OrderDelivered` once the write commits.
     *
     * @param int       $id
     * @param bool|null $collected Set to whether this delivery also marked the order paid
     * @param-out bool  $collected
     *
     * @return bool false when the order is not shipped
     */
    public static function deliver(int $id, ?bool &$collected = null): bool
    {
        $collected = false;
        $order     = self::find($id);
        if ($order === null) {
            return false;
        }
        $at     = date('Y-m-d H:i:s');
        $cash   = $order->awaitsCash();
        $userId = self::move($id, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED, static function (array $meta) use ($at): array {
            $shipping = is_array($meta['_shipping'] ?? null) ? $meta['_shipping'] : array();
            $meta['_shipping'] = array('delivered_at' => $at) + $shipping;

            return $meta;
        }, $cash ? array('dt_paid_date' => $at) : array());
        $collected = $userId !== null && $cash;
        if ($userId === null) {
            return false;
        }
        Db::afterCommit(static fn () => CoreServices::dispatcher()->dispatch(new OrderDelivered($id, $userId)));

        return true;
    }

    /**
     * Close a pending order unpaid, or a cash on delivery parcel the buyer refused, giving back
     * any stock it holds. Fires `OrderCancelled` once the write commits.
     *
     * @param int $id
     *
     * @return bool false when the order is neither
     */
    public static function cancel(int $id): bool
    {
        $order = self::find($id);
        $from  = $order !== null && $order->awaitsCash() ? Order::STATUS_SHIPPED : Order::STATUS_PENDING;
        $userId = Db::transaction(static function () use ($id, $from): ?int {
            $userId = self::move($id, $from, Order::STATUS_CANCELLED, static fn (array $meta): array => $meta);
            if ($userId !== null) {
                OrderStock::release($id);
            }

            return $userId;
        });
        if ($userId === null) {
            return false;
        }
        Db::afterCommit(static fn () => CoreServices::dispatcher()->dispatch(new OrderCancelled($id, $userId)));

        return true;
    }

    /**
     * Move an order from one status to the next with its meta changed in the same write.
     *
     * @param string|string[]       $from
     * @param callable(array):array $change
     * @param array<string,mixed>   $set    More columns to write with the move
     *
     * @return int|null the order's user id, or null when the order was not in $from
     */
    private static function move(int $id, $from, string $to, callable $change, array $set = array()): ?int
    {
        $row = null;

        return self::changeMeta($id, $change, (array) $from, $to, $row, $set) && $row !== null ? (int) $row['fk_i_user_id'] : null;
    }

    /**
     * One user's order history, newest first. Pass the last id of a page as $before for the
     * next one.
     *
     * @param int      $userId
     * @param int      $limit
     * @param int      $offset
     * @param int|null $before Only orders with a lower id
     *
     * @return Order[] newest first
     */
    public static function forUser(int $userId, int $limit = 25, int $offset = 0, ?int $before = null): array
    {
        // Read through idx_user_status: walking the primary key backwards reads the whole table
        // for a buyer whose orders are old.
        $params = array($userId);
        $sql    = 'SELECT * FROM ' . self::tableName() . ' IGNORE INDEX FOR ORDER BY (PRIMARY) WHERE fk_i_user_id = ?';
        if ($before !== null) {
            $sql     .= ' AND pk_i_id < ?';
            $params[] = $before;
        }
        $rows = Db::select($sql . ' ORDER BY pk_i_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);

        return array_map(static fn (array $row): Order => Order::fromRow($row), $rows);
    }

    /**
     * How many goods orders one user has in these statuses, optionally through these gateways
     * only. Credits orders are not counted. Reads idx_user_status.
     *
     * @param string[]      $statuses Order::STATUS_* values; none counts nothing
     * @param string[]|null $gateways gateway ids; null counts every gateway
     *
     * @throws DbException
     */
    public static function countForUser(int $userId, array $statuses, ?array $gateways = null): int
    {
        $statuses = array_values(array_unique(array_map('strval', $statuses)));
        if ($userId <= 0 || $statuses === array() || $gateways === array()) {
            return 0;
        }
        $in     = static fn (array $values): string => '(' . implode(', ', array_fill(0, count($values), '?')) . ')';
        $sql    = 'SELECT COUNT(*) FROM ' . self::tableName()
            . ' WHERE fk_i_user_id = ? AND s_status IN ' . $in($statuses) . ' AND i_credits = 0';
        $params = array_merge(array($userId), $statuses);
        if ($gateways !== null) {
            $gateways = array_values(array_unique(array_map('strval', $gateways)));
            $sql     .= ' AND s_gateway IN ' . $in($gateways);
            $params   = array_merge($params, $gateways);
        }

        return (int) Db::scalar($sql, $params);
    }

    /**
     * Pending goods orders of these gateways (or, with $others, of every other gateway) placed
     * before $before, oldest first. Reads a range of idx_status_date.
     *
     * @param string[] $gateways
     * @param string   $before   Y-m-d H:i:s
     * @param int      $limit
     * @param bool     $others
     *
     * @return int[] order ids
     * @throws DbException
     */
    public static function heldBefore(array $gateways, string $before, int $limit, bool $others = false): array
    {
        if ($gateways === array()) {
            return array();
        }
        $rows = Db::select(
            'SELECT pk_i_id FROM ' . self::tableName() . ' WHERE s_status = ? AND dt_date < ? AND i_credits = 0'
            . ' AND s_gateway ' . ($others ? 'NOT IN (' : 'IN (') . implode(', ', array_fill(0, count($gateways), '?')) . ')'
            . ' ORDER BY dt_date LIMIT ' . max(1, $limit),
            array_merge(array(Order::STATUS_PENDING, $before), array_values($gateways))
        );

        return array_map(static fn (array $row): int => (int) $row['pk_i_id'], $rows);
    }

    /**
     * One gateway's pending orders placed before $before that carry its reference, oldest first:
     * the orders a gateway asks its provider about when no webhook came. Goods and credit orders
     * alike. Reads a range of idx_status_date.
     *
     * @param string $gateway
     * @param string $before    Y-m-d H:i:s
     * @param int    $limit
     * @param string $refPrefix Only references that start with this, such as "cs_"
     *
     * @return Order[]
     * @throws DbException
     */
    public static function pendingBefore(string $gateway, string $before, int $limit, string $refPrefix = ''): array
    {
        $query = self::table()
            ->where('s_status', Order::STATUS_PENDING)
            ->where('dt_date', '<', $before)
            ->where('s_gateway', $gateway)
            ->whereNotNull('s_external_ref');
        if ($refPrefix !== '') {
            $query = $query->where('s_external_ref', 'LIKE', addcslashes($refPrefix, '\\%_') . '%');
        }
        $rows = $query->orderBy('dt_date', 'ASC')->orderBy('pk_i_id', 'ASC')->limit(max(1, $limit))->get();

        return array_map(static fn (array $row): Order => Order::fromRow($row), $rows);
    }

    /**
     * Orders across the whole site.
     *
     * @param int         $limit
     * @param int         $offset
     * @param string|null $status Restrict to one Order::STATUS_* value
     *
     * @return Order[] newest first, optionally filtered by status
     */
    public static function recent(int $limit = 25, int $offset = 0, ?string $status = null): array
    {
        $q = self::table()->orderBy('dt_date', 'DESC')->limit($limit)->offset($offset);
        if ($status !== null) {
            $q = $q->where('s_status', $status);
        }

        return array_map(static fn (array $row): Order => Order::fromRow($row), $q->get());
    }

    /**
     * How many orders exist, optionally in one status.
     *
     * @param string|null $status
     *
     * @return int
     */
    public static function countAll(?string $status = null): int
    {
        $q = self::table();
        if ($status !== null) {
            $q = $q->where('s_status', $status);
        }

        return $q->count();
    }

    /**
     * The admin orders list: filter by status, gateway and user, newest first.
     *
     * @param array $filters 'status', 'gateway', 'user_id', 'goods' (true: goods orders only),
     *                       'to_ship' (true: paid goods orders and unpaid cash on delivery ones) — any may be omitted
     * @param int   $limit
     * @param int   $offset
     *
     * @return Order[]
     */
    public static function search(array $filters, int $limit = 25, int $offset = 0): array
    {
        if (!empty($filters['to_ship']) && empty($filters['status'])) {
            return self::toShip($filters, $limit, $offset);
        }
        $rows = self::filtered($filters)
            ->orderBy('dt_date', 'DESC')
            ->orderBy('pk_i_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return array_map(static fn (array $row): Order => Order::fromRow($row), $rows);
    }

    /**
     * How many orders the same filter matches, for the admin pager.
     *
     * @param array $filters Same keys as search()
     *
     * @return int
     */
    public static function searchCount(array $filters): int
    {
        if (!empty($filters['to_ship']) && empty($filters['status'])) {
            return self::filtered(array('status' => Order::STATUS_PAID) + $filters)->count()
                + self::filtered(array('status' => Order::STATUS_PENDING) + $filters)->count();
        }

        return self::filtered($filters)->count();
    }

    /**
     * How many orders wait to be shipped: paid goods orders and unpaid cash on delivery
     * ones. One indexed COUNT, kept for a minute and dropped whenever an order is written.
     *
     * @return int
     */
    public static function toShipCount(): int
    {
        $cache = \mindstellar\cache\CacheManager::getInstance();
        $found = false;
        $count = $cache->get(self::TO_SHIP_CACHE_KEY, $found);
        if ($found && is_int($count)) {
            return $count;
        }

        $count = self::table()
            ->where('i_credits', 0)
            ->whereRaw(
                '(s_status = ? OR (s_status = ? AND s_gateway = ?))',
                array(Order::STATUS_PAID, Order::STATUS_PENDING, gateway\CashOnDeliveryGateway::ID)
            )
            ->count();
        $cache->set(self::TO_SHIP_CACHE_KEY, $count, self::TO_SHIP_CACHE_TTL);

        return $count;
    }

    /**
     * The oldest orders still waiting to ship, oldest first.
     *
     * @param int $limit
     *
     * @return Order[]
     */
    public static function oldestToShip(int $limit): array
    {
        $limit = max(1, $limit);
        $parts = array();
        foreach (array(Order::STATUS_PAID, Order::STATUS_PENDING) as $status) {
            $parts = array_merge($parts, self::filtered(array('status' => $status, 'goods' => true, 'to_ship' => true))
                ->orderBy('dt_date', 'ASC')
                ->orderBy('pk_i_id', 'ASC')
                ->limit($limit)
                ->get());
        }
        usort($parts, static fn (array $a, array $b): int => array($a['dt_date'], (int) $a['pk_i_id']) <=> array($b['dt_date'], (int) $b['pk_i_id']));

        return array_map(static fn (array $row): Order => Order::fromRow($row), array_slice($parts, 0, $limit));
    }

    /**
     * Drop the cached "to ship" count, once any write in flight has committed.
     *
     * @return void
     */
    public static function forgetToShip(): void
    {
        Db::afterCommit(static function (): void {
            \mindstellar\cache\CacheManager::getInstance()->delete(self::TO_SHIP_CACHE_KEY);
        });
    }

    /**
     * The "To ship" page: each status read newest first along idx_status_date, then merged.
     *
     * @param array $filters Same keys as search()
     *
     * @return Order[]
     */
    private static function toShip(array $filters, int $limit, int $offset): array
    {
        $take  = max(1, $limit) + max(0, $offset);
        $parts = array();
        foreach (array(Order::STATUS_PAID, Order::STATUS_PENDING) as $status) {
            $parts = array_merge($parts, self::filtered(array('status' => $status) + $filters)
                ->orderBy('dt_date', 'DESC')
                ->orderBy('pk_i_id', 'DESC')
                ->limit($take)
                ->get());
        }
        usort($parts, static fn (array $a, array $b): int => array($b['dt_date'], (int) $b['pk_i_id']) <=> array($a['dt_date'], (int) $a['pk_i_id']));

        return array_map(static fn (array $row): Order => Order::fromRow($row), array_slice($parts, max(0, $offset), max(1, $limit)));
    }

    /**
     * Distinct gateway ids that actually appear on orders. Drives the filter's options,
     * so a gateway whose plugin has since been removed can still be filtered for — its
     * orders are still here and still need reconciling.
     *
     * @return string[]
     */
    public static function knownGateways(): array
    {
        $rows = Db::select(
            'SELECT DISTINCT s_gateway FROM ' . self::tableName() . ' ORDER BY s_gateway ASC'
        );

        return array_map(static fn (array $row): string => (string) $row['s_gateway'], $rows);
    }

    /**
     * Totals for the orders header, over the current filter rather than over everything —
     * a figure that ignores the filter above it is a figure that gets misread.
     *
     * @param array $filters Same keys as search()
     *
     * @return array{count:int, paid:int, pending:int}
     */
    public static function summary(array $filters): array
    {
        return array(
            'count'   => self::searchCount($filters),
            'paid'    => self::searchCount(array_merge($filters, array('status' => Order::STATUS_PAID))),
            'pending' => self::searchCount(array_merge($filters, array('status' => Order::STATUS_PENDING))),
        );
    }

    /**
     * The admin list's filter clauses, shared by search() and searchCount().
     *
     * @param array $filters Same keys as search()
     *
     * @return QueryBuilder
     */
    private static function filtered(array $filters): QueryBuilder
    {
        $q = self::table();

        if (!empty($filters['status'])) {
            $q = $q->where('s_status', $filters['status']);
        }
        if (!empty($filters['gateway'])) {
            $q = $q->where('s_gateway', $filters['gateway']);
        }
        if (!empty($filters['user_id'])) {
            $q = $q->where('fk_i_user_id', (int) $filters['user_id']);
        }
        if (!empty($filters['goods'])) {
            $q = $q->where('i_credits', 0);
        }
        if (!empty($filters['to_ship']) && ($filters['status'] ?? null) === Order::STATUS_PENDING) {
            $q = $q->where('s_gateway', gateway\CashOnDeliveryGateway::ID);
        } elseif (!empty($filters['to_ship']) && ($filters['status'] ?? null) !== Order::STATUS_PAID) {
            $q = $q->whereRaw('1 = 0');
        }

        return $q;
    }

    /**
     * Ledger rows attached to one order: the credit it minted, and the reversal if it was refunded.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function ledgerRows(int $orderId): array
    {
        return Db::table(DB_TABLE_PREFIX . 't_billing_ledger')
            ->where('s_ref_type', 'order')
            ->where('i_ref_id', $orderId)
            ->orderBy('pk_i_id', 'ASC')
            ->get();
    }
}
