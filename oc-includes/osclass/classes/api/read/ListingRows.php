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

namespace mindstellar\api\read;

use mindstellar\cache\CacheGroup;
use mindstellar\listing\ListingQuery;
use mindstellar\utility\Utils;

/**
 * Bare t_item rows made into the rows the listing serializer reads: titles and descriptions
 * by language, then the view counters and location. A page loads only the asked language's
 * texts, plus every language of a listing that has no title in it.
 */
final class ListingRows
{
    /**
     * find() and photos() results of this web request, by id: the bare row, the extended row per
     * locale and the photo rows. An entry is dropped when its listing's cache is cleared or a
     * listing changes.
     *
     * @var array<int,array{gen:int,bare?:array<string,mixed>|null,full:array<string,array<string,mixed>>,photos?:array<int,array<string,mixed>>}>
     */
    private static array $found = [];

    private static bool $hooked = false;

    public function __construct(private ListingQuery $listings)
    {
    }

    /**
     * One listing with every language's text, or null when there is no such listing. A web
     * request reads each listing once, however many API calls on the page ask for it.
     *
     * @param (callable(array<string,mixed>): ?array<string,mixed>)|null $keep checks the bare row first; null drops it before its texts are read
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id, string $locale, ?callable $keep = null): ?array
    {
        $memo  = Utils::isWebRequest();
        $entry = self::entry($id, $memo);
        if (!array_key_exists('bare', $entry)) {
            $entry['bare'] = $this->listings->find($id);
            if ($memo) {
                self::remember($id, $entry);
            }
        }
        $row = $entry['bare'];
        if ($row !== null && $keep !== null) {
            $kept = $keep($row);
            if ($kept !== $row) {
                return $kept === null ? null : $this->extend([$kept], $locale, true)[0];
            }
        }
        if ($row === null) {
            return null;
        }
        if (!isset($entry['full'][$locale])) {
            $entry['full'][$locale] = $this->extend([$row], $locale, true)[0];
            if ($memo) {
                self::remember($id, $entry);
            }
        }

        return $entry['full'][$locale];
    }

    /**
     * The bare row find() read for a listing in this request, or null when it read none.
     *
     * @return array<string,mixed>|null
     */
    public static function held(int $id): ?array
    {
        return isset(self::$found[$id]) ? (self::entry($id, true)['bare'] ?? null) : null;
    }

    /**
     * Each listing's photo rows in upload order, item id => rows; a listing with none is left
     * out. A web request reads each listing's photos once.
     *
     * @param int[] $ids
     *
     * @return array<int,array<int,array<string,mixed>>>
     * @throws \mindstellar\database\DbException
     */
    public function photos(array $ids): array
    {
        $memo = Utils::isWebRequest();
        $out  = [];
        $rest = [];
        foreach ($ids as $id) {
            $held = $memo ? (self::entry($id, true)['photos'] ?? null) : null;
            if ($held === null) {
                $rest[] = $id;
            } elseif ($held !== []) {
                $out[$id] = $held;
            }
        }
        $read = [];
        foreach ($this->listings->photos($rest) as $row) {
            $read[(int) $row['fk_i_item_id']][] = $row;
        }
        foreach ($rest as $id) {
            if ($memo) {
                $entry           = self::entry($id, true);
                $entry['photos'] = $read[$id] ?? [];
                self::remember($id, $entry);
            }
            if (isset($read[$id])) {
                $out[$id] = $read[$id];
            }
        }
        $sorted = [];
        foreach ($ids as $id) {
            if (isset($out[$id])) {
                $sorted[$id] = $out[$id];
            }
        }

        return $sorted;
    }

    /**
     * The memo entry for a listing, or a new empty one when it is missing or a listing changed.
     *
     * @return array{gen:int,bare?:array<string,mixed>|null,full:array<string,array<string,mixed>>,photos?:array<int,array<string,mixed>>}
     */
    private static function entry(int $id, bool $memo): array
    {
        $gen = $memo ? CacheGroup::generation('search') : 0;

        return $memo && isset(self::$found[$id]) && self::$found[$id]['gen'] === $gen ? self::$found[$id] : ['gen' => $gen, 'full' => []];
    }

    /**
     * Drop what find() holds for a listing.
     */
    public static function forget(int $id): void
    {
        unset(self::$found[$id]);
    }

    /**
     * @param array{gen:int,bare?:array<string,mixed>|null,full:array<string,array<string,mixed>>,photos?:array<int,array<string,mixed>>} $entry
     */
    private static function remember(int $id, array $entry): void
    {
        if (!self::$hooked) {
            self::$hooked = true;
            osc_add_hook('invalidate_item_cache', static function ($itemId): void {
                self::forget((int) $itemId);
            });
        }
        self::$found[$id] = $entry;
    }

    /**
     * @param array<int,array<string,mixed>> $rows       t_item rows
     * @param bool                           $allLocales load every language's text, not just $locale's
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function extend(array $rows, string $locale, bool $allLocales): array
    {
        if ($rows === []) {
            return [];
        }
        $ids   = array_map(static fn (array $row): int => (int) $row['pk_i_id'], $rows);
        $texts = $this->texts($ids, $locale, $allLocales);
        $more  = [];
        foreach ($this->listings->statsAndLocations($ids) as $extra) {
            $more[(int) $extra['fk_i_item_id']] = $extra;
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['pk_i_id'];
            if (isset($texts[$id])) {
                $row['locale'] = $texts[$id];
            }
            [, $row['s_title'], $row['s_description']] = ListingText::pick($row['locale'] ?? [], $locale);
            $out[] = $row + ($more[$id] ?? []);
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int,array<string,array<string,string>>> item id => locale => s_title, s_description
     */
    private function texts(array $ids, string $locale, bool $allLocales): array
    {
        $rows = $this->listings->descriptions($ids, $allLocales ? null : $locale);
        $out  = self::group($rows);
        if (!$allLocales) {
            $missing = array_values(array_filter($ids, static fn (int $id): bool => ($out[$id][$locale]['s_title'] ?? '') === ''));
            foreach (self::group($this->listings->descriptions($missing)) as $id => $texts) {
                $out[$id] = $texts;
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,array<string,string>>>
     */
    private static function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id   = (int) $row['fk_i_item_id'];
            $code = (string) $row['fk_c_locale_code'];
            if ($row['s_title'] != '') {
                $out[$id][$code]['s_title'] = $row['s_title'];
            }
            if ($row['s_description'] != '') {
                $out[$id][$code]['s_description'] = $row['s_description'];
            }
        }

        return $out;
    }
}
