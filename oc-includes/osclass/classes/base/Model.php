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

namespace mindstellar\base;

use mindstellar\database\Db;
use mindstellar\database\QueryBuilder;
use mindstellar\database\UtcDatetime;

/**
 * Base for models on the parameterized DB API. A model names its table in TABLE, without the
 * prefix. Legacy models extend DAO instead.
 */
abstract class Model
{
    protected const TABLE = '';

    /**
     * The prefixed table name, for hand-written SQL.
     */
    protected static function tableName(): string
    {
        return DB_TABLE_PREFIX . static::TABLE;
    }

    /**
     * A query on this model's table. Static so static stores can use it too.
     */
    protected static function table(): QueryBuilder
    {
        return Db::table(static::tableName());
    }

    /**
     * A Unix time as the UTC DATETIME string the tables store; null stays null.
     */
    protected static function datetime(?int $time): ?string
    {
        return $time === null ? null : UtcDatetime::format($time);
    }
}
