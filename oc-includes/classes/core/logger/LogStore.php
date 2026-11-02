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

namespace mindstellar\logger;

use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Writes to the activity log table. LogQuery holds the reads.
 */
final class LogStore extends Model
{
    protected const TABLE = 't_log';

    /**
     * Empty the activity log.
     *
     * @return int entries removed
     * @throws \mindstellar\database\DbException
     */
    public static function clearAll(): int
    {
        return Db::execute('DELETE FROM ' . self::tableName());
    }
}
