<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\search\query;

/**
 * The raw SQL fragments plugins and core add to a search: conditions, listing
 * conditions, fields, tables, joins, GROUP BY and HAVING.
 *
 * They are trusted code and go into the statement as written. Conditions, listing
 * conditions and fields pass through the sql_search_conditions,
 * sql_search_item_conditions and sql_search_fields filters first.
 */
final class PluginClauses
{
    /** @var array<int,string> */
    private array $conditions = array();
    /** @var array<int,string|null> the count text of each condition, by position; null = the same text */
    private array $countConditions = array();
    /** @var array<int,string> */
    private array $itemConditions = array();
    /** @var array<int,string> */
    private array $fields = array();
    /** @var array<int,string> */
    private array $tables = array();
    /** @var array<string|int,array{0:string,1:string,2:string}> */
    private array $joins = array();
    /** @var string|array<int,string> */
    private $groupBy = '';
    /** @var string|array<string,mixed> */
    private $having = '';

    /**
     * A condition already set keeps the count text it was added with.
     *
     * @param mixed                       $conditions      a fragment or a list
     * @param array<int,string|null>|null $countConditions the text the count uses instead, by position; null = the same
     *
     * @return void
     */
    public function addConditions($conditions, ?array $countConditions = null): void
    {
        $countConditions = $countConditions === null ? array() : array_values($countConditions);
        foreach (array_values(is_array($conditions) ? $conditions : array($conditions)) as $i => $value) {
            $value = trim((string)$value);
            if ($value && !in_array($value, $this->conditions)) {
                $this->conditions[]      = $value;
                $count                   = isset($countConditions[$i]) ? trim((string)$countConditions[$i]) : '';
                $this->countConditions[] = $count !== '' && $count !== $value ? $count : null;
            }
        }
    }

    /**
     * @param mixed $conditions a fragment or a list
     *
     * @return void
     */
    public function addItemConditions($conditions): void
    {
        self::addUnique($this->itemConditions, $conditions);
    }

    /**
     * Drop these listing conditions.
     *
     * @param array<int,string> $conditions
     *
     * @return void
     */
    public function removeItemConditions(array $conditions): void
    {
        $this->itemConditions = array_values(array_diff($this->itemConditions, $conditions));
    }

    /**
     * Add select fields. A field equal to one of $known is skipped.
     *
     * @param mixed             $fields a field or a list
     * @param array<int,string> $known
     *
     * @return void
     */
    public function addField($fields, array $known): void
    {
        foreach (is_array($fields) ? $fields : array($fields) as $field) {
            $field = trim((string)$field);
            if ($field && !in_array($field, $known)) {
                $this->fields[] = $field;
            }
        }
    }

    /**
     * @param mixed $tables a table or a list
     *
     * @return void
     */
    public function addTable($tables): void
    {
        self::addUnique($this->tables, $tables);
    }

    /**
     * @param mixed  $key
     * @param mixed  $table
     * @param mixed  $condition
     * @param mixed  $type
     *
     * @return void
     */
    public function addJoin($key, $table, $condition, $type): void
    {
        $this->joins[$key] = array($table, $condition, $type);
    }

    /**
     * @param mixed $groupBy
     *
     * @return void
     */
    public function setGroupBy($groupBy): void
    {
        $this->groupBy = is_array($groupBy) ? $groupBy : (string)$groupBy;
    }

    /**
     * @param mixed $having
     *
     * @return void
     */
    public function setHaving($having): void
    {
        $this->having = is_array($having) ? $having : (string)$having;
    }

    /**
     * Drop the conditions, tables and joins an old-format alert carried.
     *
     * @return void
     */
    public function clearAlertParts(): void
    {
        $this->conditions      = array();
        $this->countConditions = array();
        $this->tables          = array();
        $this->joins           = array();
    }

    /**
     * Whether a condition, table, join, GROUP BY or HAVING was added. Extra fields do not count:
     * they do not change which rows match.
     *
     * @param array<int,string> $known           listing conditions core added itself, which do not count
     * @param array<int,string> $knownConditions conditions shown elsewhere, which do not count
     *
     * @return bool
     */
    public function changesRows(array $known = array(), array $knownConditions = array()): bool
    {
        return array_diff($this->conditions, $knownConditions) !== array()
            || array_diff($this->itemConditions, $known) !== array()
            || $this->tables !== array() || $this->joins !== array() || $this->groupBy != '' || $this->having != '';
    }

    /**
     * Whether this condition is set.
     *
     * @param string $condition
     *
     * @return bool
     */
    public function hasCondition(string $condition): bool
    {
        return in_array($condition, $this->conditions, true);
    }

    /**
     * Whether every one of these listing conditions is set.
     *
     * @param array<int,string> $conditions
     *
     * @return bool
     */
    public function hasItemConditions(array $conditions): bool
    {
        return array_diff($conditions, $this->itemConditions) === array();
    }

    /**
     * @return array<int,string>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /**
     * The count text of each condition that has its own, by the condition's page text.
     *
     * @return array<string,string>
     */
    public function countConditions(): array
    {
        $out = array();
        foreach ($this->countConditions as $i => $count) {
            if ($count !== null) {
                $out[$this->conditions[$i]] = $count;
            }
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * @return array<string|int,array{0:string,1:string,2:string}>
     */
    public function joins(): array
    {
        return $this->joins;
    }

    /**
     * The extra select fields, filtered, as the comma list the statement splits.
     *
     * @return string
     * @phpstan-impure it fires a filter
     */
    public function fieldList(): string
    {
        if ($this->fields === array()) {
            return '';
        }

        return ',' . implode(' ,', osc_apply_filter('sql_search_fields', $this->fields));
    }

    /**
     * The plugin conditions, filtered and AND-joined. With $count each condition gives its count
     * text where it has one. The filter runs once for the page query and once for the count query.
     * A facet filter reads differently in the two (a per-row probe for the page, an IN form for the count),
     * so a plugin that edits a core condition by its text must handle both forms.
     *
     * @param bool $count
     *
     * @return string
     * @phpstan-impure it fires a filter
     */
    public function conditionsSql(bool $count = false): string
    {
        $list = $this->conditions;
        if ($count) {
            foreach ($this->countConditions as $i => $text) {
                $list[$i] = $text ?? $list[$i];
            }
        }
        $sql = implode(' AND ', osc_apply_filter('sql_search_conditions', $list));

        return $sql !== '' ? ' ' . $sql : '';
    }

    /**
     * Add the listing conditions to $statement.
     *
     * @param Statement $statement
     *
     * @return void
     */
    public function applyItemConditions(Statement $statement): void
    {
        if ($this->itemConditions !== array()) {
            $statement->where(implode(' AND ', osc_apply_filter('sql_search_item_conditions', $this->itemConditions)));
        }
    }

    /**
     * Add the joins, the extra tables, the plugin conditions, GROUP BY and HAVING.
     *
     * @param Statement $statement
     * @param string    $conditionsSql conditionsSql(), built before the statement
     *
     * @return void
     */
    public function applyRest(Statement $statement, string $conditionsSql): void
    {
        foreach ($this->joins as $join) {
            $statement->join((string)$join[0], (string)$join[1], (string)$join[2]);
        }
        if ($this->tables !== array()) {
            $statement->from(implode(', ', $this->tables));
        }
        if ($this->conditions !== array()) {
            $statement->where($conditionsSql);
        }
        if ($this->groupBy) {
            $statement->groupBy($this->groupBy);
        }
        if ($this->having) {
            $statement->having($this->having);
        }
    }

    /**
     * @param array<int,string> $list
     * @param mixed             $values
     *
     * @return void
     */
    private static function addUnique(array &$list, $values): void
    {
        foreach (is_array($values) ? $values : array($values) as $value) {
            $value = trim((string)$value);
            if ($value && !in_array($value, $list)) {
                $list[] = $value;
            }
        }
    }
}
