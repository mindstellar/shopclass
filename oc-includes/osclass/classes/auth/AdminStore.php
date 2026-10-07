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

namespace mindstellar\auth;

use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Reads and writes on t_admin for the sign-in, password, two-step and API key code.
 * The legacy Admin model keeps its own methods.
 */
final class AdminStore extends Model
{
    protected const TABLE = 't_admin';

    /**
     * One admin row, or null.
     *
     * @param string[] $columns
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id, array $columns = []): ?array
    {
        $query = self::table();
        if ($columns !== []) {
            $query = $query->select(...$columns);
        }

        return $query->where('pk_i_id', $id)->first();
    }

    /**
     * The first admin whose $column equals $value, or null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function findBy(string $column, int|string $value): ?array
    {
        return self::table()->where($column, $value)->first();
    }

    /**
     * The admin named by id or by username, with the columns the API key commands use.
     *
     * @return array{pk_i_id:int|string,s_username:string,b_moderator:int|string}|null
     * @throws \mindstellar\database\DbException
     */
    public static function keyOwner(int|string $who, bool $byId): ?array
    {
        return Db::selectOne(
            'SELECT pk_i_id, s_username, b_moderator FROM ' . self::tableName() . ' WHERE ' . ($byId ? 'pk_i_id' : 's_username') . ' = ?',
            [$who]
        );
    }

    /**
     * @param array<string,mixed> $values
     *
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function update(int $id, array $values): int
    {
        return self::table()->where('pk_i_id', $id)->update($values);
    }

    /**
     * The sign-out stamp column of one admin, or null for no such admin.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function stampRow(int $id): ?array
    {
        return Db::selectOne('SELECT ' . AuthStamp::COLUMN . ' FROM ' . self::tableName() . ' WHERE pk_i_id = ?', [$id]);
    }

    /**
     * Usernames by id.
     *
     * @param int[] $ids
     *
     * @return array<int,string>
     * @throws \mindstellar\database\DbException
     */
    public static function usernames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Db::select(
            'SELECT pk_i_id, s_username FROM ' . self::tableName() . ' WHERE pk_i_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['pk_i_id']] = (string) $row['s_username'];
        }

        return $out;
    }

    /**
     * Every admin for the System info list, with s_2fa unless the column is not there yet.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function listing(bool $withTwoFactor): array
    {
        return Db::select(
            'SELECT pk_i_id, s_name, s_username, b_moderator' . ($withTwoFactor ? ', s_2fa' : '') . ' FROM ' . self::tableName() . ' ORDER BY pk_i_id'
        );
    }

    /**
     * @return array<string,mixed>|null the s_2fa column of one admin
     * @throws \mindstellar\database\DbException
     */
    public static function twoFactorRow(int $id): ?array
    {
        return Db::selectOne('SELECT s_2fa FROM ' . self::tableName() . ' WHERE pk_i_id = ?', [$id]);
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function setTwoFactor(int $id, ?string $value): void
    {
        Db::execute('UPDATE ' . self::tableName() . ' SET s_2fa = ? WHERE pk_i_id = ?', [$value, $id]);
    }

    /**
     * Write s_2fa only while it still holds $expected.
     *
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function swapTwoFactor(int $id, string $value, ?string $expected): int
    {
        return Db::execute('UPDATE ' . self::tableName() . ' SET s_2fa = ? WHERE pk_i_id = ? AND s_2fa = ?', [$value, $id, $expected]);
    }

}
