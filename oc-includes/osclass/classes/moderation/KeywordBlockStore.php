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

namespace mindstellar\moderation;

use mindstellar\base\Model;

/**
 * t_keyword_block writes for the keyword blocklist screen. The legacy KeywordBlock model keeps
 * its own methods.
 */
final class KeywordBlockStore extends Model
{
    protected const TABLE = 't_keyword_block';

    /**
     * Add a keyword, or rewrite one when $id is given. The date is set to now either way.
     *
     * @return int the row's id
     * @throws \mindstellar\database\DbException
     */
    public static function save(?int $id, string $keyword, string $scope, bool $substring): int
    {
        $values = array(
            's_keyword'   => $keyword,
            's_scope'     => $scope,
            'b_substring' => $substring ? 1 : 0,
            'dt_date'     => date('Y-m-d H:i:s'),
        );
        if ($id === null) {
            return self::table()->insert($values);
        }
        self::table()->where('pk_i_id', $id)->update($values);

        return $id;
    }

    /**
     * @return bool whether a row was deleted
     * @throws \mindstellar\database\DbException
     */
    public static function delete(int $id): bool
    {
        return self::table()->where('pk_i_id', $id)->delete() > 0;
    }
}
