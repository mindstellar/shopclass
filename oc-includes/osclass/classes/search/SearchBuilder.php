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
        //FILTERING CATEGORY
        foreach ($criteria->categories() as $category) {
            try {
                $search->addCategory($category);
            } catch (\Exception $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        }

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

        //FILTERING COUNTRY
        foreach ($criteria->countries() as $country) {
            $search->addCountry($country);
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
     * Fire `search_conditions`, after the caller has set sort and paging, so a plugin that
     * changes either inside the hook keeps its change.
     *
     * @param SearchCriteria $criteria
     * @param \Search        $search
     * @param string         $context 'request' for the search page
     *
     * @return void
     */
    public static function fireConditions(SearchCriteria $criteria, \Search $search, string $context = 'request'): void
    {
        osc_run_hook('search_conditions', \Params::getParamsAsArray());
    }

    /**
     * The per-custom-field conditions block CWebSearch::doModel() built inline, moved
     * verbatim (metaLiteral() moves with it).
     *
     * @param SearchCriteria $criteria
     * @param \Search        $search
     *
     * @return void
     */
    private static function applyMeta(SearchCriteria $criteria, \Search $search): void
    {
        // CUSTOM FIELDS
        $custom_fields = $criteria->meta();

        $fields = \Field::newInstance()->findIDSearchableByCategories($criteria->categories());

        $table = DB_TABLE_PREFIX . 't_item_meta';
        if (is_array($custom_fields)) {
            foreach ($custom_fields as $key => $aux) {
                if (in_array($key, $fields)) {
                    $field = \Field::newInstance()->findByPrimaryKey($key);
                    switch ($field['e_type']) {
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
                                $start = mktime('0', '0', '0', $m, $d, $y);
                                $end   = mktime('23', '59', '59', $m, $d, $y);
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
                                $start        = $from;
                                $end          = $to;
                                $sql          = "SELECT fk_i_item_id FROM $table WHERE ";
                                $sql          .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                                $sql          .= $start . ' >= ' . $table
                                    . ".s_value AND s_multi = 'from'";
                                $sql1         = "SELECT fk_i_item_id FROM $table WHERE ";
                                $sql1         .= $table . '.fk_i_field_id = ' . (int)$key . ' AND ';
                                $sql1         .= $end . ' <= ' . $table
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
        }
    }

    /**
     * A custom-field search value as a quoted SQL string. Search conditions are kept as
     * SQL text (saved alerts store them), so they cannot be bound; the value is always
     * quoted, so a number is compared as the text it is stored as.
     *
     * @param string|int|float $value
     *
     * @return string
     */
    private static function metaLiteral($value): string
    {
        return "'" . \mindstellar\database\Connection::instance()->escape((string) $value) . "'";
    }
}
