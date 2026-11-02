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

namespace mindstellar\search;

use mindstellar\base\Model;

/**
 * Writes to t_latest_searches. The legacy LatestSearches model keeps its own methods.
 */
final class LatestSearchStore extends Model
{
    protected const TABLE = 't_latest_searches';

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function record(string $pattern, string $date): void
    {
        self::table()->insert(['s_search' => $pattern, 'd_date' => $date]);
    }
}
