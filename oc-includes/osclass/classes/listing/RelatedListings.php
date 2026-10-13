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
 * Listings like a given one, best first. A plugin may pick them through the
 * `item_related_ids` filter; core picks the newest live listings in the same
 * category, the ones sharing the most title words first.
 */
final class RelatedListings
{
    public const MAX_LIMIT = 20;

    /** The filters and their defaults. */
    public const FILTERS = [
        'same_category' => true,
        'same_country'  => false,
        'same_region'   => false,
        'with_photos'   => false,
        'premium_only'  => false,
    ];

    /** Title words core matches on. */
    private const WORDS = 5;

    /**
     * Live listing rows like $itemId, best first, without the listing itself.
     *
     * @param array<string,mixed> $filters names from FILTERS; unknown names are ignored
     *
     * @return array<int,array<string,mixed>> bare t_item rows
     * @throws \mindstellar\database\DbException
     */
    public static function rows(int $itemId, int $limit = 6, array $filters = []): array
    {
        $limit   = max(1, min(self::MAX_LIMIT, $limit));
        $filters = self::filters($filters);

        $picked = osc_apply_filter('item_related_ids', null, $itemId, $limit, $filters);
        $ids    = is_array($picked) ? self::cleanIds($picked, $itemId) : self::defaultIds($itemId, $limit, $filters);

        return array_slice((new ListingQuery())->findLive($ids), 0, $limit);
    }

    /**
     * @param array<string,mixed> $filters
     *
     * @return array<string,bool>
     */
    public static function filters(array $filters): array
    {
        $out = self::FILTERS;
        foreach (array_intersect_key($filters, self::FILTERS) as $name => $value) {
            $out[$name] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /**
     * Positive listing ids from a plugin, each once, in order, without $without.
     *
     * @param array<mixed> $ids
     *
     * @return int[]
     */
    public static function cleanIds(array $ids, int $without = 0): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = is_numeric($id) ? (int) $id : 0;
            if ($id > 0 && $id !== $without) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * @param array<string,bool> $filters
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    private static function defaultIds(int $itemId, int $limit, array $filters): array
    {
        $query  = new ListingQuery();
        $source = $query->find($itemId);
        if ($source === null) {
            return [];
        }
        $place = $query->statsAndLocations([$itemId])[0] ?? [];
        $match = [
            'premium' => $filters['premium_only'],
            'photos'  => $filters['with_photos'],
        ];
        if ($filters['same_category']) {
            $match['category'] = (int) $source['fk_i_category_id'];
        }
        if ($filters['same_country'] && (string) ($place['fk_c_country_code'] ?? '') !== '') {
            $match['country'] = (string) $place['fk_c_country_code'];
        }
        if ($filters['same_region'] && (int) ($place['fk_i_region_id'] ?? 0) > 0) {
            $match['region'] = (int) $place['fk_i_region_id'];
        }

        return $query->related($itemId, $match, self::words(self::title($query, $itemId)), $limit);
    }

    /**
     * The listing's title in the visitor's language, or in any language it has.
     */
    private static function title(ListingQuery $query, int $itemId): string
    {
        $titles = [];
        foreach ($query->descriptions([$itemId]) as $row) {
            $titles[(string) $row['fk_c_locale_code']] = (string) $row['s_title'];
        }

        return $titles[osc_current_user_locale()] ?? (string) (reset($titles) ?: '');
    }

    /**
     * Up to WORDS distinct title words of three letters or more.
     *
     * @return string[]
     */
    private static function words(string $title): array
    {
        $words = [];
        foreach (preg_split('/[^\pL\pN]+/u', mb_strtolower($title, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word, 'UTF-8') >= 3) {
                $words[$word] = $word;
            }
        }

        return array_slice(array_values($words), 0, self::WORDS);
    }
}
