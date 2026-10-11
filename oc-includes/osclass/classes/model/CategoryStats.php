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

use mindstellar\listing\ListingCountStore;

/**
 * The live listing count of each category, kept in t_category.i_num_items
 *
 * @package    Shopclass
 * @subpackage Model
 */
class CategoryStats extends DAO
{
    use \mindstellar\base\SharedInstance;

    /**
     * No table is behind this DAO: the counts are in t_category.i_num_items, through ListingCountStore.
     * The DAO names a table that no longer exists, so the inherited DAO methods fail on purpose.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_category_stats');
        $this->setPrimaryKey('fk_i_category_id');
        $this->setFields(array('fk_i_category_id', 'i_num_items'));
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Add one to a category's count and to each of its parents'.
     *
     * @param int $categoryId
     *
     * @return bool|int true for a top category, the number of categories counted for a
     *                  subcategory, or false when the id is not a number or the write fails
     */
    public function increaseNumItems($categoryId)
    {
        if (!is_numeric($categoryId)) {
            return false;
        }
        $ids = iterator_to_array($this->chain((int)$categoryId), false);
        if ($ids === array()) {
            return false;
        }

        try {
            $changed = ListingCountStore::categories()->add($ids, 1);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
        if ($changed === 0) {
            return false;
        }

        return count($ids) === 1 ? true : count($ids);
    }

    /**
     * Take one from a category's count and from each of its parents', never below zero.
     *
     * @param int $categoryId
     *
     * @return int|false rows changed, or false when the id is missing or a write fails
     */
    public function decreaseNumItems($categoryId)
    {
        if ($categoryId === null || !is_numeric($categoryId)) {
            return false;
        }

        $return = 0;
        try {
            foreach ($this->chain((int)$categoryId) as $id) {
                $return += ListingCountStore::categories()->add(array($id), -1);
            }
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return $return;
    }

    /**
     * A category's id, then its ancestors' ids. A parent is looked up only when the caller asks for it.
     *
     * @return \Generator<int,int>
     */
    private function chain(int $categoryId): \Generator
    {
        $seen = array();
        for ($id = $categoryId; $id > 0 && !in_array($id, $seen, true);) {
            $seen[] = $id;
            yield $id;
            $result = Category::getInstance()->findByPrimaryKey($id);
            $id     = isset($result['fk_i_parent_id']) ? (int)$result['fk_i_parent_id'] : 0;
        }
    }

    /**
     * Set a category's count.
     *
     * @param int $categoryID
     * @param int $numItems
     *
     * @return bool false when the write fails
     */
    public function setNumItems($categoryID, $numItems)
    {
        try {
            return ListingCountStore::categories()->set(array((int)$categoryID => (int)$numItems));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * A category's count, as the old stats row: fk_i_category_id and i_num_items.
     *
     * @param int $categoryId
     *
     * @return array<string,string>|false False when there is no such category
     */
    public function findByCategoryId($categoryId)
    {
        try {
            return (ListingCountStore::categories()->row((int)$categoryId, 'fk_i_category_id') ?? false);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * A category's count.
     *
     * @param int $categoryId
     *
     * @return int|string the count as a string, or 0 when there is no such category
     */
    public function countItemsFromCategory($categoryId)
    {
        try {
            $row = (ListingCountStore::categories()->row((int)$categoryId, 'fk_i_category_id') ?? false);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        return $row === false ? 0 : $row['i_num_items'];
    }

    /**
     * Get number of items
     *
     * @param array{pk_i_id:int|string} $cat category array
     *
     * @return int|string The stored count, or int 0 when the category has none
     *
     * @staticvar string $numItemsMap
     */
    public function getNumItems($cat)
    {
        static $numItemsMap = null;
        if (null === $numItemsMap) {
            $numItemsMap = $this->toNumItemsMap();
        }
        if (isset($numItemsMap['parent'][$cat['pk_i_id']])) {
            return $numItemsMap['parent'][$cat['pk_i_id']]['numItems'];
        }

        // The subcategories map is keyed by ROOT id, each holding a map of its
        // own children by id, so a subcategory is found by searching those
        // child maps rather than indexing the root level with the child's id.
        foreach ($numItemsMap['subcategories'] ?? array() as $children) {
            if (isset($children[$cat['pk_i_id']])) {
                return $children[$cat['pk_i_id']]['numItems'];
            }
        }

        return 0;
    }

    /**
     * Build the per-category item-count map, split into 'parent' (root categories)
     * and 'subcategories' (children keyed by their root id).
     *
     * @return array<string,array<int|string,mixed>> Empty when no stats rows exist
     */
    public function toNumItemsMap()
    {
        try {
            $map = ListingCountStore::categories()->all();
        } catch (\mindstellar\database\DbException $e) {
            $map = array();
        }

        if (empty($map)) {
            return array();
        }

        $roots = Category::getInstance()->findRootCategories();

        $new_map = array();
        foreach ($roots as $root) {
            $root_description                    = Category::getInstance()->findByPrimaryKey($root['pk_i_id']);
            $new_map['parent'][$root['pk_i_id']] =
                array('numItems' => @$map[$root['pk_i_id']], 's_name' => @$root_description['s_name']);
            $subcategories                       = Category::getInstance()->findSubcategories($root['pk_i_id']);
            $aux                                 = array();
            foreach ($subcategories as $sub) {
                $sub_description      = Category::getInstance()->findByPrimaryKey($sub['pk_i_id']);
                $aux[$sub['pk_i_id']] =
                    array('numItems' => $map[$sub['pk_i_id']], 's_name' => $sub_description['s_name']);
            }
            $new_map['subcategories'][$root['pk_i_id']] = $aux;
        }

        return $new_map;
    }
}

/* file end: ./oc-includes/osclass/model/CategoryStats.php */
