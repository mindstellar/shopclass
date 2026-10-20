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

namespace mindstellar\search\query;

/**
 * Listings within a distance of a point, and their distance from it, on t_item_location's
 * search point. A box around the point uses the index; the exact distance then trims the
 * box to a circle.
 */
final class RadiusFilter
{
    public const EARTH_KM = 6371.0;

    private ?float $lat = null;
    private ?float $lng = null;
    private float $km   = 0.0;

    /**
     * Keep only listings within $km of this point, and measure each one from it.
     */
    public function near(float $lat, float $lng, float $km): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->km  = max(0.0, $km);
    }

    public function used(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /**
     * The point and radius, for toJson().
     *
     * @return array{lat:float,lng:float,km:float}|null
     */
    public function recorded(): ?array
    {
        return $this->used() ? array('lat' => (float) $this->lat, 'lng' => (float) $this->lng, 'km' => $this->km) : null;
    }

    /**
     * Add the radius condition, and the distance column unless this is a count.
     */
    public function apply(Statement $statement, bool $count): void
    {
        if ($this->lat === null || $this->lng === null) {
            return;
        }
        [$distance, $params] = $this->distance();
        [$lat, $lngs]        = self::box($this->lat, $this->lng, $this->km);
        $column              = DB_TABLE_PREFIX . 't_item_location.d_geo_';
        $sql                 = '(' . $column . 'lat BETWEEN ? AND ?';
        $bound               = array($lat[0], $lat[1]);
        $ranges              = array();
        foreach ($lngs as $range) {
            $ranges[] = $column . 'long BETWEEN ? AND ?';
            array_push($bound, $range[0], $range[1]);
        }
        $sql .= ' AND (' . implode(' OR ', $ranges) . ') AND ' . $distance . ' <= ?)';
        array_push($bound, ...$params);
        $bound[] = $this->km;
        $statement->where($sql, $bound);
        if (!$count) {
            $statement->selectBound($distance . ' AS distance', $params);
        }
    }

    /**
     * The latitude range and the one or two longitude ranges holding every point within $km.
     *
     * @return array{0:array{0:float,1:float},1:array<int,array{0:float,1:float}>}
     */
    public static function box(float $lat, float $lng, float $km): array
    {
        $angle  = $km / self::EARTH_KM;
        $latMin = max(-90.0, $lat - rad2deg($angle));
        $latMax = min(90.0, $lat + rad2deg($angle));
        $reach  = sin($angle) / max(cos(deg2rad($lat)), 1e-9);
        // Near a pole, or for a radius past a quarter of the earth, every longitude is in reach.
        if ($latMin <= -90.0 || $latMax >= 90.0 || $reach >= 1.0) {
            return array(array($latMin, $latMax), array(array(-180.0, 180.0)));
        }
        $delta  = rad2deg(asin($reach));
        $lngMin = $lng - $delta;
        $lngMax = $lng + $delta;
        // Across the date line the box is two ranges.
        if ($lngMin < -180.0) {
            return array(array($latMin, $latMax), array(array($lngMin + 360.0, 180.0), array(-180.0, $lngMax)));
        }
        if ($lngMax > 180.0) {
            return array(array($latMin, $latMax), array(array($lngMin, 180.0), array(-180.0, $lngMax - 360.0)));
        }

        return array(array($latMin, $latMax), array(array($lngMin, $lngMax)));
    }

    /**
     * Great-circle distance in km from the point; the clamp keeps ACOS from returning NULL
     * when rounding takes its argument just past 1 for a listing at the point itself.
     *
     * @return array{0:string,1:array<int,float>}
     */
    private function distance(): array
    {
        $column = DB_TABLE_PREFIX . 't_item_location.d_geo_';

        return array(
            self::EARTH_KM . ' * ACOS(GREATEST(-1.0, LEAST(1.0, COS(RADIANS(?)) * COS(RADIANS(' . $column . 'lat))'
            . ' * COS(RADIANS(' . $column . 'long) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(' . $column . 'lat)))))',
            array((float) $this->lat, (float) $this->lng, (float) $this->lat),
        );
    }
}
