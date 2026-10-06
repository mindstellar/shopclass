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
 * The category filter: each category asked for, with its whole subtree.
 */
final class CategoryFilter
{
    /** @var array<int,mixed> ids as given or as the category tree holds them */
    private array $ids = array();

    /**
     * Add a category by id, slug or slug path, with its subcategories.
     *
     * @param mixed $category
     *
     * @return bool false when it is empty or unknown
     */
    public function add($category): bool
    {
        if ($category == null) {
            return false;
        }

        if (!is_numeric($category)) {
            $category  = preg_replace('|/$|', '', (string)$category);
            $aCategory = explode('/', (string)$category);
            $found     = \Category::getInstance()->findBySlug($aCategory[count($aCategory) - 1]);

            if (count($found) == 0) {
                return false;
            }

            $category = $found['pk_i_id'];
        }
        $tree = \Category::getInstance()->toSubTree($category);
        if (!in_array($category, $this->ids)) {
            $this->ids[] = $category;
        }
        $this->addBranches($tree);

        return true;
    }

    /**
     * Replace the list.
     *
     * @param array<int,int> $ids
     *
     * @return void
     */
    public function set(array $ids): void
    {
        $this->ids = $ids;
    }

    /**
     * @return array<int,mixed>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * Add the IN condition to $statement.
     *
     * @param Statement $statement
     *
     * @return void
     */
    public function apply(Statement $statement): void
    {
        if ($this->ids !== array()) {
            $statement->where(
                DB_TABLE_PREFIX . 't_item.fk_i_category_id IN (' . SqlValue::placeholders(count($this->ids)) . ')',
                array_map(array(SqlValue::class, 'number'), $this->ids)
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>>|null $branches
     *
     * @return void
     */
    private function addBranches($branches): void
    {
        if ($branches != null) {
            foreach ($branches as $branch) {
                if (!in_array($branch['pk_i_id'], $this->ids)) {
                    $this->ids[] = $branch['pk_i_id'];
                    if (isset($branch['categories'])) {
                        $this->addBranches($branch['categories']);
                    }
                }
            }
        }
    }
}
