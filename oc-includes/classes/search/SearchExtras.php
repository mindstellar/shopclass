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

namespace mindstellar\search;

/**
 * What a search engine adds to a page of results besides the listings: category counts,
 * a corrected query and whether a fuzzy search found them. Empty when core searched.
 */
final class SearchExtras
{
    /**
     * @param array<int,int> $categoryCounts category id => listings found in it
     */
    private function __construct(private array $categoryCounts, private string $suggestion, private bool $relaxed)
    {
    }

    public static function none(): self
    {
        return new self([], '', false);
    }

    /**
     * The optional keys of a `search_results` answer, with bad values dropped.
     *
     * @param array<string,mixed> $answer
     */
    public static function fromAnswer(array $answer): self
    {
        $counts = [];
        $facets = $answer['facets']['category'] ?? [];
        foreach (is_array($facets) ? $facets : [] as $id => $count) {
            if ((int) $id > 0 && is_numeric($count) && (int) $count > 0) {
                $counts[(int) $id] = (int) $count;
            }
        }
        $suggestion = is_string($answer['suggestion'] ?? null) ? trim(strip_tags($answer['suggestion'])) : '';

        return new self($counts, $suggestion, ($answer['relaxed'] ?? false) === true);
    }

    /**
     * @return array<int,int> category id => listings found in it, as the engine counted them
     */
    public function categoryCounts(): array
    {
        return $this->categoryCounts;
    }

    /** A corrected query, or '' when there is none. Plain text, not escaped. */
    public function suggestion(): string
    {
        return $this->suggestion;
    }

    /** Whether a fuzzy or relaxed search supplied the results. */
    public function relaxed(): bool
    {
        return $this->relaxed;
    }
}
