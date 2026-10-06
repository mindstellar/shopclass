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

namespace mindstellar\widgets;

use mindstellar\base\Model;

/**
 * t_widget writes for the Appearance > Widgets screen. The legacy Widget model keeps its
 * own methods.
 */
final class WidgetStore extends Model
{
    protected const TABLE = 't_widget';

    /**
     * @param array<string,mixed> $row
     *
     * @return int the new widget id
     * @throws \mindstellar\database\DbException
     */
    public static function add(array $row): int
    {
        return (int) self::table()->insert($row);
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function moveTo(int $id, string $location): void
    {
        self::table()->where('pk_i_id', $id)->update(array('s_location' => $location));
    }
}
