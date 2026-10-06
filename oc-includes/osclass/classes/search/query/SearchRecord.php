<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\search\query;

use mindstellar\search\AlertEnvelope;
use mindstellar\search\AlertReplay;

/**
 * A search as JSON (Search::toJson()), and a search restored from a stored alert
 * (Search::setJsonAlert()).
 *
 * The JSON is the key of core's result cache, and themes and search backends read it,
 * so its keys and the SQL text of its location and user entries do not change.
 */
final class SearchRecord
{
    /**
     * @param SearchParts $parts
     *
     * @return string
     */
    public static function encode(SearchParts $parts): string
    {
        $data['price_min']   = $parts->priceMin / 1000000;
        $data['price_max']   = $parts->priceMax / 1000000;
        $data['aCategories'] = $parts->categories->ids();
        foreach (LocationFilter::LEVELS as $level) {
            $data[$level] = $parts->locations->recorded($level);
        }
        $data['withPattern'] = $parts->pattern->active();
        $data['sPattern']    = $parts->pattern->recorded();
        if ($parts->withPicture) {
            $data['withPicture'] = $parts->withPicture;
        }
        if ($parts->onlyPremium) {
            $data['onlyPremium'] = $parts->onlyPremium;
        }
        // The result cache keys on this record, so an explicit locale filter must be in it.
        if ($parts->pattern->locales() !== array()) {
            $locales = array_values($parts->pattern->locales());
            sort($locales);
            $data['locale_code'] = $locales;
        }

        $data['tables']                = $parts->plugin->tables();
        $data['tables_join']           = $parts->plugin->joins();
        $data['no_catched_tables']     = $parts->plugin->tables();
        $data['no_catched_conditions'] = $parts->plugin->conditions();
        $data['user_ids']              = $parts->users->recorded();

        $data['order_column']     = $parts->ordering->column();
        $data['order_direction']  = $parts->ordering->direction();
        $data['limit_init']       = $parts->ordering->offset();
        $data['results_per_page'] = $parts->ordering->perPage();

        return (string)json_encode($data);
    }

    /**
     * Restore a stored alert onto $search. A v2 envelope is rebuilt the way the search
     * page builds a request; one that does not validate matches nothing. An old-format
     * record gives only its plain values (price, categories, keyword, photo and premium
     * switches); every SQL-bearing field is cleared, never applied.
     *
     * @param \Search             $search
     * @param SearchParts         $parts
     * @param array<string,mixed> $data
     *
     * @return void
     */
    public static function restore(\Search $search, SearchParts $parts, $data): void
    {
        if (AlertEnvelope::isEnvelope($data)) {
            $params = AlertEnvelope::validateDecoded($data);
            if ($params === null) {
                error_log('Search::setJsonAlert(): not a valid v2 envelope, matching nothing');
                $search->addConditions('1 = 0');

                return;
            }
            AlertReplay::apply($search, $params);

            return;
        }

        // The shared Search is reused, so clear the keyword too: a keyword-less alert
        // would otherwise keep the previous one's and match nothing.
        $parts->pattern->clear();
        $parts->locations->clear();
        $parts->users->clear();
        $parts->plugin->clearAlertParts();

        $price = static function ($value) {
            return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? $value : 0;
        };
        $parts->priceRange($price($data['price_min'] ?? 0), $price($data['price_max'] ?? 0));

        $parts->categories->set(array_values(array_filter(array_map('intval', array_filter(
            (array)($data['aCategories'] ?? array()),
            'is_scalar'
        )))));

        if (isset($data['sPattern']) && is_scalar($data['sPattern'])) {
            $search->addPattern(self::unescapeLegacyPattern((string)$data['sPattern']));
        }
        if (isset($data['withPicture'])) {
            $search->withPicture(true);
        }
        if (isset($data['onlyPremium'])) {
            $search->onlyPremium(true);
        }
    }

    /**
     * Alerts saved before the record kept the raw keyword hold it escaped and quoted;
     * strip that one layer so old and new alerts match the same listings.
     *
     * @param string $pattern
     *
     * @return string
     */
    private static function unescapeLegacyPattern(string $pattern): string
    {
        $len = strlen($pattern);
        if ($len >= 2 && $pattern[0] === "'" && $pattern[$len - 1] === "'") {
            return stripslashes(substr($pattern, 1, -1));
        }

        return $pattern;
    }
}
