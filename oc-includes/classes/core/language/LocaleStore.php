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

namespace mindstellar\language;

use mindstellar\base\Model;
use mindstellar\cache\CacheGroup;

/**
 * t_locale writes for the languages screen. The legacy OSCLocale model keeps its own methods.
 * Every write clears the `locale` cache group, as the model's writes do.
 */
final class LocaleStore extends Model
{
    protected const TABLE = 't_locale';

    /** Columns an edit may change; the code itself is the key and never changes. */
    public const COLUMNS = array(
        's_name', 's_short_name', 's_description', 's_version', 's_direction', 's_author_name',
        's_author_url', 's_currency_format', 's_dec_point', 's_thousands_sep', 'i_num_dec',
        's_date_format', 's_stop_words', 'b_enabled', 'b_enabled_bo',
    );

    /**
     * Write some columns of one locale; unknown columns are dropped.
     *
     * @param array<string,mixed> $values
     *
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public static function update(string $code, array $values): int
    {
        $values = array_intersect_key($values, array_flip(self::COLUMNS));
        if ($values === array()) {
            return 0;
        }
        $changed = self::table()->where('pk_c_code', $code)->update($values);
        CacheGroup::invalidate('locale');

        return $changed;
    }
}
