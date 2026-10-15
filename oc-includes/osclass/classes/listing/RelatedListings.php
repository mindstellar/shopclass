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

    /**
     * Live listing rows like $itemId, best first, without the listing itself.
     *
     * @param array<string,mixed>      $filters names from FILTERS; unknown names are ignored
     * @param array<string,mixed>|null $source  the page's row for $itemId, such as Item::extendData() gives
     *                                          (pk_i_id, fk_i_category_id, fk_c_country_code, fk_i_region_id,
     *                                          s_title); anything missing is read from the database
     *
     * @return array<int,array<string,mixed>> bare t_item rows
     * @throws \mindstellar\database\DbException
     */
    public static function rows(int $itemId, int $limit = 6, array $filters = [], ?array $source = null): array
    {
        $limit   = max(1, min(self::MAX_LIMIT, $limit));
        $filters = self::filters($filters);

        $picked = osc_apply_filter('item_related_ids', null, $itemId, $limit, $filters);
        $ids    = is_array($picked) ? ListingQuery::cleanIds($picked, $itemId) : self::defaultIds($itemId, $limit, $filters, $source);

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
     * @param array<string,bool>       $filters
     * @param array<string,mixed>|null $source
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    private static function defaultIds(int $itemId, int $limit, array $filters, ?array $source): array
    {
        $query = new ListingQuery();
        if (!isset($source['fk_i_category_id']) || (int) ($source['pk_i_id'] ?? 0) !== $itemId) {
            $source = $query->find($itemId);
            if ($source === null) {
                return [];
            }
        }
        $place = array_key_exists('fk_c_country_code', $source) ? $source : ($query->statsAndLocations([$itemId])[0] ?? []);
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

        $title = (string) ($source['s_title'] ?? '');

        return $query->related($itemId, $match, ListingQuery::titleWords($title !== '' ? $title : self::title($query, $itemId)), $limit);
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
}
