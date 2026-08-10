<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

use InvalidArgumentException;
use mindstellar\database\DbException;
use mindstellar\database\QueryBuilder;

/**
 * Persistence for t_billing_order.
 *
 * Queries run through the parameterized osc_db_* / QueryBuilder API; this does not
 * extend the legacy DAO layer.
 *
 * @package mindstellar\billing
 */
final class Orders
{
    /** Unprefixed table name. */
    private const TABLE = 't_billing_order';

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
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
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

        return new Order($id, $userId, $gateway, null, $amount, $currency, $credits, Order::STATUS_PENDING, $meta, $now);
    }

    public static function find(int $id): ?Order
    {
        $row = self::table()->where('pk_i_id', $id)->first();

        return $row === null ? null : Order::fromRow($row);
    }

    /**
     * Look an order up by the gateway's own reference. This is how a webhook that
     * carries only the provider's id finds its way back to our record.
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
     * Guarded on the current status being pending, so a replayed webhook cannot
     * re-settle an order that already settled -- the update matches no row and
     * returns false. The caller treats that as "already handled", not as an error.
     *
     * @return bool whether this call was the one that changed the row
     */
    public static function settle(int $id, string $status, ?string $externalRef = null): bool
    {
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

        try {
            $changed = self::table()
                ->where('pk_i_id', $id)
                ->where('s_status', Order::STATUS_PENDING)
                ->update($data);
        } catch (DbException $e) {
            // uq_gateway_ref: this external reference is already attached to another
            // order, which means the callback is being replayed against the wrong
            // record. Refuse rather than overwrite.
            return false;
        }

        return $changed === 1;
    }

    /**
     * A refund arrives after settlement, so it is the one transition that starts
     * from paid rather than pending.
     */
    public static function refund(int $id): bool
    {
        $changed = self::table()
            ->where('pk_i_id', $id)
            ->where('s_status', Order::STATUS_PAID)
            ->update(array('s_status' => Order::STATUS_REFUNDED));

        return $changed === 1;
    }

    /**
     * @return Order[] newest first
     */
    public static function forUser(int $userId, int $limit = 25, int $offset = 0): array
    {
        $rows = self::table()
            ->where('fk_i_user_id', $userId)
            ->orderBy('dt_date', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return array_map(static fn (array $row): Order => Order::fromRow($row), $rows);
    }

    /**
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
     * @param array $filters 'status', 'gateway', 'user_id' — any may be omitted
     *
     * @return Order[]
     */
    public static function search(array $filters, int $limit = 25, int $offset = 0): array
    {
        $rows = self::filtered($filters)
            ->orderBy('dt_date', 'DESC')
            ->orderBy('pk_i_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return array_map(static fn (array $row): Order => Order::fromRow($row), $rows);
    }

    public static function searchCount(array $filters): int
    {
        return self::filtered($filters)->count();
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
        $rows = osc_db_select(
            'SELECT DISTINCT s_gateway FROM ' . DB_TABLE_PREFIX . self::TABLE . ' ORDER BY s_gateway ASC'
        );

        return array_map(static fn (array $row): string => (string) $row['s_gateway'], $rows);
    }

    /**
     * Totals for the orders header, over the current filter rather than over everything —
     * a figure that ignores the filter above it is a figure that gets misread.
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

        return $q;
    }

    private static function table(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . self::TABLE);
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Orders.php */
