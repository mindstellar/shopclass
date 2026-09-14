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
use mindstellar\database\QueryBuilder;

/**
 * Persistence for t_billing_package -- the price list an admin sells credits from.
 *
 * A package is a plain array, not a domain object: it is edited and removed by an
 * admin, not appended to like the ledger, so it carries no history requirement of its
 * own. Orders::create() takes its amount and credits straight from a row read here, so
 * checkout never reads a price the request supplied.
 *
 * @package mindstellar\billing
 */
final class Packages
{
    /** Unprefixed table name. */
    private const TABLE = 't_billing_package';

    /**
     * Packages offered at checkout.
     *
     * @return array[] enabled packages, in checkout order
     */
    public static function enabled(): array
    {
        return self::table()
            ->where('b_enabled', 1)
            ->orderBy('i_position', 'ASC')
            ->orderBy('pk_i_id', 'ASC')
            ->get();
    }

    /**
     * Packages for the admin price list.
     *
     * @return array[] every package, including disabled ones, for the admin list
     */
    public static function all(): array
    {
        return self::table()
            ->orderBy('i_position', 'ASC')
            ->orderBy('pk_i_id', 'ASC')
            ->get();
    }

    /**
     * One package row, or null when no package has that id.
     *
     * @param int $id
     *
     * @return array|null
     */
    public static function find(int $id): ?array
    {
        return self::table()->where('pk_i_id', $id)->first();
    }

    /**
     * @param array $data 's_name', 'i_amount', 's_currency', 'i_credits', and
     *                     optionally 'i_position', 'b_enabled'
     *
     * @return int the new package id
     * @throws InvalidArgumentException on an invalid row
     */
    public static function create(array $data): int
    {
        $row           = self::validated($data);
        $row['dt_date'] = date('Y-m-d H:i:s');

        return self::table()->insert($row);
    }

    /**
     * Replace a package's fields with a validated row.
     *
     * @param int   $id
     * @param array $data Same keys as create()
     *
     * @return bool whether the row changed
     * @throws InvalidArgumentException on an invalid row
     */
    public static function update(int $id, array $data): bool
    {
        return self::table()->where('pk_i_id', $id)->update(self::validated($data)) > 0;
    }

    /**
     * Remove a package. Orders already placed against it keep their own figures.
     *
     * @param int $id
     *
     * @return bool whether a row was removed
     */
    public static function delete(int $id): bool
    {
        return self::table()->where('pk_i_id', $id)->delete() > 0;
    }

    /**
     * A price list entry means nothing with an empty name, a negative amount, fewer
     * than one credit, or a currency that is not a real ISO 4217 code -- every one of
     * those would otherwise reach Orders::create() unquestioned.
     *
     * @param array $data
     *
     * @return array the row to write
     * @throws InvalidArgumentException
     */
    private static function validated(array $data): array
    {
        $name = trim((string) ($data['s_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Packages: name cannot be empty');
        }

        $amount = (int) ($data['i_amount'] ?? 0);
        if ($amount < 0) {
            throw new InvalidArgumentException('Packages: amount cannot be negative');
        }

        $credits = (int) ($data['i_credits'] ?? 0);
        if ($credits < 1) {
            throw new InvalidArgumentException('Packages: credits must be at least 1');
        }

        $currency = strtoupper((string) ($data['s_currency'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Packages: currency must be an ISO 4217 code');
        }

        return array(
            's_name'     => $name,
            'i_amount'   => $amount,
            's_currency' => $currency,
            'i_credits'  => $credits,
            'i_position' => max(0, (int) ($data['i_position'] ?? 0)),
            'b_enabled'  => array_key_exists('b_enabled', $data) ? (empty($data['b_enabled']) ? 0 : 1) : 1,
        );
    }

    /**
     * Query builder bound to the package table.
     *
     * @return QueryBuilder
     */
    private static function table(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . self::TABLE);
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Packages.php */
