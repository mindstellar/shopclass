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

use mindstellar\api\serializer\ListingSerializer;
use mindstellar\listing\ListingQuery;

/**
 * Bare t_item rows made into the rows the listing serializer reads: titles and descriptions
 * by language, then the view counters and location. A page loads only the asked language's
 * texts, plus every language of a listing that has no title in it.
 */
final class ListingRows
{
    public function __construct(private ListingQuery $listings)
    {
    }

    /**
     * One listing with every language's text, or null when there is no such listing.
     *
     * @param (callable(array<string,mixed>): ?array<string,mixed>)|null $keep checks the bare row first; null drops it before its texts are read
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id, string $locale, ?callable $keep = null): ?array
    {
        $row = $this->listings->find($id);
        if ($row !== null && $keep !== null) {
            $row = $keep($row);
        }

        return $row === null ? null : $this->extend([$row], $locale, true)[0];
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
            [, $row['s_title'], $row['s_description']] = ListingSerializer::localeText($row['locale'] ?? [], $locale);
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
