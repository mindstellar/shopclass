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

/**
 * Admin rows as the API reads them, each loaded once per request: the admin an admin key
 * acts for, so core code and its activity log see who made the change.
 */
final class AdminRows extends MemoisedRows
{
    /**
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    protected static function fromTable(int $id): ?array
    {
        $row = \mindstellar\auth\AdminStore::find($id, ['pk_i_id', 's_name', 's_username', 's_email', 'b_moderator']);

        return $row === null ? null : Db::stringifyRow($row);
    }
}
