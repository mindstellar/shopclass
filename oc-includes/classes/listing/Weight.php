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

namespace mindstellar\listing;

/**
 * Product weights: stored in grams, shown and typed in the site's unit, metric (g, kg) or
 * imperial (oz, lb). The osc_weight_*() helpers call this.
 */
final class Weight
{
    private function __construct()
    {
    }

    /** 'metric' or 'imperial', from Settings → Billing → Shop. */
    public static function unit(): string
    {
        return osc_get_preference('weight_unit', 'osclass') === 'imperial' ? 'imperial' : 'metric';
    }

    /** Grams in one g, kg, oz or lb; an unknown unit counts as grams. */
    public static function gramsPer(string $unit): float
    {
        return match ($unit) {
            'kg'    => 1000.0,
            'oz'    => 28.349523125,
            'lb'    => 453.59237,
            default => 1.0,
        };
    }

    /** A weight in one unit, with at most two decimals and no trailing zeros: "1.5". */
    public static function in(int $grams, string $unit): string
    {
        return rtrim(rtrim(number_format($grams / self::gramsPer($unit), 2, '.', ''), '0'), '.');
    }

    /** A weight for people to read, in the larger unit once it reaches one: "250 g", "1.5 kg", "8 oz", "2 lb". */
    public static function display(int $grams): string
    {
        if (self::unit() === 'imperial') {
            return $grams < self::gramsPer('lb') ? sprintf(__('%s oz'), self::in($grams, 'oz')) : sprintf(__('%s lb'), self::in($grams, 'lb'));
        }

        return $grams < 1000 ? sprintf(__('%s g'), $grams) : sprintf(__('%s kg'), self::in($grams, 'kg'));
    }

    /** A stored weight as a form field shows it, in the small unit: grams, or ounces on an imperial site. */
    public static function input(int $grams): string
    {
        return self::unit() === 'imperial' ? self::in($grams, 'oz') : (string) $grams;
    }

    /** A typed weight in the small unit, as grams; 0 for anything that is not a number. */
    public static function parse(mixed $value): int
    {
        $text = str_replace(',', '.', trim((string) $value));
        if (preg_match('/^\d{1,12}(\.\d{1,6})?$/', $text) !== 1) {
            return 0;
        }

        return (int) round((float) $text * (self::unit() === 'imperial' ? self::gramsPer('oz') : 1));
    }

    /** How many started kilograms, or pounds on an imperial site, a weight reaches. */
    public static function started(int $grams, ?bool $imperial = null): int
    {
        $per = ($imperial ?? self::unit() === 'imperial') ? (int) round(16 * self::gramsPer('oz')) : 1000;

        return intdiv(max(0, $grams) + $per - 1, $per);
    }
}
