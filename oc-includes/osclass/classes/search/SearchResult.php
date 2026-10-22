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
 * One page of listings from SearchRunner, with the total and the model that found them.
 */
class SearchResult
{
    /** @var array<int,array<string,mixed>> */
    private $items;

    /** @var int|null */
    private $total;

    /** @var \Search */
    private $model;

    /** @var SearchExtras */
    private $extras;

    /** @var bool */
    private $capped;

    /**
     * @param array<int,array<string,mixed>> $items
     * @param int|null                       $total null when the search was not counted
     * @param \Search                        $model the core search, or the one a search_results backend returned
     * @param SearchExtras|null              $extras what a search_results backend added; none when core searched
     * @param bool                           $capped the count stopped at its cap: $total is the cap and there are more
     */
    public function __construct(array $items, $total, \Search $model, ?SearchExtras $extras = null, bool $capped = false)
    {
        $this->items  = $items;
        $this->total  = $total;
        $this->model  = $model;
        $this->extras = $extras ?? SearchExtras::none();
        $this->capped = $capped && $total !== null;
    }

    /** @return array<int,array<string,mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Total matches across all pages, or null when the search was not counted.
     *
     * @return int|null
     */
    public function total()
    {
        return $this->total;
    }

    /**
     * Whether the count stopped at the search count cap, so there are more matches than total().
     */
    public function totalCapped(): bool
    {
        return $this->capped;
    }

    public function model(): \Search
    {
        return $this->model;
    }

    public function extras(): SearchExtras
    {
        return $this->extras;
    }
}
