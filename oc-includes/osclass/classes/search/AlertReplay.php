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
 * Turns a stored alert back into a Search.
 *
 * A v2 row is rebuilt from its params through the same SearchBuilder the search page
 * uses, so `search_conditions` fires for it with context 'alert'. A v1 row still goes
 * through Search::setJsonAlert().
 */
class AlertReplay
{
    /**
     * A Search for a t_alerts row, newest first; the caller adds its own limits and runs it.
     *
     * @param array<string,mixed> $alertRow a t_alerts row (reads s_search)
     * @param array<string,mixed> $options  'limit' => page size for a v2 row (default 10)
     *
     * @return \Search|null null when the row holds no search that can be replayed
     */
    public static function search(array $alertRow, array $options = array()): ?\Search
    {
        $json = (string)($alertRow['s_search'] ?? '');
        // v1 rows have no size limit (an expanded category list can be long); a v2 one does.
        if (strlen($json) > AlertEnvelope::MAX_BYTES && strncmp($json, '{"v":', 5) === 0) {
            self::logSkip($alertRow);

            return null;
        }
        $data = json_decode($json, true, 8);
        if (!is_array($data)) {
            return null;
        }

        $search = new \Search();
        if (!AlertEnvelope::isEnvelope($data)) {
            $search->setJsonAlert($data);

            return $search;
        }

        $params = AlertEnvelope::validate($json);
        if ($params === null) {
            self::logSkip($alertRow);

            return null;
        }
        self::apply($search, $params, (int)($options['limit'] ?? 10));

        return $search;
    }

    /**
     * Build validated params onto $search, which is the shared Search instance while the
     * builder and `search_conditions` run, and the request bag is the params.
     *
     * @param \Search             $search
     * @param array<string,mixed> $params validated v2 params
     * @param int                 $limit  page size
     *
     * @return void
     */
    public static function apply(\Search $search, array $params, int $limit = 10): void
    {
        $request  = self::requestBag($params);
        $previous = \Search::resetInstance($search);
        try {
            \Params::withRequest($request, static function () use ($search, $request, $limit) {
                $criteria = SearchCriteria::fromRequest($request);
                SearchBuilder::apply($criteria, $search);
                $search->order('dt_pub_date', 'DESC');
                $search->page(0, max(1, $limit));
                SearchBuilder::fireConditions($criteria, $search, 'alert');
            });
        } finally {
            \Search::resetInstance($previous);
        }
    }

    /**
     * @param array<string,mixed> $alertRow
     *
     * @return void
     */
    private static function logSkip(array $alertRow): void
    {
        error_log(sprintf(
            'AlertReplay: skipped alert #%s, its stored search is not a valid v2 envelope',
            $alertRow['pk_i_id'] ?? '?'
        ));
    }

    /**
     * The stored params in the shape a search request has them, so code reading Params
     * sees what it sees on the search page: numbers as strings, and a one-value list as
     * that value unless it holds a comma (the page would split it).
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private static function requestBag(array $params): array
    {
        foreach (AlertEnvelope::CORE_KEYS as $key) {
            if (!isset($params[$key]) || $key === 'meta') {
                continue;
            }
            $value = $params[$key];
            if (is_array($value)) {
                $value = array_map('strval', $value);
                if (count($value) === 1 && strpos($value[0], ',') === false) {
                    $value = $value[0];
                }
            } else {
                $value = (string)$value;
            }
            $params[$key] = $value;
        }

        return $params;
    }
}
