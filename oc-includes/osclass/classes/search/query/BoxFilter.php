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
 * Listings whose search point on t_item_location is inside a box of west, south, east and
 * north edges, such as the area a map shows. A box with west past east crosses the date line.
 */
final class BoxFilter
{
    /** Decimals a box edge keeps: about 11 m. */
    public const DECIMALS = 4;

    /** Longest bbox text taken, in bytes. */
    public const MAX_LENGTH = 100;

    /** @var array{0:float,1:float,2:float,3:float}|null west, south, east, north */
    private ?array $box = null;

    /**
     * Keep only listings inside this box.
     */
    public function within(float $west, float $south, float $east, float $north): void
    {
        $this->box = array($west, $south, $east, $north);
    }

    public function used(): bool
    {
        return $this->box !== null;
    }

    /**
     * The box, for toJson() and the result cache key.
     *
     * @return array{west:float,south:float,east:float,north:float}|null
     */
    public function recorded(): ?array
    {
        if ($this->box === null) {
            return null;
        }

        return array('west' => $this->box[0], 'south' => $this->box[1], 'east' => $this->box[2], 'north' => $this->box[3]);
    }

    /**
     * Add the box condition. The latitude range leads, so the index on the point serves it.
     */
    public function apply(Statement $statement): void
    {
        if ($this->box === null) {
            return;
        }
        [$west, $south, $east, $north] = $this->box;
        $column = DB_TABLE_PREFIX . 't_item_location.d_geo_';
        $lngs   = $west <= $east ? array(array($west, $east)) : array(array($west, 180.0), array(-180.0, $east));
        $ranges = array();
        $bound  = array($south, $north);
        foreach ($lngs as $range) {
            $ranges[] = $column . 'long BETWEEN ? AND ?';
            array_push($bound, $range[0], $range[1]);
        }
        $statement->where('(' . $column . 'lat BETWEEN ? AND ? AND (' . implode(' OR ', $ranges) . '))', $bound);
    }

    /**
     * A box from "west,south,east,north", or null when it is not four numbers with
     * longitudes within ±180, latitudes within ±90 and south not above north. Each edge is
     * rounded to DECIMALS after the range check.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public static function parse(mixed $value): ?array
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }
        $parts = explode(',', $value);
        if (count($parts) !== 4) {
            return null;
        }
        $box = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if (!is_numeric($part) || !is_finite((float) $part)) {
                return null;
            }
            $box[] = (float) $part;
        }
        [$west, $south, $east, $north] = $box;
        if (abs($west) > 180 || abs($east) > 180 || abs($south) > 90 || abs($north) > 90 || $south > $north) {
            return null;
        }

        return array(self::edge($west), self::edge($south), self::edge($east), self::edge($north));
    }

    /**
     * The box as "west,south,east,north", the form a search URL and a saved search carry.
     *
     * @param array{0:float,1:float,2:float,3:float} $box
     */
    public static function format(array $box): string
    {
        return implode(',', array_map(static function (float $edge): string {
            $text = rtrim(rtrim(sprintf('%.' . self::DECIMALS . 'F', $edge), '0'), '.');

            return $text === '-0' ? '0' : $text;
        }, $box));
    }

    private static function edge(float $value): float
    {
        $value = round($value, self::DECIMALS);

        return $value == 0.0 ? 0.0 : $value;
    }
}
