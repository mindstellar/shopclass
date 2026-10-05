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

namespace mindstellar\api\auth;

/**
 * Admin rows as the API reads them, each loaded once per request: the admin an admin key
 * acts for, so core code and its activity log see who made the change.
 */
final class AdminRows
{
    /** @var array<int,array<string,mixed>|null> */
    private array $rows = [];

    /** @var \Closure(int): ?array<string,mixed> */
    private \Closure $load;

    /**
     * @param callable|null $load (admin id) => the t_admin row, or null; the table by default
     */
    public function __construct(?callable $load = null)
    {
        $this->load = \Closure::fromCallable($load ?? [self::class, 'fromTable']);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if (!array_key_exists($id, $this->rows)) {
            $this->rows[$id] = $id > 0 ? ($this->load)($id) : null;
        }

        return $this->rows[$id];
    }

    /**
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    private static function fromTable(int $id): ?array
    {
        $row = osc_db_table(DB_TABLE_PREFIX . 't_admin')
            ->select('pk_i_id', 's_name', 's_username', 's_email', 'b_moderator')
            ->where('pk_i_id', $id)
            ->first();

        return $row === null ? null : osc_db_stringify_row($row);
    }
}
