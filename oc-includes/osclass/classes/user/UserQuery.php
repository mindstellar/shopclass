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

namespace mindstellar\user;

use mindstellar\database\QueryBuilder;

/**
 * User reads: whether a user exists, their status columns, and every user newest first with
 * the users screen's filters, paged by id.
 */
final class UserQuery
{
    public function exists(int $id): bool
    {
        return $id > 0 && $this->table()->where('pk_i_id', $id)->count() > 0;
    }

    /**
     * The user's row with its descriptions under `locale`, as the User model reads it.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $user = \User::getInstance()->findByPrimaryKey($id);

        return is_array($user) && $user !== [] ? $user : null;
    }

    /**
     * @return array{pk_i_id:string,b_enabled:string,b_active:string}|null
     */
    public function statusRow(int $id): ?array
    {
        $row = $this->table()->select('pk_i_id', 'b_enabled', 'b_active')->where('pk_i_id', $id)->first();

        return $row === null ? null : osc_db_stringify_row($row);
    }

    /**
     * Bare t_user rows, newest first, below $beforeId when given.
     *
     * @param bool|null $active  confirmed or not; either when null
     * @param bool|null $enabled not blocked or blocked; either when null
     * @param string    $prefix  e-mail, username or name starting with this; '' for any
     *
     * @return array<int,array<string,mixed>>
     */
    public function newest(?bool $active, ?bool $enabled, string $prefix, ?int $beforeId, int $limit): array
    {
        $query = $this->filtered($active, $enabled, $prefix);
        if ($beforeId !== null) {
            $query = $query->where('pk_i_id', '<', $beforeId);
        }

        return osc_db_stringify_rows($query->orderBy('pk_i_id', 'DESC')->limit($limit)->get());
    }

    public function count(?bool $active, ?bool $enabled, string $prefix): int
    {
        return $this->filtered($active, $enabled, $prefix)->count();
    }

    private function filtered(?bool $active, ?bool $enabled, string $prefix): QueryBuilder
    {
        $query = $this->table();
        foreach (['b_active' => $active, 'b_enabled' => $enabled] as $column => $value) {
            if ($value !== null) {
                $query = $query->where($column, $value ? 1 : 0);
            }
        }
        if ($prefix !== '') {
            $like  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
            $query = $query->whereRaw('(s_email LIKE ? OR s_username LIKE ? OR s_name LIKE ?)', [$like, $like, $like]);
        }

        return $query;
    }

    private function table(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . 't_user');
    }
}
