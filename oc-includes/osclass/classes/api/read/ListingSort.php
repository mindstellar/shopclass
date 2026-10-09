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

use mindstellar\api\Request;

/**
 * How a listing search is ordered: the sort and its direction, the t_item columns that make
 * the order total (the id breaks ties), and the keyset a cursor resumes from. Relevance has no
 * keyset: its score is a computed float, not a column, so it pages by offset.
 */
final class ListingSort
{
    public const SORTS = ['created', 'id', 'price', 'relevance'];

    /** sort => the search page's sOrder; `id` is reordered by columns(). */
    private const SEARCH_ORDER = ['created' => 'dt_pub_date', 'id' => 'dt_pub_date', 'price' => 'i_price', 'relevance' => 'relevance'];

    /** sort => t_item columns, most significant first; relevance keeps the search's own order. */
    private const COLUMNS = ['created' => ['dt_pub_date', 'pk_i_id'], 'id' => ['pk_i_id'], 'price' => ['i_price', 'pk_i_id'], 'relevance' => []];

    /** Columns that may hold NULL. MySQL sorts NULL first ascending and last descending. */
    private const NULLABLE = ['i_price'];

    private const DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D';

    private function __construct(private string $name, private string $direction)
    {
    }

    /**
     * The sort a request asks for: relevance for a pattern search, else newest first.
     */
    public static function fromRequest(Request $request): self
    {
        $name = $request->queryString('sort', trim($request->queryString('q')) === '' ? 'created' : 'relevance');

        return self::of($name, $request->queryString('order'));
    }

    /**
     * An unknown sort is `created`; any direction but `asc` is `desc`.
     */
    public static function of(string $name, string $direction = 'desc'): self
    {
        return new self(in_array($name, self::SORTS, true) ? $name : 'created', $direction === 'asc' ? 'asc' : 'desc');
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * `asc` or `desc`.
     */
    public function direction(): string
    {
        return $this->direction;
    }

    /**
     * How a listing search with this sort pages.
     */
    public function spec(int $defaultLimit, int $maxLimit): ListSpec
    {
        return new ListSpec(self::SORTS, $this->name, $this->direction, $defaultLimit, $maxLimit, [$this, 'keysetFits'], [$this, 'keyset']);
    }

    /**
     * The search page's `sOrder` for this sort.
     */
    public function searchOrder(): string
    {
        return self::SEARCH_ORDER[$this->name];
    }

    /**
     * The order as Search::orderBy() takes it; empty for relevance.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public function columns(): array
    {
        $direction = strtoupper($this->direction);

        return array_map(static fn (string $column): array => [$column, $direction], self::COLUMNS[$this->name]);
    }

    /**
     * Whether a keyset cursor's values fit this sort: `[datetime, id]` for created, `[id]`
     * for id, `[price or null, id]` for price. Only a plain datetime and ints reach the SQL.
     *
     * @param array<int,int|string|null> $after
     */
    public function keysetFits(array $after): bool
    {
        return match ($this->name) {
            'created' => count($after) === 2 && is_string($after[0]) && preg_match(self::DATETIME, $after[0]) === 1 && is_int($after[1]),
            'id'      => count($after) === 1 && is_int($after[0]),
            'price'   => count($after) === 2 && ($after[0] === null || is_int($after[0])) && is_int($after[1]),
            default   => false,
        };
    }

    /**
     * The keyset values of a row.
     *
     * @param array<string,mixed> $row
     *
     * @return array<int,int|string|null>
     */
    public function keyset(array $row): array
    {
        return match ($this->name) {
            'created' => [(string) $row['dt_pub_date'], (int) $row['pk_i_id']],
            'price'   => [$row['i_price'] === null ? null : (int) $row['i_price'], (int) $row['pk_i_id']],
            default   => [(int) $row['pk_i_id']],
        };
    }

    /**
     * The condition for rows after a keyset, with `?` for each value. For created desc it is
     * `(t.dt_pub_date < ? OR (t.dt_pub_date = ? AND t.pk_i_id < ?))`; a nullable column also
     * places NULL where MySQL sorts it.
     *
     * @param string                     $table the qualified t_item table name
     * @param array<int,int|string|null> $after values that passed keysetFits()
     *
     * @return array{0:string,1:array<int,int|string>} the SQL and its values
     */
    public function after(string $table, array $after): array
    {
        $asc     = $this->direction === 'asc';
        $op      = $asc ? '>' : '<';
        $columns = self::COLUMNS[$this->name];
        $last    = count($columns) - 1;
        $sql     = $table . '.' . $columns[$last] . ' ' . $op . ' ?';
        $params  = [$after[$last]];
        for ($i = $last - 1; $i >= 0; $i--) {
            $column = $table . '.' . $columns[$i];
            $value  = $after[$i];
            if ($value === null) {
                $sql = $asc ? '(' . $column . ' IS NOT NULL OR (' . $column . ' IS NULL AND ' . $sql . '))' : '(' . $column . ' IS NULL AND ' . $sql . ')';
                continue;
            }
            $nulls  = !$asc && in_array($columns[$i], self::NULLABLE, true) ? ' OR ' . $column . ' IS NULL' : '';
            $sql    = '(' . $column . ' ' . $op . ' ?' . $nulls . ' OR (' . $column . ' = ? AND ' . $sql . '))';
            $params = [$value, $value, ...$params];
        }

        return [$sql, $params];
    }
}
