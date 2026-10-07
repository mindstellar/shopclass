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
 * User rows as the API reads them, each loaded once per request: checking an access token,
 * taking on the user's identity and the account endpoints share the same row.
 */
final class UserRows
{
    /** @var array<int,array<string,mixed>|null> */
    private array $rows = [];

    /** @var \Closure(int): ?array<string,mixed> */
    private \Closure $load;

    /**
     * @param callable|null $load (user id) => the t_user row, or null; the table by default
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
     * Read the row again next time, after it changed.
     */
    public function forget(int $id): void
    {
        unset($this->rows[$id]);
    }

    /**
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    private static function fromTable(int $id): ?array
    {
        $row = \mindstellar\user\UserStore::find($id);

        return $row === null ? null : osc_db_stringify_row($row);
    }
}
