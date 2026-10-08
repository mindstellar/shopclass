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

use mindstellar\database\Db;
use mindstellar\database\QueryBuilder;
use mindstellar\model\Resource;

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
     * The bare t_user row, or null.
     *
     * @return array<string,mixed>|null
     */
    public function row(int $id): ?array
    {
        $row = UserStore::find($id);

        return $row === null ? null : Db::stringifyRow($row);
    }

    /**
     * Bare rows of these users, keyed by id.
     *
     * @param int[]    $ids
     * @param string[] $columns must include pk_i_id
     * @param bool     $liveOnly only enabled, confirmed accounts
     *
     * @return array<int,array<string,mixed>>
     */
    public function byIds(array $ids, array $columns, bool $liveOnly = false): array
    {
        return $ids === [] ? [] : array_column(Db::stringifyRows(UserStore::byIds($ids, $columns, $liveOnly)), null, 'pk_i_id');
    }

    /**
     * Load these users' avatars in one query, so each osc_user_avatar_url() after it reads the cache.
     *
     * @param int[] $ids
     */
    public function primeAvatars(array $ids): void
    {
        (new Resource())->primeOwnerCache(Resource::OWNER_USER, $ids);
    }

    /**
     * @return array{pk_i_id:string,b_enabled:string,b_active:string}|null
     */
    public function statusRow(int $id): ?array
    {
        $row = $this->table()->select('pk_i_id', 'b_enabled', 'b_active')->where('pk_i_id', $id)->first();

        return $row === null ? null : Db::stringifyRow($row);
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
        return Db::stringifyRows($this->filtered($active, $enabled, $prefix)->newestBefore($beforeId, $limit));
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
            $like  = QueryBuilder::escapeLike($prefix) . '%';
            $query = $query->whereRaw('(s_email LIKE ? OR s_username LIKE ? OR s_name LIKE ?)', [$like, $like, $like]);
        }

        return $query;
    }

    private function table(): QueryBuilder
    {
        return Db::table(DB_TABLE_PREFIX . 't_user');
    }
}
