<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\listing;

use mindstellar\database\Db;

/**
 * The listing totals kept per user, category, country, region and city, moved as a listing
 * goes live, stops being live, or changes owner, category or place.
 *
 * Inside a transaction the updates wait for the commit and are dropped on a rollback. They
 * touch rows shared by every listing, so holding them in the transaction would make
 * concurrent posts fail on MariaDB 11.6+ and lengthen the lock.
 */
final class ListingStats
{
    private function __construct()
    {
    }

    /**
     * Count one listing in. Fires `item_increase_stat` unless $announce is false.
     *
     * @param array<string,mixed> $item     fk_i_user_id, fk_i_category_id and the location ids
     * @param bool                $announce whether to fire the hook
     */
    public static function increase(array $item, bool $announce = true): void
    {
        Db::afterCommit(static function () use ($item, $announce): void {
            self::applyIncrease($item, $announce);
        });
    }

    private static function applyIncrease(array $item, bool $announce): void
    {
        if ($item['fk_i_user_id'] !== null) {
            \User::getInstance()->increaseNumItems($item['fk_i_user_id']);
        }
        if ($item['fk_i_category_id'] !== null && $item['fk_i_category_id'] !== '') {
            \CategoryStats::getInstance()->increaseNumItems($item['fk_i_category_id']);
        }
        if ($item['fk_c_country_code'] !== null && $item['fk_c_country_code'] !== '') {
            \CountryStats::getInstance()->increaseNumItems($item['fk_c_country_code']);
        }
        if ($item['fk_i_region_id'] !== null && $item['fk_i_region_id'] !== '') {
            \RegionStats::getInstance()->increaseNumItems($item['fk_i_region_id']);
        }
        if ($item['fk_i_city_id'] !== null && $item['fk_i_city_id'] !== '') {
            \CityStats::getInstance()->increaseNumItems($item['fk_i_city_id']);
        }
        if ($announce) {
            osc_run_hook('item_increase_stat', $item);
        }
    }

    /**
     * Count one listing out. Fires `item_decrease_stat` unless $announce is false.
     *
     * @param array<string,mixed>|mixed $item     a t_item row joined with its location ids
     * @param bool                      $announce whether to fire the hook
     */
    public static function decrease($item, bool $announce = true): void
    {
        Db::afterCommit(static function () use ($item, $announce): void {
            self::applyDecrease($item, $announce);
        });
    }

    private static function applyDecrease($item, bool $announce): void
    {
        if ($item['fk_i_user_id'] != null) {
            \User::getInstance()->decreaseNumItems($item['fk_i_user_id']);
        }
        \CategoryStats::getInstance()->decreaseNumItems($item['fk_i_category_id']);
        \CountryStats::getInstance()->decreaseNumItems($item['fk_c_country_code']);
        \RegionStats::getInstance()->decreaseNumItems($item['fk_i_region_id']);
        \CityStats::getInstance()->decreaseNumItems($item['fk_i_city_id']);
        if ($announce) {
            osc_run_hook('item_decrease_stat', $item);
        }
    }

    /**
     * Move the totals after an edit: in or out when the expiry crossed now, across when the
     * owner, category or place changed. Only a live listing is counted.
     *
     * @param bool|int                  $result       what the item update returned
     * @param array<string,mixed>|mixed $oldItem      the row before the edit
     * @param bool                      $oldIsExpired
     * @param array<string,mixed>|mixed $oldLocation  its location row before the edit
     * @param array<string,mixed>       $aItem        the saved data
     * @param bool                      $newIsExpired
     * @param array<string,mixed>       $location     the location written
     */
    public static function afterEdit($result, $oldItem, $oldIsExpired, $oldLocation, $aItem, $newIsExpired, $location): void
    {
        Db::afterCommit(static function () use ($result, $oldItem, $oldIsExpired, $oldLocation, $aItem, $newIsExpired, $location): void {
            self::applyEdit($result, $oldItem, $oldIsExpired, $oldLocation, $aItem, $newIsExpired, $location);
        });
    }

    private static function applyEdit($result, $oldItem, $oldIsExpired, $oldLocation, $aItem, $newIsExpired, $location): void
    {
        if ($result == 1 && $oldItem['b_enabled'] == 1 && $oldItem['b_active'] == 1 && $oldItem['b_spam'] == 0) {
            // if old item is expired and new item is not expired.
            if ($oldIsExpired && !$newIsExpired) {
                // increment new item stats (user, category, location_stats)
                if (is_numeric($aItem['userId'])) {
                    \User::getInstance()->increaseNumItems($aItem['userId']);
                }
                \CategoryStats::getInstance()->increaseNumItems($aItem['catId']);
                \CountryStats::getInstance()->increaseNumItems($location['fk_c_country_code']);
                \RegionStats::getInstance()->increaseNumItems($location['fk_i_region_id']);
                \CityStats::getInstance()->increaseNumItems($location['fk_i_city_id']);
            }
            // if old is not expired and new is expired
            if (!$oldIsExpired && $newIsExpired) {
                // decrement new item stats (user, category, location_stats)
                if (is_numeric($oldItem['fk_i_user_id'])) {
                    \User::getInstance()->decreaseNumItems($oldItem['fk_i_user_id']);
                }
                \CategoryStats::getInstance()->decreaseNumItems($aItem['catId']);
                \CountryStats::getInstance()->decreaseNumItems($location['fk_c_country_code']);
                \RegionStats::getInstance()->decreaseNumItems($location['fk_i_region_id']);
                \CityStats::getInstance()->decreaseNumItems($location['fk_i_city_id']);
            }
            // if old item is not expired and new item is not expired
            if (!$oldIsExpired && !$newIsExpired) {
                // Update user stats - if old user diferent to actual user, update user stats
                if ($oldItem['fk_i_user_id'] != $aItem['userId']) {
                    if (is_numeric($oldItem['fk_i_user_id'])) {
                        \User::getInstance()->decreaseNumItems($oldItem['fk_i_user_id']);
                    }
                    if (is_numeric($aItem['userId'])) {
                        \User::getInstance()->increaseNumItems($aItem['userId']);
                    }
                }
                // Update category numbers
                if ($oldItem['fk_i_category_id'] != $aItem['catId']) {
                    \CategoryStats::getInstance()->increaseNumItems($aItem['catId']);
                    \CategoryStats::getInstance()->decreaseNumItems($oldItem['fk_i_category_id']);
                }
                // Update location stats
                if ($oldLocation['fk_c_country_code'] != $location['fk_c_country_code']) {
                    \CountryStats::getInstance()->decreaseNumItems($oldLocation['fk_c_country_code']);
                    \CountryStats::getInstance()->increaseNumItems($location['fk_c_country_code']);
                }
                if ($oldLocation['fk_i_region_id'] != $location['fk_i_region_id']) {
                    \RegionStats::getInstance()->decreaseNumItems($oldLocation['fk_i_region_id']);
                    \RegionStats::getInstance()->increaseNumItems($location['fk_i_region_id']);
                }
                if ($oldLocation['fk_i_city_id'] != $location['fk_i_city_id']) {
                    \CityStats::getInstance()->decreaseNumItems($oldLocation['fk_i_city_id']);
                    \CityStats::getInstance()->increaseNumItems($location['fk_i_city_id']);
                }
            }
            // if old and new items are expired [nothing to do]
            // if($oldIsExpired && $newIsExpired) { }
        }
    }
}
