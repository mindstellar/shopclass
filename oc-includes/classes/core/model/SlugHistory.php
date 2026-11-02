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

namespace mindstellar\model;

use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Old slugs of categories, regions and cities, so links to a renamed one still reach it.
 * A category slug belongs to a locale; a location slug has the empty locale.
 */
final class SlugHistory extends Model
{
    public const TABLE = 't_slug_history';

    public const CATEGORY = 'CATEGORY';
    public const REGION   = 'REGION';
    public const CITY     = 'CITY';

    /**
     * Keep $oldSlug pointing at the row, and stop $newSlug redirecting now that it is live.
     * $locale is the category's locale; a region or city passes ''.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function record(string $type, int $id, string $oldSlug, string $newSlug, string $locale = '', ?string $at = null): void
    {
        self::table()->where('e_type', $type)->where('s_slug', $newSlug)->where('fk_c_locale_code', $locale)->delete();
        if ($oldSlug === '' || $oldSlug === $newSlug) {
            return;
        }
        Db::execute(
            'INSERT INTO ' . self::tableName() . ' (e_type, fk_i_id, fk_c_locale_code, s_slug, dt_date) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE fk_i_id = VALUES(fk_i_id), dt_date = VALUES(dt_date)',
            array($type, $id, $locale, $oldSlug, $at ?? date('Y-m-d H:i:s'))
        );
    }

    /**
     * The row an old slug last belonged to, in any locale, or null.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function ownerOf(string $type, string $slug): ?int
    {
        $id = Db::scalar(
            'SELECT fk_i_id FROM ' . self::tableName() . ' WHERE e_type = ? AND s_slug = ? ORDER BY dt_date DESC LIMIT 1',
            array($type, $slug)
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Drop the old slugs of a deleted row, so they stop redirecting to it. Nothing else does:
     * fk_i_id points into one of three tables, so the table has no foreign key.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function forget(string $type, int $id): void
    {
        self::table()->where('e_type', $type)->where('fk_i_id', $id)->delete();
    }
}
