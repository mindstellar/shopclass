<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\location;

/**
 * The decisions the location admin screen makes before it renders: how a search is read,
 * when the A–Z strip shows, where a search hit links to and what a delete asks the admin
 * to type. Pure functions, so they can be tested without a request.
 */
final class LocationAdminView
{
    /** A level holding more rows than this gets the A–Z strip. */
    public const ALPHABET_MIN = 200;

    /** Longest search accepted; names are at most 80 characters. */
    public const MAX_QUERY = 80;

    /** Hits per level in the everywhere search. */
    public const HITS_PER_LEVEL = 10;

    /**
     * The search as the list reads it: trimmed and capped, with the everywhere scope only
     * when there is something to search for.
     *
     * @param string $q
     * @param string $scope 'all' for everywhere; anything else means this level
     *
     * @return array{q:string,scope:string}
     */
    public static function search(string $q, string $scope): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if (mb_strlen($q) > self::MAX_QUERY) {
            $q = rtrim(mb_substr($q, 0, self::MAX_QUERY));
        }

        return array('q' => $q, 'scope' => $scope === 'all' && $q !== '' ? 'all' : 'level');
    }

    /**
     * @param int    $levelTotal rows at this level before any search
     * @param string $scope
     *
     * @return bool
     */
    public static function showAlphabet(int $levelTotal, string $scope): bool
    {
        return $scope !== 'all' && $levelTotal > self::ALPHABET_MIN;
    }

    /**
     * Hits fetched with one row more than shown per level: trimmed to HITS_PER_LEVEL, with
     * `more` saying which levels had that extra row.
     *
     * @param array<string,array<int,array<string,mixed>>> $hits countries, regions, cities
     *
     * @return array{hits:array<string,array<int,array<string,mixed>>>,more:array<string,bool>}
     */
    public static function splitHits(array $hits): array
    {
        $out = array('hits' => array(), 'more' => array());
        foreach (array('countries', 'regions', 'cities') as $group) {
            $rows                 = array_values($hits[$group] ?? array());
            $out['more'][$group]  = count($rows) > self::HITS_PER_LEVEL;
            $out['hits'][$group]  = array_slice($rows, 0, self::HITS_PER_LEVEL);
        }

        return $out;
    }

    /**
     * Where an everywhere-search hit leads: `open` is the list to show (a country's regions,
     * a region's cities, or the city's region filtered to its name) and `edit` the list the
     * edit form opens on. Both are null for a row whose parent is gone.
     *
     * @param string              $level country|region|city
     * @param array<string,mixed> $hit   a row of LocationAdminQuery::searchAll()
     *
     * @return array{open:array<string,string|int>|null,edit:array<string,string|int>|null,path:array<int,string>}
     */
    public static function hitLinks(string $level, array $hit): array
    {
        switch ($level) {
            case 'country':
                return array(
                    'open' => array('country' => (string) $hit['code']),
                    'edit' => array('form' => 'edit', 'id' => (string) $hit['code']),
                    'path' => array(),
                );
            case 'region':
                $known = ($hit['country_name'] ?? null) !== null;

                return array(
                    'open' => $known ? array('country' => (string) $hit['country'], 'region' => (int) $hit['id']) : null,
                    'edit' => $known ? array('country' => (string) $hit['country'], 'form' => 'edit', 'id' => (int) $hit['id']) : null,
                    'path' => $known ? array((string) $hit['country_name']) : array(),
                );
            default:
                $known = ($hit['region_name'] ?? null) !== null;
                $list  = array('country' => (string) ($hit['country'] ?? ''), 'region' => (int) $hit['region']);

                return array(
                    'open' => $known ? $list + array('q' => (string) $hit['name']) : null,
                    'edit' => $known ? $list + array('form' => 'edit', 'id' => (int) $hit['id']) : null,
                    'path' => array_values(array_filter(
                        array($hit['country_name'] ?? null, $hit['region_name'] ?? null),
                        static fn ($part): bool => $part !== null
                    )),
                );
        }
    }

    /**
     * What the admin types before a delete that removes listings: the location's name for
     * one row, the number of listings for a selection. Null when no listing would go.
     *
     * @param array{listings:int} $impact
     * @param string|null         $name   the one row's name; null for a selection
     *
     * @return string|null
     */
    public static function confirmPhrase(array $impact, ?string $name): ?string
    {
        if ((int) $impact['listings'] < 1) {
            return null;
        }

        return $name !== null && trim($name) !== '' ? trim($name) : (string) (int) $impact['listings'];
    }

    /**
     * An HTML `pattern` that matches exactly $text. Browsers compile it with the `v` flag,
     * where only syntax characters may be escaped outside a class.
     *
     * @param string $text
     *
     * @return string
     */
    public static function confirmPattern(string $text): string
    {
        // A count may be typed as shown (3,481 or 3.481 or 3 481) or as bare digits.
        if (preg_match('/^\d{4,}$/', $text) === 1) {
            $groups = str_split(strrev($text), 3);

            return implode("[,.' \u{00a0}]?", array_map('strrev', array_reverse($groups)));
        }

        return (string) preg_replace('/[\^$\\\\.*+?()[\]{}|\/]/', '\\\\$0', $text);
    }
}
