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

namespace mindstellar\search\query;

/**
 * Everything one Search has been asked for. The filters and the plugin clauses are
 * their own objects; the simple switches live here.
 */
final class SearchParts
{
    public PatternFilter $pattern;
    public LocationFilter $locations;
    public CategoryFilter $categories;
    public UserFilter $users;
    public PluginClauses $plugin;
    public Ordering $ordering;

    /** @var int|float price bounds in millionths, 0 for none */
    public $priceMin = 0;
    /** @var int|float */
    public $priceMax = 0;
    /** @var mixed */
    public $withPicture = false;
    /** @var mixed */
    public $onlyPremium = false;
    /** @var mixed null when not filtered by contact e-mail */
    public $contactEmail = null;
    public bool $byContactEmail = false;
    /** @var mixed */
    public $itemId = null;
    public bool $byItemId = false;

    /** @var array<int,string> the conditions that make a listing public */
    public array $liveConditions = array();
    /** @var mixed the locale a keyword search falls back to */
    public $userLocale = null;

    /** @var array<int,string> ORDER BY terms that go ahead of the sort, such as fromPrimaryKeys()' ranking */
    public array $leadOrder = array();

    /**
     * Conditions for the next statement only (notFromUser(), fromPrimaryKeys()): the
     * first statement built takes them, so a count run after it does not see them.
     *
     * @var array<int,array{0:string,1:array<int,mixed>}>
     */
    private array $once = array();

    public function __construct()
    {
        $this->pattern    = new PatternFilter();
        $this->locations  = new LocationFilter();
        $this->categories = new CategoryFilter();
        $this->users      = new UserFilter();
        $this->plugin     = new PluginClauses();
        $this->ordering   = new Ordering();
    }

    /**
     * @param string           $sql
     * @param array<int,mixed> $params
     *
     * @return void
     */
    public function addOnce(string $sql, array $params = array()): void
    {
        $this->once[] = array($sql, $params);
    }

    /**
     * A new statement, starting with the one-time conditions, which it takes.
     *
     * @return Statement
     */
    public function newStatement(): Statement
    {
        $statement = new Statement();
        foreach ($this->once as [$sql, $params]) {
            $statement->where($sql, $params);
        }
        $this->once = array();

        return $statement;
    }

    /**
     * Store a price range as millionths, the unit i_price holds.
     *
     * @param mixed $min
     * @param mixed $max
     *
     * @return void
     */
    public function priceRange($min, $max): void
    {
        $this->priceMin = 1000000 * ((int)$min);
        $this->priceMax = 1000000 * ((int)$max);
    }

    /**
     * Add the price bounds to $statement.
     *
     * @param Statement $statement
     *
     * @return void
     */
    public function applyPrice(Statement $statement): void
    {
        if ($this->priceMin != 0) {
            $statement->where('i_price >= ?', array($this->priceMin));
        }
        if ($this->priceMax > 0) {
            $statement->where('i_price <= ?', array($this->priceMax));
        }
    }
}
