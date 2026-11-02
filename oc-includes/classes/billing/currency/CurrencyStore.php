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

namespace mindstellar\currency;

use mindstellar\base\Model;

/**
 * t_currency reads for the currency service. The legacy Currency model keeps its own methods.
 */
final class CurrencyStore extends Model
{
    protected const TABLE = 't_currency';

    /**
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function find(string $code): ?array
    {
        return self::table()->where('pk_c_code', $code)->first();
    }

    /**
     * Enabled currencies by code.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function enabled(): array
    {
        return self::table()->where('b_enabled', 1)->orderBy('pk_c_code')->get();
    }
}
