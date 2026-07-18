<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Category DAO
 */
class Category extends DAO
{
    /**
     *
     * @var \Category
     */
    private static $instance;
    private $language;
    private $tree;
    private $categories;
    private $categoriesEnabled;
    private $relation;
    private $emptyTree;
    private $slugs;
    /**
     * @var bool
     */
    private $empty_tree;

    /**
     * Set data related to t_category table
     *
     * @param string $l
     */
    public function __construct($l = '')
    {
        parent::__construct();
        $this->setTableName('t_category');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            'fk_i_parent_id',
            'i_expiration_days',
            'i_position',
            'b_enabled',
            's_icon',
            'b_price_enabled'
        );
        $this->setFields($array_fields);

        if ($l == '') {
            $l = osc_current_user_locale();
            if (OC_ADMIN) {
                $l = osc_current_admin_locale();
            }
        }

        $this->language  = $l;
        $this->emptyTree = true;
        $this->toTree();
    }

    /**
     * Return categories in a tree
     *
     * @access public
     *
     * @param bool $empty
     *
     * @return array
     * @since  unknown
     */
    public function toTree(bool $empty = true)
    {
        $key   = md5(osc_base_url() . (string)$this->language . (string)$empty);
        $found = null;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            if ($empty == $this->emptyTree && $this->tree != null) {
                return $this->tree;
            }
            $this->empty_tree = $empty;
            // if listEnabled has been called before, don't redo the query
            if ($this->categoriesEnabled) {
                $categories = $this->categoriesEnabled;
            } else {
                $this->categoriesEnabled = $this->listEnabled();
                $categories              = $this->categoriesEnabled;
            }
            $this->categories = array();
            $this->relation   = array();
            foreach ($categories as $c) {
                if ($empty || ($c['i_num_items'] > 0)) {
                    $this->categories[$c['pk_i_id']] = $c;
                    if ($c['fk_i_parent_id'] == null) {
                        $this->tree[]        = $c;
                        $this->relation[0][] = $c['pk_i_id'];
                    } else {
                        $this->relation[$c['fk_i_parent_id']][] = $c['pk_i_id'];
                    }
                }
            }

            if (count($this->relation) == 0 || !isset($this->relation[0])) {
                return array();
            }

            $this->tree = $this->sideTree($this->relation[0], $this->categories, $this->relation);
            $cache                      = [];
            $cache['tree']              = $this->tree;
            $cache['empty_tree']        = $this->emptyTree;
            $cache['relation']          = $this->relation;
            $cache['categories']        = $this->categories;
            $cache['categoriesEnabled'] = $this->categoriesEnabled;
            osc_cache_set($key, $cache, OSC_CACHE_TTL);

            return $this->tree;
        }

        $this->tree              = $cache['tree'];
        $this->empty_tree        = $cache['empty_tree'];
        $this->relation          = $cache['relation'];
        $this->categories        = $cache['categories'];
        $this->categoriesEnabled = $cache['categoriesEnabled'];

        return $this->tree;
    }

    /**
     * List all enabled categories
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function listEnabled()
    {
        // $this->dao->where("b.s_name != ''");
        // $this->dao->where("a.b_enabled = 1");

        return $this->listWhere("b.s_name != '' AND a.b_enabled = 1");
    }

    /**
     * Comodin function to serve multiple queries
     *
     * *Note: param needs to be escaped, inside function will not be escaped
     *
     * @access public
     *
     * @param mixed
     *
     * @return array
     * @since  unknown
     */
    public function listWhere()
    {

        $argv = func_get_args();
        $sql  = null;
        switch (func_num_args()) {
            case 0:
                return array();
                break;
            case 1:
                $sql = $argv[0];
                break;
            default:
                $args   = func_get_args();
                $format = array_shift($args);
                foreach ($args as $k => $v) {
                    $args[$k] = $this->dao->escape($v);
                }
                $sql = vsprintf($format, $args);
                break;
        }

        // Category with Description and category stats
        $this->dao->select('a.*, b.*, c.i_num_items');
        $this->dao->from($this->getTableName() . ' as a');
        $this->dao->join(
            DB_TABLE_PREFIX . 't_category_description as b', '(a.pk_i_id = b.fk_i_category_id )', 'LEFT'
        );
        $this->dao->join(DB_TABLE_PREFIX . 't_category_stats  as c ', 'a.pk_i_id = c.fk_i_category_id', 'LEFT');
        if ($sql != null) {
            $this->dao->where($sql);
        }
        $this->dao->orderBy('i_position', 'ASC');
        $rs = $this->dao->get();

        if ($rs === false) {
            return array();
        }
        if ($rs->numRows() === 0) {
            return array();
        }

        $allResults = $rs->result();
        $mergedCategories = [];
        foreach ($allResults as $cat) {
            // merge all the array with the same pk_i_id
            if (!isset($mergedCategories[$cat['pk_i_id']])) {
                $mergedCategories[$cat['pk_i_id']] = $cat;
            }
            if (!empty($cat['s_name'])) {
                // merge fk_i_category_id, fk_c_locale_code, s_name, s_description in locale index
                $mergedCategories[$cat['pk_i_id']]['locale'][$cat['fk_c_locale_code']] = [
                    's_name'           => $cat['s_name'],
                    's_description'    => $cat['s_description'],
                    's_slug'           => $cat['s_slug'],
                ];
            }
        }
        // correct locale to $this->language if exists or set it to first locale
        foreach ($mergedCategories as $k => $cat) {
            if (isset($cat['locale'][$this->language])) {
                $mergedCategories[$k] = array_merge($cat, $cat['locale'][$this->language]);
            } elseif (empty($cat['s_name']) && empty($cat['s_description'])) {
                // get first locale
                if (isset($cat['locale'])) {
                    $mergedCategories[$k] = array_merge($cat, $cat['locale'][key($cat['locale'])]);
                }
            }
            $mergedCategories[$k]['fk_c_locale_code'] = $this->language;
            //unset($mergedCategories[$k]['locale']);
        }
        // reset keys, same as old ways
        return array_values($mergedCategories);
    }

    /**
     * Helps create the tree
     *
     * @access private
     *
     * @param array $branch
     * @param array $categories
     * @param array $relation
     *
     * @return array
     * @since  unknown
     */
    private function sideTree($branch, $categories, $relation)
    {
        $tree = array();
        if (!empty($branch)) {
            foreach ($branch as $b) {
                $aux = $categories[$b];
                if (isset($relation[$b]) && is_array($relation[$b])) {
                    $aux['categories'] = $this->sideTree($relation[$b], $categories, $relation);
                } else {
                    $aux['categories'] = array();
                }
                $tree[] = $aux;
            }
        }

        return $tree;
    }

    /**
     * @param string $l
     *
     * @return \Category
     */
    public static function newInstance($l = '')
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self($l);
        }

        return self::$instance;
    }

    /**
     * Find root categories
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function findRootCategories()
    {
        // juanramon: specific condition
        // $this->dao->where( 'a.fk_i_parent_id IS NULL' );
        // end specific condition

        return $this->listWhere('a.fk_i_parent_id IS NULL');
    }

    /**
     * Find root enabled categories
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function findRootCategoriesEnabled()
    {
        // juanramon: specific condition
        // $this->dao->where( 'a.fk_i_parent_id IS NULL' );
        // $this->dao->where( 'a.b_enabled', '1' );
        // end specific condition

        return $this->listWhere('a.fk_i_parent_id IS NULL AND a.b_enabled = 1');
    }

    /**
     * Returna  tree of a given category as the root
     *
     * @access public
     *
     * @param integer $category
     *
     * @return array
     * @since  unknown
     */
    public function toSubTree($category = null)
    {
        $this->toTree();
        if ($category == null) {
            return array();
        }

        if (isset($this->relation[$category])) {
            return $this->sideTree($this->relation[$category], $this->categories, $this->relation);
        }

        return array();
    }

    /**
     * Return a tree of ALL (enabled & disabled) categories
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function toTreeAll()
    {
        $categories     = $this->listAll();
        $all_categories = array();
        $all_relation   = array();
        $tree           = array();
        foreach ($categories as $c) {
            $all_categories[$c['pk_i_id']] = $c;
            if ($c['fk_i_parent_id'] == null) {
                $tree[]            = $c;
                $all_relation[0][] = $c['pk_i_id'];
            } else {
                $all_relation[$c['fk_i_parent_id']][] = $c['pk_i_id'];
            }
        }
        if (isset($all_relation[0])) {
            $tree = $this->sideTree($all_relation[0], $all_categories, $all_relation);
        } else {
            $tree = array();
        }

        return $tree;
    }

    /**
     * List all categories
     *
     * @access public
     *
     * @param bool $description
     *
     * @return array
     * @since  unknown
     */
    public function listAll($description = true)
    {
        // juanramon: specific condition
        // $this->dao->where( '1 = 1' );
        // end specific condition

        return $this->listWhere('1 = 1');
    }

    /**
     * Return the root category of a one given
     *
     * @access public
     *
     * @param integer $categoryID
     *
     * @return array
     * @since  unknown
     */
    public function findRootCategory($categoryID)
    {
        // juanramon: specific condition
        // $this->dao->where( 'a.fk_i_parent_id IS NOT NULL' );
        // $this->dao->where( 'a.pk_i_id', $categoryID );
        // end specific condition

        $results = $this->listWhere('a.fk_i_parent_id IS NOT NULL AND a.pk_i_id = %d', (int)$categoryID);

        if (count($results) > 0) {
            return $this->findRootCategory($results[0]['fk_i_parent_id']);
        }

        return $this->findByPrimaryKey($categoryID);
    }

    /**
     * Return a category given an id
     * This overwrite findByPrimaryKey of DAO model because we store the
     * categories on an array for the tree and it's faster than a SQL query
     *
     * @access public
     *
     * @param int    $categoryID primary key
     * @param string $locale
     *
     * @return array|bool
     *
     * @since  unknown
     */
    public function findByPrimaryKey($categoryID, $locale = '')
    {
        if ($categoryID == null) {
            return false;
        }
        $key   = md5(osc_base_url() . 'Category:findByPrimaryKey:' . $categoryID . $locale);
        $found = null;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            $category = array();

            if (isset($this->categories[$categoryID])) {
                $category = $this->categories[$categoryID];

                // if we already have locale data, we return the category
                if ($locale == '' || ($locale != '' && isset($category['locale']))) {
                    if ($locale != '' && isset($category['locale'][$locale])) {
                        $category['s_name']        = $category['locale'][$locale]['s_name'];
                        $category['s_description'] = $category['locale'][$locale]['s_description'];
                    }
                    osc_cache_set($key, $category, OSC_CACHE_TTL);

                    return $category;
                }
            } else {
                // $this->dao->where('pk_i_id', $categoryID);
                $category = $this->listWhere('a.pk_i_id = %d', (int)$categoryID);

                if (!isset($category[0]) || !isset($category[0]['pk_i_id'])) {
                    return false;
                }
                $category = $category[0];
            }

            $this->dao->select();
            $this->dao->from($this->getTablePrefix() . 't_category_description');
            $this->dao->where('fk_i_category_id', $category['pk_i_id']);
            $this->dao->orderBy('fk_c_locale_code');
            $result = $this->dao->get();

            if ($result == false) {
                return false;
            }

            $sub_rows = $result->result();
            $row      = array();
            foreach ($sub_rows as $sub_row) {
                if (isset($sub_row['fk_c_locale_code'])) {
                    $row[$sub_row['fk_c_locale_code']] = $sub_row;
                }
            }
            $category['locale'] = $row;

            // if it exists in the $categories array, we copy the row data
            if (array_key_exists($categoryID, $this->categories)) {
                $this->categories[$categoryID] = $category;
            }
            if ($locale != '' && isset($category['locale'][$locale])) {
                $category['s_name']        = $category['locale'][$locale]['s_name'];
                $category['s_description'] = $category['locale'][$locale]['s_description'];
            }
            osc_cache_set($key, $category, OSC_CACHE_TTL);

            return $category;
        }

        return $cache;
    }

    /**
     * delete a category and all information linked to it
     *
     * @access       public
     *
     * @param integer $pk primary key
     *
     * @return bool|int
     * @since        unknown
     */
    public function deleteByPrimaryKey($pk)
    {
        $items   = Item::newInstance()->findByCategoryID((int)($pk));
        $subcats = $this->findSubcategories((int)($pk));
        if (count($subcats) > 0) {
            foreach ($subcats as $s) {
                $this->deleteByPrimaryKey((int)($s['pk_i_id']));
            }
        }

        if (count($items) > 0) {
            foreach ($items as $item) {
                Item::newInstance()->deleteByPrimaryKey($item['pk_i_id']);
            }
        }

        osc_run_hook('delete_category', (int)($pk));

        $this->dao->delete(sprintf('%st_plugin_category', DB_TABLE_PREFIX), array('fk_i_category_id' => (int)($pk)));
        $this->dao->delete(
            sprintf('%st_category_description', DB_TABLE_PREFIX),
            array('fk_i_category_id' => (int)($pk))
        );
        $this->dao->delete(sprintf('%st_category_stats', DB_TABLE_PREFIX), array('fk_i_category_id' => (int)($pk)));
        $this->dao->delete(sprintf('%st_meta_categories', DB_TABLE_PREFIX), array('fk_i_category_id' => (int)($pk)));

        return $this->dao->delete(sprintf('%st_category', DB_TABLE_PREFIX), array('pk_i_id' => (int)($pk)));
    }

    /**
     * returns the children of a given category
     *
     * @access public
     *
     * @param integer $categoryID
     *
     * @return array
     * @since  unknown
     */
    public function findSubcategories($categoryID)
    {
        // $this->dao->where( 'fk_i_parent_id', (int)($categoryID));
        return $this->listWhere('fk_i_parent_id = %d', (int)$categoryID);
    }

    /**
     * Update a category
     *
     * @access public
     *
     * @param     $data
     * @param int $pk primary key
     *
     * @return mixed bool if there is an error, affectedRows if there isn't errors
     * @since  unknown
     */
    public function updateByPrimaryKey($data, $pk)
    {
        $fields = $data['fields'];

        $aFieldsDescription = $data['aFieldsDescription'];
        $return             = true;
        $affectedRows       = 0;
        //UPDATE for category
        $res = $this->dao->update($this->getTableName(), $fields, array('pk_i_id' => $pk));
        if ($res >= 0) {
            // update dt_expiration (table t_item) using category.i_expiration_days
            if ($fields['i_expiration_days'] > 0) {
                $update_dt_expiration = sprintf('update %st_item as a
                        left join %st_category  as b on b.pk_i_id = a.fk_i_category_id
                        set a.dt_expiration = date_add(a.dt_pub_date, INTERVAL b.i_expiration_days DAY)
                        where a.fk_i_category_id = %d ', DB_TABLE_PREFIX, DB_TABLE_PREFIX, $pk);

                $this->dao->query($update_dt_expiration);
                // update dt_expiration (table t_item) using the max date value
            } elseif ($fields['i_expiration_days'] == 0) {
                $update_dt_expiration = sprintf("update %st_item as a
                        set a.dt_expiration = '9999-12-31 23:59:59'
                        where a.fk_i_category_id = %s", DB_TABLE_PREFIX, $pk);

                $this->dao->query($update_dt_expiration);
            }

            $affectedRows = $res;

            foreach ($aFieldsDescription as $k => $fieldsDescription) {
                //UPDATE for description of categories
                $fieldsDescription['fk_i_category_id'] = $pk;
                $fieldsDescription['fk_c_locale_code'] = $k;
                if (isset($fieldsDescription['s_slug']) && trim($fieldsDescription['s_slug']) !== '') {
                    $slug = osc_sanitizeString(osc_apply_filter('slug', trim($fieldsDescription['s_slug'])));
                } else {
                    $slug = osc_sanitizeString(osc_apply_filter(
                        'slug',
                        isset($fieldsDescription['s_name']) ? $fieldsDescription['s_name'] : ''
                    ));
                }
                $slug_tmp                              = $slug;
                $slug_unique                           = 1;
                while (true) {
                    $cat_slug = $this->findBySlug($slug);
                    if (!isset($cat_slug['pk_i_id']) || $cat_slug['pk_i_id'] == $pk) {
                        break;
                    }

                    $slug = $slug_tmp . '_' . $slug_unique;
                    $slug_unique++;
                }
                $fieldsDescription['s_slug'] = $slug;
                $array_where                 = array(
                    'fk_i_category_id' => $pk,
                    'fk_c_locale_code' => $fieldsDescription['fk_c_locale_code']
                );

                $rs = $this->dao->update(DB_TABLE_PREFIX . 't_category_description', $fieldsDescription, $array_where);
                if ($rs == 0) {
                    $this->dao->select();
                    $this->dao->from($this->tableName . ' as a');
                    $this->dao->join(
                        sprintf('%st_category_description as b', DB_TABLE_PREFIX),
                        'a.pk_i_id = b.fk_i_category_id',
                        'INNER'
                    );
                    $this->dao->where('a.pk_i_id', $pk);
                    $this->dao->where('b.fk_c_locale_code', $k);
                    $result = $this->dao->get();
                    $rows   = $result->result();
                    if ($result->numRows == 0) {
                        $res_insert = $this->insertDescription($fieldsDescription);
                        ++$affectedRows;
                    }
                } elseif ($rs > 0) {
                    $affectedRows += $rs;
                } elseif (is_bool($rs)) { // catch error
                    if ($return) {
                        $return = $rs;
                    }
                }
            }
        } else {
            $return = $res;
        }

        if ($return) {
            return $affectedRows;
        }

        return $return;
    }

    /**
     * Find a category find its slug
     *
     * @access public
     *
     * @param string $slug
     *
     * @return array
     * @since  unknown
     */
    public function findBySlug($slug)
    {
        $slug = trim($slug);
        if ($slug != '') {
            if (isset($this->slugs[$slug])) {
                return $this->findByPrimaryKey($this->slugs[$slug]);
            }
            $slug = urlencode($slug);
            // $this->dao->where('b.s_slug', $slug);
            // end specific condition

            $results = $this->listWhere('b.s_slug = %s', $slug);
            if (count($results) > 0) {
                $this->slugs[$slug] = $results[0]['pk_i_id'];

                return $results[0];
            }
        }

        return array();
    }

    /**
     * Insert the description of a category
     *
     * @access public
     *
     * @param array $fields_description
     *
     * @return bool
     * @since  unknown
     */
    public function insertDescription($fields_description)
    {
        if (!empty($fields_description['s_name'])) {
            return $this->dao->insert(DB_TABLE_PREFIX . 't_category_description', $fields_description);
        }
    }

    /**
     * Inser a new category
     *
     * @access public
     *
     * @param array $fields
     * @param null  $aFieldsDescription
     *
     * @return mixed
     * @since  unknown
     */
    public function insert($fields, $aFieldsDescription = null)
    {
        $this->dao->insert($this->getTableName(), $fields);
        $category_id = $this->dao->insertedId();
        foreach ($aFieldsDescription as $k => $fieldsDescription) {
            $fieldsDescription['fk_i_category_id'] = $category_id;
            $fieldsDescription['fk_c_locale_code'] = $k;
            $slug                                  = osc_sanitizeString(osc_apply_filter(
                'slug',
                $fieldsDescription['s_name']
            ));
            $slug_tmp                              = $slug;
            $slug_unique                           = 1;
            while (true) {
                if (!$this->findBySlug($slug)) {
                    break;
                }

                $slug = $slug_tmp . '_' . $slug_unique;
                $slug_unique++;
            }
            $fieldsDescription['s_slug'] = $slug;
            $this->dao->insert(DB_TABLE_PREFIX . 't_category_description', $fieldsDescription);
        }

        return $category_id;
    }

    /**
     * Same as toRootTree but reverse the results
     *
     * @access public
     *
     * @param integer $category_id
     *
     * @return array
     * @since  unknown
     */
    public function hierarchy($category_id)
    {
        return array_reverse($this->toRootTree($category_id));
    }

    /**
     * Given a category, return the branch from the root to the category
     *
     * @access public
     *
     * @param null $cat
     *
     * @return array
     * @since  unknown
     */
    public function toRootTree($cat = null)
    {
        $tree = array();
        if ($cat != null) {
            $tree_b = array();
            if (is_numeric($cat)) {
                $cat = $this->findByPrimaryKey($cat);
            } else {
                $cat = $this->findBySlug($cat);
            }
            $tree[0] = $cat;
            while ($cat['fk_i_parent_id'] != null) {
                $cat = $this->findByPrimaryKey($cat['fk_i_parent_id']);
                array_unshift($tree, '');//$cat);
                $tree[0] = $cat;
            }
        }

        return $tree;
    }

    /**
     * Check if it's a root category
     *
     * @access public
     *
     * @param $categoryID
     *
     * @return boolean
     * @since  unknown
     */
    public function isRoot($categoryID)
    {
        // juanramon: specific condition
        // $this->dao->where( 'fk_i_parent_id IS NULL' );
        // $this->dao->where( 'pk_i_id', $categoryID );
        // end specific condition

        $results = $this->listWhere('a.fk_i_parent_id IS NULL AND a.pk_i_id = %d', (int)$categoryID);

        return count($results) > 0;
    }

    /**
     * returns the children of a given category
     *
     * @access public
     *
     * @param integer $categoryID
     *
     * @return array
     * @since  unknown
     */
    public function findSubcategoriesEnabled($categoryID)
    {
        // $this->dao->where( 'fk_i_parent_id', (int)($categoryID));
        // $this->dao->where( 'a.b_enabled', '1' );
        return $this->listWhere('a.fk_i_parent_id = %s AND a.b_enabled = 1', (int)$categoryID);
    }

    /**
     * Return a category's name given an id
     *
     * @access public
     *
     * @param int $categoryID primary key
     *
     * @return string
     * @since  3.1
     */
    public function findNameByPrimaryKey($categoryID)
    {
        if ($categoryID == null) {
            return false;
        }

        $category = array();

        if (array_key_exists($categoryID, $this->categories)) {
            $category = $this->categories[$categoryID];
        } else {
            $this->dao->select('s_name');
            $this->dao->from($this->getTablePrefix() . 't_category_description');
            $this->dao->where('fk_i_category_id', $categoryID);
            $result = $this->dao->get();

            if ($result == false) {
                return false;
            }

            $category = $result->row();
        }

        if (isset($category['s_name'])) {
            return $category['s_name'];
        }

        return __('Non-Existent Category');
    }

    /**
     * Return list of categories' name and id by locale
     *
     * @access public
     *
     * @param string $locale
     *
     * @return array|bool
     * @since  3.2.1
     */
    public function _findNameIDByLocale($locale = null)
    {
        if ($locale == null) {
            return false;
        }

        $this->dao->select('s_name, fk_i_category_id as pk_i_id');
        $this->dao->from($this->getTablePrefix() . 't_category_description');
        $this->dao->where('fk_c_locale_code', $locale);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Update categories' order
     *
     * @access public
     *
     * @param integer $pk_i_id
     * @param integer $order
     *
     * @return mixed false on fail, int of num. of affected rows
     * @since  unknown
     */
    public function updateOrder($pk_i_id, $order)
    {
        return $this->dao->update($this->tableName, array('i_position' => $order), array('pk_i_id' => $pk_i_id));
    }

    /**
     * Update categories' expiration
     *
     * @access public
     *
     * @param integer $pk_i_id
     * @param integer $expiration
     * @param boolean $updateSubcategories
     *
     * @return mixed false on fail, int of num. of affected rows
     * @since  unknown
     */
    public function updateExpiration($pk_i_id, $expiration, $updateSubcategories = false)
    {
        $itemManager = Item::newInstance();

        $this->dao->select('pk_i_id');
        $this->dao->from(DB_TABLE_PREFIX . 't_item');
        $this->dao->where(sprintf('fk_i_category_id = %d', $pk_i_id));
        $result = $this->dao->get();
        if ($result === false) {
            $items = array();
        } else {
            $items = $result->result();
        }
        foreach ($items as $item) {
            $itemManager->updateExpirationDate($item['pk_i_id'], $expiration);
        }
        $result = $this->dao->update(
            $this->tableName,
            array('i_expiration_days' => $expiration),
            array('pk_i_id' => $pk_i_id)
        );
        if ($updateSubcategories) {
            $subcategories = $this->findSubcategories($pk_i_id);
            foreach ($subcategories as $c) {
                $this->updateExpiration($c['pk_i_id'], $expiration, true);
            }
        }

        return $result;
    }

    /**
     * Update categories' price enabled
     *
     * @access public
     *
     * @param integer $pk_i_id
     * @param integer $enabled
     * @param boolean $updateSubcategories
     *
     * @return bool true on pass, false on fail
     * @since  unknown
     */
    public function updatePriceEnabled($pk_i_id, $enabled, $updateSubcategories = false)
    {
        $result =
            $this->dao->update($this->tableName, array('b_price_enabled' => $enabled), array('pk_i_id' => $pk_i_id));
        if ($updateSubcategories) {
            $subcategories = $this->findSubcategories($pk_i_id);
            foreach ($subcategories as $c) {
                $this->updatePriceEnabled($c['pk_i_id'], $enabled, true);
            }
        }

        return $result;
    }

    /**
     * update name of a category
     *
     * @access public
     *
     * @param integer $pk_i_id
     * @param string  $locale
     * @param string  $name
     *
     * @return mixed false on fail, int of num. of affected rows
     * @since  unknown
     */
    public function updateName($pk_i_id, $locale, $name)
    {
        return $this->dao->update(
            DB_TABLE_PREFIX . 't_category_description',
            array('s_name' => $name),
            array('fk_i_category_id' => $pk_i_id, 'fk_c_locale_code' => $locale)
        );
    }

    /**
     * Formats a value before being inserted in DB.
     *
     * @param $value
     *
     * @return string
     */
    public function formatValue($value)
    {
        if ($value === null) {
            return DB_CONST_NULL;
        }

        $value = trim($value);
        switch ($value) {
            case DB_FUNC_NOW:
            case DB_CONST_TRUE:
            case DB_CONST_FALSE:
            case DB_CONST_NULL:
                break;
            default:
                $value = '\'' . addslashes($value) . '\'';
                break;
        }

        return $value;
    }
}

/* file end: ./oc-includes/osclass/model/Category.php */
