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

/**
 * Activity log reads. The legacy Log model keeps the writes.
 */
final class LogQuery
{
    /**
     * The latest background-job rows, newest first.
     *
     * @param string[] $actions narrow to these actions; empty for all
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function jobs(array $actions, int $limit): array
    {
        $query = osc_db_table(DB_TABLE_PREFIX . 't_log')
            ->select('dt_date', 's_action', 'fk_i_id', 's_data')
            ->where('s_section', 'jobs');
        if ($actions !== array()) {
            $query = $query->whereIn('s_action', $actions);
        }

        return $query->orderBy('dt_date', 'DESC')->limit($limit)->get();
    }
}
