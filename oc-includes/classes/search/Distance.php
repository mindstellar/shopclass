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

/**
 * Distance search values: the fixed radius choices, km and miles, and a visitor's point.
 * The search page takes a radius only from BANDS and a point rounded to two decimals
 * (about 1 km), so search URLs stay few enough to cache and never carry where a visitor is.
 */
final class Distance
{
    /** The radius choices, in the site's unit. */
    public const BANDS = array(5, 10, 25, 50, 100);

    public const KM_PER_MILE = 1.609344;

    /**
     * The band nearest to $value, or 0 for none.
     *
     * @param mixed $value
     */
    public static function band($value): int
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            return 0;
        }
        $best = self::BANDS[0];
        foreach (self::BANDS as $band) {
            if (abs($band - (float) $value) < abs($best - (float) $value)) {
                $best = $band;
            }
        }

        return $best;
    }

    public static function toKm(float $value, string $unit): float
    {
        return $unit === 'mi' ? $value * self::KM_PER_MILE : $value;
    }

    public static function fromKm(float $km, string $unit): float
    {
        return $unit === 'mi' ? $km / self::KM_PER_MILE : $km;
    }

    /**
     * A valid latitude and longitude rounded to two decimals, or null.
     *
     * @param mixed $lat
     * @param mixed $lng
     *
     * @return array{0:float,1:float}|null
     */
    public static function point($lat, $lng): ?array
    {
        if (!self::validPoint($lat, $lng)) {
            return null;
        }

        return array(round((float) $lat, 2), round((float) $lng, 2));
    }

    /**
     * True when both values are finite numbers within ±90 latitude and ±180 longitude.
     */
    public static function validPoint(mixed $lat, mixed $lng): bool
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;

        return is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180;
    }
}
