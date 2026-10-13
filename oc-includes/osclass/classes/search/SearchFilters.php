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

use mindstellar\currency\Money;
use mindstellar\search\query\LocationFilter;
use mindstellar\search\query\SearchParts;

/**
 * What a built search filters by, as plain values (Search::filters()). A search backend reads
 * this instead of parsing toJson(), whose location, user and order entries are SQL text.
 *
 * A copy: changing it does not change the search.
 */
final class SearchFilters
{
    /** @var array<int,int> */
    public array $categories = array();
    /** Lower price bound in the site currency, null for none. */
    public ?float $priceMin = null;
    /** Upper price bound in the site currency, null for none. */
    public ?float $priceMax = null;
    /** The keywords, trimmed; null when there are none. */
    public ?string $pattern = null;
    /** @var array<int,string> locale codes the keywords are matched in; empty for the default */
    public array $locales = array();
    public bool $withPicture = false;
    public bool $onlyPremium = false;
    /**
     * Each level of LocationFilter::LEVELS (city_areas, cities, regions, countries) and its
     * values. `by` is `id`, `code` (a two-letter country) or `name` (matched with LIKE).
     *
     * @var array<string,array<int,array{by:string,value:int|string}>>
     */
    public array $locations = array();
    /** @var array{lat:float,lng:float,km:float}|null */
    public ?array $radius = null;
    /** @var array<int,int> */
    public array $userIds = array();
    /**
     * The order, most significant first. `table` is null for a bare column such as
     * `relevance`, else a name without DB_TABLE_PREFIX such as `t_item`.
     *
     * @var array<int,array{table:?string,column:string,direction:string}>
     */
    public array $order = array();
    public int $offset = 0;
    public int $perPage = 10;
    /**
     * True when code added SQL conditions, tables, joins, GROUP BY or HAVING. None of it is
     * shown here, so a backend that cannot run it should leave the search to MySQL.
     */
    public bool $rawSql = false;
    /** False when the search also returns hidden, disabled, spam or expired listings. */
    public bool $liveOnly = true;

    /**
     * @param SearchParts $parts
     *
     * @return self
     */
    public static function from(SearchParts $parts): self
    {
        $c = new self();

        $c->categories  = array_map('intval', $parts->categories->ids());
        $c->priceMin    = $parts->priceMin > 0 ? $parts->priceMin / Money::MICROS : null;
        $c->priceMax    = $parts->priceMax > 0 ? $parts->priceMax / Money::MICROS : null;
        $c->pattern     = $parts->pattern->active() ? $parts->pattern->recorded() : null;
        $c->locales     = array_values($parts->pattern->locales());
        $c->withPicture = (bool)$parts->withPicture;
        $c->onlyPremium = (bool)$parts->onlyPremium;
        foreach (LocationFilter::LEVELS as $level) {
            $c->locations[$level] = $parts->locations->values($level);
        }
        $c->radius  = $parts->radius->recorded();
        $c->userIds = $parts->users->ids();
        $c->order   = $parts->ordering->terms();
        $c->offset  = max(0, (int)$parts->ordering->offset());
        $c->perPage = (int)$parts->ordering->perPage();
        $c->rawSql   = $parts->plugin->changesRows($parts->liveConditions);
        $c->liveOnly = $parts->plugin->hasItemConditions($parts->liveConditions);

        return $c;
    }
}
