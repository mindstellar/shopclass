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

use mindstellar\database\Db;
use mindstellar\user\UserQuery;
use mindstellar\user\UserStore;

/**
 * User rows as the API reads them, each loaded once per request: checking an access token,
 * taking on the user's identity and the account endpoints share the same row.
 */
final class UserRows extends MemoisedRows
{
    /**
     * @param callable|null $load (id) => the row, or null; the table by default
     */
    public function __construct(?callable $load = null)
    {
        parent::__construct($load ?? [new UserQuery(), 'bareRow']);
    }

    /**
     * The row, and whether the refresh family has a live token, in one query. Null row for no user.
     *
     * @return array{0: array<string,mixed>|null, 1: bool}
     * @throws \mindstellar\database\DbException
     */
    public function findWithFamily(int $id, string $family): array
    {
        $row = $id > 0 ? UserStore::findWithFamily($id, $family) : null;
        if ($row === null) {
            return [null, false];
        }
        $live = (int) $row['b_family_live'] === 1;
        unset($row['b_family_live']);
        $row = Db::stringifyRow($row);
        $this->rows[$id] = $row;

        return [$row, $live];
    }

    /**
     * Read the row again next time, after it changed.
     */
    public function forget(int $id): void
    {
        unset($this->rows[$id]);
    }
}
