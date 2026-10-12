<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\search;

use mindstellar\fields\FieldQuery;
use mindstellar\location\LocationQuery;

/**
 * Applies a SearchCriteria to a core Search model.
 *
 * The mutator calls CWebSearch::doModel() used to make inline, moved verbatim and in
 * the same order — category/city-area/city/region/country, pattern, user, locale,
 * picture/premium, price range, then the custom-field ("meta") conditions block. The
 * caller then sets sort and paging, which a live request and an alert replay choose
 * differently, and calls fireConditions() last, as the controller always did.
 */
class SearchBuilder
{
    /**
     * @param SearchCriteria $criteria
     * @param \Search        $search
     *
     * @return void
     */
    public static function apply(SearchCriteria $criteria, \Search $search): void
    {
        // A radius measures from the place and replaces its filter; a distance sort keeps it.
        $near    = self::near($criteria);
        $byPlace = $near === null || $criteria->radius() === 0;

        //FILTERING CATEGORY
        foreach ($criteria->categories() as $category) {
            try {
                $search->addCategory($category);
            } catch (\Exception $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        }

        if ($byPlace) {
            //FILTERING CITY_AREA
            foreach ($criteria->cityAreas() as $city_area) {
                $search->addCityArea($city_area);
            }

            //FILTERING CITY
            foreach ($criteria->cities() as $city) {
                $search->addCity($city);
            }

            //FILTERING REGION
            foreach ($criteria->regions() as $region) {
                $search->addRegion($region);
            }
        }

        //FILTERING COUNTRY
        foreach ($criteria->countries() as $country) {
            $search->addCountry($country);
        }

        // FILTERING BY DISTANCE
        if ($near !== null) {
            $search->near(...$near);
        }

        // FILTERING PATTERN
        if ($criteria->hasPattern()) {
            $search->addPattern($criteria->pattern());
        }

        // FILTERING USER
        if ($criteria->users() != '') {
            $search->fromUser($criteria->users());
        }

        // FILTERING LOCALE
        $search->addLocale($criteria->locale());

        // FILTERING IF WE ONLY WANT ITEMS WITH PICS
        if ($criteria->withPicture()) {
            $search->withPicture(true);
        }

        // FILTERING IF WE ONLY WANT PREMIUM ITEMS
        if ($criteria->onlyPremium()) {
            $search->onlyPremium(true);
        }

        //FILTERING BY RANGE PRICE
        $search->priceRange($criteria->priceMin(), $criteria->priceMax());

        self::applyMeta($criteria, $search);
    }

    /**
     * Where a distance search measures from and how far, when the site offers it: the
     * visitor's point, else the centre of the one city or region named. A "nearest first"
     * sort with no radius still keeps to the largest one, so it never reads every listing.
     *
     * @return array{0:float,1:float,2:float}|null latitude, longitude, km
     */
    private static function near(SearchCriteria $criteria): ?array
    {
        if (!osc_geo_search_enabled() || ($criteria->radius() === 0 && $criteria->order() !== 'distance')) {
            return null;
        }
        $point = $criteria->point();
        if ($point === null) {
            $cities    = array_values($criteria->cities());
            $regions   = array_values($criteria->regions());
            $countries = array_values($criteria->countries());
            $country   = count($countries) === 1 ? (string) $countries[0] : null;
            if (count($cities) === 1) {
                $point = (new LocationQuery())->centre(LocationQuery::CITY, (string) $cities[0], $country);
            } elseif ($cities === array() && count($regions) === 1) {
                $point = (new LocationQuery())->centre(LocationQuery::REGION, (string) $regions[0], $country);
            }
        }
        if ($point === null) {
            return null;
        }
        $band = $criteria->radius() > 0 ? $criteria->radius() : max(Distance::BANDS);

        return array($point[0], $point[1], Distance::toKm((float) $band, osc_distance_unit()));
    }

    /**
     * Fire `search_conditions`, after the caller has set sort and paging, so a plugin that
     * changes either inside the hook keeps its change. Listeners get the request params,
     * the Search being built and the context.
     *
     * @param \Search                  $search
     * @param string                   $context 'request' for the search page, 'alert' for a saved alert
     * @param array<string,mixed>|null $params  the search parameters; null for the request's
     *
     * @return void
     */
    public static function fireConditions(\Search $search, string $context = 'request', ?array $params = null): void
    {
        self::asRequest($params, static fn () => osc_run_hook('search_conditions', \Params::getParamsAsArray(), $search, $context));
    }

    /**
     * Run a listener call with $params as the request, so a listener reading Params itself
     * sees the same values it is given; with null, the request as it is.
     *
     * @param array<string,mixed>|null $params
     *
     * @return mixed what $fn returns
     */
    public static function asRequest(?array $params, callable $fn)
    {
        return $params === null ? $fn() : \Params::withRequest($params, $fn);
    }

    /**
     * The ids of the fields that can be searched in these categories (ids or slugs),
     * inheritance included; none when no category is given.
     *
     * @param array<mixed> $categories
     *
     * @return int[]
     */
    public static function searchableFieldIds(array $categories): array
    {
        if ($categories === []) {
            return [];
        }

        return array_map('intval', (array) \Field::getInstance()->findIDSearchableByCategories($categories));
    }

    /**
     * One condition per custom-field filter on a field searchable in the asked categories.
     *
     * @param SearchCriteria $criteria
     * @param \Search        $search
     *
     * @return void
     */
    private static function applyMeta(SearchCriteria $criteria, \Search $search): void
    {
        $custom_fields = $criteria->meta();
        if (!is_array($custom_fields) || $custom_fields === []) {
            return;
        }
        $fields = self::searchableFieldIds((array) $criteria->categories());
        $types  = FieldQuery::types(array_map('intval', array_filter(
            array_keys($custom_fields),
            static fn ($key): bool => (string) (int) $key === (string) $key && in_array((int) $key, $fields, true)
        )));

        $table = DB_TABLE_PREFIX . 't_item_meta';
        foreach ($custom_fields as $key => $aux) {
            switch ($types[(int)$key] ?? null) {
                case 'TEXTAREA':
                case 'TEXT':
                case 'URL':
                    if (is_scalar($aux) && $aux != '') {
                        $sql         = "SELECT fk_i_item_id FROM $table WHERE ";
                        $str_escaped = self::metaLiteral('%' . $aux . '%');
                        $sql         .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                        $sql         .= $table . '.s_value LIKE ' . $str_escaped;
                        $search->addConditions(DB_TABLE_PREFIX
                            . 't_item.pk_i_id IN (' . $sql . ')');
                    }
                    break;
                case 'DROPDOWN':
                case 'RADIO':
                    if (is_scalar($aux) && $aux != '') {
                        $sql         = "SELECT fk_i_item_id FROM $table WHERE ";
                        $str_escaped = self::metaLiteral($aux);
                        $sql         .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                        $sql         .= $table . '.s_value = ' . $str_escaped;
                        $search->addConditions(DB_TABLE_PREFIX
                            . 't_item.pk_i_id IN (' . $sql . ')');
                    }
                    break;
                case 'CHECKBOX':
                    if ($aux != '') {
                        $sql = "SELECT fk_i_item_id FROM $table WHERE ";
                        $sql .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                        $sql .= $table . '.s_value = 1';
                        $search->addConditions(DB_TABLE_PREFIX
                            . 't_item.pk_i_id IN (' . $sql . ')');
                    }
                    break;
                case 'DATE':
                    // A whole number only: '1e20' passes is_numeric() and date() then throws.
                    if (is_scalar($aux) && filter_var($aux, FILTER_VALIDATE_INT) !== false) {
                        $y     = (int)date('Y', (int)$aux);
                        $m     = (int)date('n', (int)$aux);
                        $d     = (int)date('j', (int)$aux);
                        $start = mktime(0, 0, 0, $m, $d, $y);
                        $end   = mktime(23, 59, 59, $m, $d, $y);
                        $sql   = "SELECT fk_i_item_id FROM $table WHERE ";
                        $sql   .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                        $sql   .= $table . '.s_value >= ' . $start . ' AND ';
                        $sql   .= $table . '.s_value <= ' . $end;
                        $search->addConditions(DB_TABLE_PREFIX
                            . 't_item.pk_i_id IN (' . $sql . ')');
                    }
                    break;
                case 'DATEINTERVAL':
                    if (is_array($aux) && (!empty($aux['from']) && !empty($aux['to']))
                        && is_numeric($aux['from']) && is_numeric($aux['to'])
                    ) {
                        // s_value stores unix timestamps for DATEINTERVAL fields
                        $from         = (int)$aux['from'];
                        $to           = (int)$aux['to'];
                        $sql          = "SELECT fk_i_item_id FROM $table WHERE ";
                        $sql          .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                        $sql          .= $from . ' >= ' . $table
                            . ".s_value AND s_multi = 'from'";
                        $sql1         = "SELECT fk_i_item_id FROM $table WHERE ";
                        $sql1         .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                        $sql1         .= $to . ' <= ' . $table
                            . ".s_value AND s_multi = 'to'";
                        $sql_interval = 'select a.fk_i_item_id from (' . $sql
                            . ') a where a.fk_i_item_id IN (' . $sql1 . ')';
                        $search->addConditions(DB_TABLE_PREFIX
                            . 't_item.pk_i_id IN (' . $sql_interval . ')');
                    }
                    break;
                case 'NUMBER':
                    if (is_array($aux) && (!empty($aux['from']) && !empty($aux['to']))
                        && is_numeric($aux['from']) && is_numeric($aux['to'])
                        && is_finite((float)$aux['from']) && is_finite((float)$aux['to'])
                    ) {
                        $min   = (float)$aux['from'];
                        $max   = (float)$aux['to'];
                        $sql   = "SELECT fk_i_item_id FROM $table WHERE ";
                        $sql   .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                        $sql   .= $table . '.s_value >= ' . $min . ' AND ';
                        $sql   .= $table . '.s_value <= ' . $max;
                        $search->addConditions(DB_TABLE_PREFIX
                            . 't_item.pk_i_id IN (' . $sql . ')');
                    }
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * A custom-field search value as a quoted SQL string. Search conditions are kept as
     * SQL text (the sql_search_conditions filter and the result cache key read them), so
     * they cannot be bound; the value is always quoted, so a number is compared as the
     * text it is stored as.
     *
     * @param string|int|float $value
     *
     * @return string
     */
    private static function metaLiteral($value): string
    {
        return "'" . \mindstellar\database\Connection::getInstance()->escape((string) $value) . "'";
    }
}
