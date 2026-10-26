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

namespace mindstellar\location;

/**
 * Shows place names in the visitor's language, falling back to the stored name. It loads what a
 * page needs in at most one query per place type and remembers it for the rest of the request.
 */
final class LocationNames
{
    /** @var array<string,?string> "locale|type|id" => name, null for a known miss */
    private array $memo = [];

    public function __construct(private readonly LocationNameStore $store)
    {
    }

    /**
     * @param array<string,array<int,int|string>> $idsByType place type => ids
     */
    public function prime(string $locale, array $idsByType): void
    {
        foreach ($idsByType as $type => $ids) {
            $missing = [];
            foreach ($ids as $id) {
                $key = $this->id((string) $type, $id);
                if ($key !== '' && !array_key_exists($this->memoKey($locale, (string) $type, $key), $this->memo)) {
                    $missing[$key] = $key;
                }
            }
            if ($missing === []) {
                continue;
            }
            $found = $this->store->names((string) $type, array_values($missing), $locale);
            foreach ($missing as $key) {
                $this->memo[$this->memoKey($locale, (string) $type, $key)] = $found[$key] ?? null;
            }
        }
    }

    public function name(string $type, int|string $id, string $locale, string $fallback): string
    {
        $key = $this->id($type, $id);
        if ($key === '') {
            return $fallback;
        }
        $this->prime($locale, [$type => [$key]]);

        return $this->memo[$this->memoKey($locale, $type, $key)] ?? $fallback;
    }

    /**
     * @param array<int|string,array<string,mixed>> $rows listing rows
     *
     * @return array<int|string,array<string,mixed>>
     */
    public function translateRows(array $rows, string $locale): array
    {
        $levels = [
            LocationNameStore::CITY    => ['fk_i_city_id', 's_city'],
            LocationNameStore::REGION  => ['fk_i_region_id', 's_region'],
            LocationNameStore::COUNTRY => ['fk_c_country_code', 's_country'],
        ];
        $ids = [];
        foreach ($rows as $row) {
            foreach ($levels as $type => [$idKey]) {
                if (isset($row[$idKey]) && $this->id($type, $row[$idKey]) !== '') {
                    $ids[$type][] = $row[$idKey];
                }
            }
        }
        if ($ids === []) {
            return $rows;
        }
        $this->prime($locale, $ids);
        foreach ($rows as $i => $row) {
            foreach ($levels as $type => [$idKey, $nameKey]) {
                if (!isset($row[$idKey])) {
                    continue;
                }
                $key = $this->id($type, $row[$idKey]);
                $hit = $key === '' ? null : ($this->memo[$this->memoKey($locale, $type, $key)] ?? null);
                if ($hit !== null) {
                    $rows[$i][$nameKey] = $hit;
                }
            }
        }

        return $rows;
    }

    /**
     * Place lists. A list that shows at least one translated name is sorted again by the shown
     * name; a list with none keeps its order.
     *
     * @param array<int|string,array<string,mixed>> $rows
     *
     * @return array<int|string,array<string,mixed>>
     */
    public function translateList(array $rows, string $type, string $idKey, string $nameKey, string $locale): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (isset($row[$idKey])) {
                $ids[] = $row[$idKey];
            }
        }
        $this->prime($locale, [$type => $ids]);
        $changed = false;
        foreach ($rows as $i => $row) {
            if (!isset($row[$idKey])) {
                continue;
            }
            $key = $this->id($type, $row[$idKey]);
            $hit = $key === '' ? null : ($this->memo[$this->memoKey($locale, $type, $key)] ?? null);
            if ($hit !== null && $hit !== ($row[$nameKey] ?? null)) {
                $rows[$i][$nameKey] = $hit;
                $changed            = true;
            }
        }
        if (!$changed) {
            return $rows;
        }
        $names    = array_map(static fn (array $row): string => (string) ($row[$nameKey] ?? ''), $rows);
        $collator = class_exists(\Collator::class) ? \Collator::create($locale) : null;
        $compare  = $collator instanceof \Collator
            ? static fn (string $a, string $b): int => (int) $collator->compare($a, $b)
            : static fn (string $a, string $b): int => strnatcasecmp($a, $b);
        $order = array_keys($rows);
        usort($order, static fn ($a, $b): int => $compare($names[$a], $names[$b]));
        $sorted = [];
        foreach ($order as $i) {
            $sorted[] = $rows[$i];
        }

        return $sorted;
    }

    private function id(string $type, mixed $id): string
    {
        $key = trim((string) $id);

        return $type === LocationNameStore::COUNTRY ? strtolower($key) : ($key === '0' ? '' : $key);
    }

    private function memoKey(string $locale, string $type, string $id): string
    {
        return $locale . '|' . $type . '|' . $id;
    }
}
