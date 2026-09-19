<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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

    /** Renames a catalog preview lists by name. */
    public const PREVIEW_RENAMES = 10;

    /** Catalog row states, in the order the Data tab lists them. */
    public const CATALOG_STATES = array('update', 'current', 'available');

    /** Data tab filters: everything, installed (current or not), updates, not installed. */
    public const CATALOG_FILTERS = array('all', 'installed', 'update', 'available');

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

    /**
     * Catalog rows for the Data tab: countries with something to import, each with a state
     * (update, current or available), updates first and by name within a state.
     *
     * @param array<int,array<string,mixed>> $status rows of LocationCatalog::status()
     *
     * @return array<int,array{code:string,name:string,cities:int,regions:int,state:string}>
     */
    public static function catalogRows(array $status): array
    {
        $rows = array();
        foreach ($status as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($code === '' || ((string) ($row['file'] ?? '') === '' && (string) ($row['ndjson'] ?? '') === '')) {
                continue;
            }
            $installed = !empty($row['installed']);
            $rows[]    = array(
                'code'    => $code,
                'name'    => (string) ($row['name'] ?? $code),
                'cities'  => (int) ($row['rows'] ?? 0),
                'regions' => (int) ($row['regions'] ?? 0),
                'state'   => $installed ? (!empty($row['current']) ? 'current' : 'update') : 'available',
            );
        }

        $order = array_flip(self::CATALOG_STATES);
        usort($rows, static function (array $a, array $b) use ($order): int {
            return $order[$a['state']] <=> $order[$b['state']] ?: strnatcasecmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * The Data tab filter as the page reads it: the text trimmed and capped, and a known
     * filter. Without one it shows the installed countries, or all when none is installed.
     *
     * @param string $find      text to match against the name or code
     * @param string $show      one of CATALOG_FILTERS
     * @param int    $installed how many catalog countries are installed
     *
     * @return array{find:string,show:string}
     */
    public static function catalogFilter(string $find, string $show, int $installed): array
    {
        if (!in_array($show, self::CATALOG_FILTERS, true)) {
            $show = $installed > 0 ? 'installed' : 'all';
        }

        return array('find' => self::search($find, '')['q'], 'show' => $show);
    }

    /**
     * Whether a catalog row passes the filter: the name contains the text or the code equals it.
     *
     * @param array{code:string,name:string,state:string} $row
     * @param string                                      $find
     * @param string                                      $show
     *
     * @return bool
     */
    public static function catalogMatches(array $row, string $find, string $show): bool
    {
        if ($show === 'installed' && $row['state'] === 'available') {
            return false;
        }
        if ($show !== 'all' && $show !== 'installed' && $row['state'] !== $show) {
            return false;
        }
        if ($find === '') {
            return true;
        }

        return strcasecmp($row['code'], $find) === 0 || mb_stripos($row['name'], $find) !== false;
    }

    /**
     * How many catalog rows each filter shows.
     *
     * @param array<int,array{state:string}> $rows
     *
     * @return array{all:int,installed:int,update:int,available:int}
     */
    public static function catalogCounts(array $rows): array
    {
        $counts = array('all' => count($rows), 'installed' => 0, 'update' => 0, 'available' => 0);
        foreach ($rows as $row) {
            if ($row['state'] === 'available') {
                $counts['available']++;
                continue;
            }
            $counts['installed']++;
            if ($row['state'] === 'update') {
                $counts['update']++;
            }
        }

        return $counts;
    }

    /**
     * The catalog country a manually typed code stands for, so the add form can offer to
     * import it instead. Null for a malformed code or one the catalog cannot import.
     *
     * @param string                         $code   as typed
     * @param array<int,array<string,mixed>> $status rows of LocationCatalog::status()
     *
     * @return array{code:string,name:string,regions:int,cities:int,installed:bool,current:bool}|null
     */
    public static function importOffer(string $code, array $status): ?array
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }
        foreach (self::catalogRows($status) as $row) {
            if ($row['code'] === $code) {
                return array(
                    'code'      => $row['code'],
                    'name'      => $row['name'],
                    'regions'   => $row['regions'],
                    'cities'    => $row['cities'],
                    'installed' => $row['state'] !== 'available',
                    'current'   => $row['state'] === 'current',
                );
            }
        }

        return null;
    }

    /**
     * The catalog row an import names: by country code, or by the exact file name an older
     * caller posts. Null for anything else, so a posted path never reaches the importer.
     *
     * @param string                         $location country code or published file name
     * @param array<int,array<string,mixed>> $status   rows of LocationCatalog::status()
     *
     * @return array<string,mixed>|null
     */
    public static function catalogEntry(string $location, array $status): ?array
    {
        $location = trim($location);
        $offer    = self::importOffer($location, $status);
        foreach ($status as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($offer !== null ? $code === $offer['code'] : ($location !== '' && (string) ($row['file'] ?? '') === $location)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Why the add form's "import instead" cannot run for a code, or null when it can. An
     * installed country is refused: importing it again would update it without a preview.
     *
     * @param string                         $code   as typed
     * @param array<int,array<string,mixed>> $status rows of LocationCatalog::status()
     *
     * @return string|null malformed|unknown|installed
     */
    public static function importInsteadRefusal(string $code, array $status): ?string
    {
        if (preg_match('/^[A-Z]{2}$/', strtoupper(trim($code))) !== 1) {
            return 'malformed';
        }
        $offer = self::importOffer($code, $status);
        if ($offer === null) {
            return 'unknown';
        }

        return $offer['installed'] ? 'installed' : null;
    }

    /**
     * Seconds a request may still spend counting: the request runs at most $cap seconds in
     * all, and stops five seconds short of max_execution_time (0 means no limit).
     *
     * @param int   $maxExecution max_execution_time in seconds
     * @param float $elapsed      seconds the request has run already
     * @param float $cap
     *
     * @return float
     */
    public static function recountBudget(int $maxExecution, float $elapsed, float $cap = 20.0): float
    {
        $limit = $maxExecution > 0 ? min($cap, $maxExecution - 5) : $cap;

        return max(0.0, $limit - $elapsed);
    }

    /**
     * An importer dry-run report reduced to what the preview shows. Hidden counts rows the
     * import would deactivate; kept counts rows it leaves live because they hold listings.
     *
     * @param array<string,mixed>                $report LocationImporter report
     * @param array<string,array<int,string>>    $names  current names of renamed rows, as
     *                                                   ['REGION' => [id => name], 'CITY' => …]
     *
     * @return array<string,mixed> `error` alone when the import could not run
     */
    public static function previewReport(array $report, array $names = array()): array
    {
        if (isset($report['error'])) {
            return array('error' => (string) $report['error']);
        }

        $levels  = array();
        $renamed = 0;
        $changes = !empty($report['country_inserted']) || !empty($report['country_renamed']);
        foreach (array('regions', 'cities') as $level) {
            $counts = is_array($report[$level] ?? null) ? $report[$level] : array();
            $read   = static fn (string $key): int => (int) ($counts[$key] ?? 0);

            $levels[$level] = array(
                'inserted'  => $read('inserted'),
                'updated'   => $read('updated'),
                'renamed'   => $read('renamed'),
                'hidden'    => $read('deactivated'),
                'unchanged' => $read('unchanged'),
                'kept'      => $read('kept_stale'),
                'skipped'   => $read('skipped_empty') + $read('skipped_long'),
            );
            $renamed += $levels[$level]['renamed'];
            $changes  = $changes || $levels[$level]['inserted'] + $levels[$level]['updated'] + $levels[$level]['hidden'] > 0;
        }

        $renames = array();
        foreach (array_slice(is_array($report['renames'] ?? null) ? $report['renames'] : array(), 0, self::PREVIEW_RENAMES) as $rename) {
            $type      = (string) ($rename['type'] ?? '');
            $id        = (int) ($rename['id'] ?? 0);
            $renames[] = array(
                'level' => $type === 'REGION' ? 'region' : 'city',
                'id'    => $id,
                'name'  => $names[$type][$id] ?? null,
                'from'  => (string) ($rename['from'] ?? ''),
                'to'    => (string) ($rename['to'] ?? ''),
            );
        }

        return array(
            'country'         => (string) ($report['country'] ?? ''),
            'countryInserted' => !empty($report['country_inserted']),
            'countryRenamed'  => !empty($report['country_renamed']),
            'levels'          => $levels,
            'renames'         => $renames,
            'renamesMore'     => max(0, $renamed - count($renames)),
            'kept'            => $levels['regions']['kept'] + $levels['cities']['kept'],
            'changes'         => $changes,
            'fellBack'        => isset($report['fell_back']),
        );
    }

    /**
     * The date of a catalog release name such as 2026-08-22T0451Z, or '' when it has none.
     *
     * @param string $release
     *
     * @return string Y-m-d
     */
    public static function releaseDate(string $release): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($release), $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return '';
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /**
     * Progress of a counts recalculation from the queue left and the size it started at.
     *
     * @param int $pending locations still queued
     * @param int $total   locations queued when the run started
     *
     * @return array{pending:int,total:int,done:int,percent:int}
     */
    public static function recalcProgress(int $pending, int $total): array
    {
        $pending = max(0, $pending);
        $total   = max($total, $pending);
        $done    = $total - $pending;

        return array(
            'pending' => $pending,
            'total'   => $total,
            'done'    => $done,
            'percent' => $total === 0 ? 100 : (int) floor($done * 100 / $total),
        );
    }
}
