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
 * Model database for Field table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class Field extends DAO
{
    /**
     * It references to self object: Field.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var Field
     */
    private static $instance;

    /**
     * Current locale code
     *
     */
    public $currentLocaleCode;

    /**
     * Set data related to t_meta_fields table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_meta_fields');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 's_name', 'e_type', 'b_required', 'b_searchable', 's_slug', 's_options', 's_meta', 'i_position', 'fk_i_group_id'));
        if (defined('OC_ADMIN') && OC_ADMIN) {
            $this->currentLocaleCode = osc_current_admin_locale();
        } else {
            $this->currentLocaleCode = osc_current_user_locale();
        }
    }

    /**
     * It creates a new Field object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return Field
     * @since  unknown
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Find a field by its id.
     *
     * @access public
     *
     * @param int $id
     *
     * @return array Field information. If there's no information, return an empty array.
     * @since  unknown
     */
    public function findByPrimaryKey($id)
    {
        try {
            $field = osc_db_table($this->getTableName())->where('pk_i_id', $id)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($field === null) {
            return array();
        }

        return $this->extendField(osc_db_stringify_row($field));
    }

    /**
     * Extend s_meta json column to field array
     *
     * @param array $field
     *
     * @return array
     */
    private function extendField($field)
    {
        // if s_meta json column is not empty merge it with $field
        if (!empty($field['s_meta'])) {
            $aMeta = json_decode($field['s_meta'], true);
            if (is_array($aMeta)) {
                $field = array_merge($field, $aMeta);
            }
        }

        // check if $field['locale] is set and if it's not empty
        if (isset($field['locale'][$this->currentLocaleCode]['s_name']) && !empty($field['locale'][$this->currentLocaleCode]['s_name'])) {
            $field['s_name'] = $field['locale'][$this->currentLocaleCode]['s_name'];
        } elseif (isset($field['s_name'])) {
            $field['locale'][$this->currentLocaleCode]['s_name'] = $field['s_name'];
        }

        return $field;
    }

    /**
     * Delete a field and all information associated with it
     *
     * @access public
     *
     * @param int $id
     *
     * @return bool on success
     * @since  unknown
     */
    public function deleteByPrimaryKey($id)
    {
        // The first three statements have always thrown their return value away,
        // and the legacy layer reported a failure without raising, so a failure
        // on one never stopped the rest from running. Each therefore keeps its
        // own swallowed catch: one shared try would abort the cascade at the
        // first failure and leave orphaned rows behind.
        try {
            osc_db_table(sprintf('%st_item_meta', DB_TABLE_PREFIX))
                ->where('fk_i_field_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            // discarded, as before
        }
        try {
            osc_db_table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))
                ->where('fk_i_field_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            // discarded, as before
        }
        // remove form memberships too, or the t_meta_group_fields FK blocks the delete.
        try {
            osc_db_table(sprintf('%st_meta_group_fields', DB_TABLE_PREFIX))
                ->where('fk_i_field_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            // discarded, as before
        }

        // A null id used to build a comparison with no right-hand side, so the
        // delete failed and the method reported false. A bound null is valid SQL
        // that matches nothing and would report 0 instead — callers tell the two
        // apart, so the failure value is reproduced explicitly.
        if ($id === null) {
            return false;
        }

        try {
            return osc_db_table($this->getTableName())->where('pk_i_id', $id)->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Get all the rows from the table $tableName
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function listAll()
    {
        try {
            $fields = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->orderBy('i_position', 'ASC')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $extendedFields = array();
        foreach (osc_db_stringify_rows($fields) as $field) {
            $extendedFields[] = $this->extendField($field, $this->currentLocaleCode);
        }

        return $extendedFields;
    }

    /**
     * The category-inheritance path for a category: the category itself followed by
     * each ancestor up to the root, as an ordered list of ids (nearest first).
     *
     * Fields assigned to any category on this path apply to the given category, so
     * a field placed on "Vehicles" is inherited by "Vehicles › Cars" without being
     * re-assigned. The tree is shallow (usually two or three levels); the guard just
     * stops a corrupt parent cycle from looping forever.
     *
     * @param int $catId
     *
     * @return int[] category ids from the leaf up to the root; empty when invalid.
     */
    public function categoryPath($catId)
    {
        $catId = (int)$catId;
        if ($catId <= 0) {
            return array();
        }

        $path    = array();
        $current = $catId;
        $guard   = 0;
        while ($current > 0 && $guard < 100) {
            $path[]  = $current;
            // $current is (int)-cast and bound; the table name is the
            // DB_TABLE_PREFIX constant plus a literal suffix.
            try {
                $row = osc_db_select_one(
                    'SELECT fk_i_parent_id FROM ' . DB_TABLE_PREFIX . 't_category WHERE pk_i_id = ?',
                    array($current)
                );
            } catch (\mindstellar\database\DbException $e) {
                break;
            }
            $current = isset($row['fk_i_parent_id']) ? (int)$row['fk_i_parent_id'] : 0;
            // defensive: a parent chain that points back at a category already seen
            // would loop; break instead of spinning to the guard.
            if (in_array($current, $path, true)) {
                break;
            }
            $guard++;
        }

        return $path;
    }

    /**
     * The fields belonging to a group, ordered by position.
     *
     * @param int $groupId
     *
     * @return array
     */
    public function findByGroup($groupId)
    {
        // Membership + per-form order come from the link table (t_meta_group_fields),
        // which replaced the single fk_i_group_id column so a field can live in many
        // forms at different positions.
        $p   = DB_TABLE_PREFIX;
        // Aliased join the builder's identifier allowlist cannot express; every
        // identifier is a compile-time literal or the DB_TABLE_PREFIX constant,
        // and the only value is the group id, bound.
        $sql = 'SELECT mf.* FROM ' . $p . 't_meta_fields mf'
            . ' JOIN ' . $p . 't_meta_group_fields gf ON gf.fk_i_field_id = mf.pk_i_id'
            . ' WHERE gf.fk_i_group_id = ?'
            . ' ORDER BY gf.i_position ASC';
        try {
            $rows = osc_db_select($sql, array((int)$groupId));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        $extended = array();
        foreach (osc_db_stringify_rows($rows) as $field) {
            $extended[] = $this->extendField($field);
        }

        return $extended;
    }

    /**
     * Find the fields that apply to a category, honouring category inheritance: a
     * field assigned to any ancestor of $id resolves for $id too — either directly
     * (a loose field, via t_meta_categories) or through a group assigned to the
     * category (t_meta_group_categories). De-duplicated by field id and ordered.
     *
     * @access public
     *
     * @param int $id
     *
     * @return array Field information. If there's no information, return an empty array.
     * @since  unknown
     */
    public function findByCategory($id)
    {
        $path = $this->categoryPath($id);
        if (empty($path)) {
            return array();
        }
        // Every id in $path is an (int) produced by categoryPath(); each IN list
        // is generated placeholders bound to those ids. The two arms each carry
        // the whole path, so it is bound twice. Every identifier is a literal or
        // the DB_TABLE_PREFIX constant.
        $placeholders = implode(', ', array_fill(0, count($path), '?'));
        $p            = DB_TABLE_PREFIX;

        // Loose fields directly assigned (and in NO form), plus grouped fields whose
        // form is assigned to the category — form membership now comes from the link
        // table t_meta_group_fields (a field can be in several forms).
        $sql = 'SELECT query.* FROM ('
            . 'SELECT mf.*, 0 AS cf_group_position FROM ' . $p . 't_meta_fields mf, ' . $p . 't_meta_categories mc'
            . ' WHERE mc.fk_i_category_id IN (' . $placeholders . ') AND mf.pk_i_id = mc.fk_i_field_id'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $p . 't_meta_group_fields gfx WHERE gfx.fk_i_field_id = mf.pk_i_id)'
            . ' UNION '
            . 'SELECT mf.*, g.i_position AS cf_group_position FROM ' . $p . 't_meta_fields mf'
            . ' JOIN ' . $p . 't_meta_group_fields gf ON gf.fk_i_field_id = mf.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group g ON gf.fk_i_group_id = g.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = g.pk_i_id'
            . ' WHERE gc.fk_i_category_id IN (' . $placeholders . ')'
            . ') AS query GROUP BY query.pk_i_id ORDER BY query.cf_group_position ASC, query.i_position ASC';

        try {
            $fields = osc_db_select($sql, array_merge($path, $path));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $extendedFields = [];
        foreach (osc_db_stringify_rows($fields) as $field) {
            $extendedFields[] = $this->extendField($field, $this->currentLocaleCode);
        }

        return $extendedFields;
    }

    /**
     * Find a field by its name
     *
     * @access public
     *
     * @param mixed $ids
     *
     * @return array Fields' id
     * @since  unknown
     *
     */
    public function findIDSearchableByCategories($ids)
    {
        if (!is_array($ids)) {
            $ids = array($ids);
        }
        $catIds = array();
        $mCat   = Category::newInstance();
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $catIds[] = (int)$id;
            } else {
                $cat = $mCat->findBySlug($id);
                if (isset($cat['pk_i_id'])) {
                    $catIds[] = (int)$cat['pk_i_id'];
                }
            }
        }
        // expand each category to its inheritance path so a searchable field assigned
        // to a parent stays searchable in its descendants.
        $pathIds = array();
        foreach ($catIds as $catId) {
            foreach ($this->categoryPath($catId) as $pathId) {
                $pathIds[$pathId] = $pathId;
            }
        }
        if (empty($pathIds)) {
            return array();
        }
        // Path ids come from categoryPath() as (int)s; each IN list is generated
        // placeholders bound to them, once per arm. Identifiers are all literals
        // or the DB_TABLE_PREFIX constant.
        $pathValues   = array_values($pathIds);
        $placeholders = implode(', ', array_fill(0, count($pathValues), '?'));
        $p            = DB_TABLE_PREFIX;

        // Searchable loose fields directly assigned (and in no form), plus searchable
        // fields whose form is assigned, across the inheritance path — form membership
        // via the link table t_meta_group_fields.
        $sql = 'SELECT DISTINCT pk_i_id FROM ('
            . 'SELECT f.pk_i_id FROM ' . $p . 't_meta_fields f, ' . $p . 't_meta_categories c'
            . ' WHERE c.fk_i_category_id IN (' . $placeholders . ') AND f.pk_i_id = c.fk_i_field_id'
            . ' AND f.b_searchable = 1'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $p . 't_meta_group_fields gfx WHERE gfx.fk_i_field_id = f.pk_i_id)'
            . ' UNION '
            . 'SELECT f.pk_i_id FROM ' . $p . 't_meta_fields f'
            . ' JOIN ' . $p . 't_meta_group_fields gf ON gf.fk_i_field_id = f.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = gf.fk_i_group_id'
            . ' WHERE gc.fk_i_category_id IN (' . $placeholders . ') AND f.b_searchable = 1'
            . ') AS q';

        try {
            $rows = osc_db_select($sql, array_merge($pathValues, $pathValues));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $tmp = array();
        foreach (osc_db_stringify_rows($rows) as $t) {
            $tmp[] = $t['pk_i_id'];
        }

        return $tmp;
    }

    /**
     * Find fields from a category and an item
     *
     * @access public
     *
     * @param $catId
     * @param $itemId
     *
     * @return array Field information. If there's no information, return an empty array.
     * @since  unknown
     *
     */
    public function findByCategoryItem($catId, $itemId)
    {
        if (!is_numeric($catId) || (!is_numeric($itemId) && $itemId != null)) {
            return array();
        }

        // resolve fields down the category inheritance path (leaf + ancestors), so
        // a field assigned to a parent category renders on a child category's item.
        // Loose fields (via t_meta_categories) and grouped fields (via a group
        // assigned through t_meta_group_categories) are unioned; each row carries its
        // section (cf_group_name / cf_group_position) so the form can render groups
        // as sections while a flat theme loop still sees every field.
        $path = $this->categoryPath($catId);
        if (empty($path)) {
            return array();
        }
        $p      = DB_TABLE_PREFIX;
        $itemId = (int)$itemId;

        // Path ids (each an (int) from categoryPath) fill the two IN lists via
        // generated placeholders; the item id — already (int)-cast above — is the
        // final bound value on the LEFT JOIN. Params are in textual order: the
        // first arm's IN, the second arm's IN, then the join's item id. Every
        // identifier is a literal or the DB_TABLE_PREFIX constant.
        $placeholders = implode(', ', array_fill(0, count($path), '?'));

        // Loose fields carry their global position; grouped fields carry their
        // per-form position from the link table (cf_field_position), so ordering and
        // sectioning survive a field living in several forms.
        $sql = 'SELECT query.*, im.s_value as s_value, im.fk_i_item_id FROM ('
            . 'SELECT mf.*, NULL AS cf_group_name, 0 AS cf_group_position, mf.i_position AS cf_field_position'
            . ' FROM ' . $p . 't_meta_fields mf, ' . $p . 't_meta_categories mc'
            . ' WHERE mc.fk_i_category_id IN (' . $placeholders . ') AND mf.pk_i_id = mc.fk_i_field_id'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $p . 't_meta_group_fields gfx WHERE gfx.fk_i_field_id = mf.pk_i_id)'
            . ' UNION '
            . 'SELECT mf.*, g.s_name AS cf_group_name, g.i_position AS cf_group_position, gf.i_position AS cf_field_position'
            . ' FROM ' . $p . 't_meta_fields mf'
            . ' JOIN ' . $p . 't_meta_group_fields gf ON gf.fk_i_field_id = mf.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group g ON gf.fk_i_group_id = g.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = g.pk_i_id'
            . ' WHERE gc.fk_i_category_id IN (' . $placeholders . ')'
            . ') as query'
            . ' LEFT JOIN ' . $p . 't_item_meta im ON im.fk_i_field_id = query.pk_i_id AND im.fk_i_item_id = ?'
            . ' ORDER BY query.cf_group_position ASC, query.cf_field_position ASC';

        try {
            $result = osc_db_select($sql, array_merge($path, $path, array($itemId)));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        // Render-time dedup (FORMS.md §9.3): a field reused across several forms/
        // categories reaches the context multiple times; keep the FIRST occurrence
        // (lowest group then field position) so the item form never emits two
        // meta[id] inputs. This also collapses a field's multiple t_item_meta rows
        // (e.g. DATEINTERVAL from/to), matching the old GROUP BY pk_i_id behaviour.
        $extendedFields = array();
        $seen           = array();
        foreach (osc_db_stringify_rows($result) as $field) {
            $id = $field['pk_i_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $extendedFields[] = $this->extendField($field);
        }

        return $extendedFields;
    }

    /**
     * The meta fields an item has a stored value for (its detail-page values).
     *
     * Resolves purely from the values in t_item_meta: any field the item filled in
     * is returned, regardless of how it was assigned (loose, grouped, or inherited
     * from a parent category). The old t_meta_categories join required the field to
     * be assigned to the item's exact category, which hid the value of an inherited
     * or grouped field — the value was stored but never shown.
     *
     * @param $itemId
     *
     * @return array
     */
    public function findByItem($itemId)
    {
        if (!is_numeric($itemId)) {
            return array();
        }
        // Column aliases and an aliased join the builder cannot express, so the
        // query stays hand-written; every identifier is a literal or the
        // DB_TABLE_PREFIX constant and the only value — the item id, (int)-cast —
        // is bound.
        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT mf.pk_i_id as pk_i_id, im.s_value as s_value, mf.s_name as s_name,'
            . ' mf.e_type as e_type, im.s_multi as s_multi, mf.s_slug as s_slug, mf.s_meta as s_meta'
            . ' FROM ' . $p . 't_item_meta im'
            . ' INNER JOIN ' . $p . 't_meta_fields mf ON mf.pk_i_id = im.fk_i_field_id'
            . ' WHERE im.fk_i_item_id = ?'
            . ' ORDER BY mf.i_position ASC';

        try {
            $fields = osc_db_select($sql, array((int)$itemId));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        // extend fields
        $extendedFields = array();
        foreach (osc_db_stringify_rows($fields) as $field) {
            $extendedFields[] = $this->extendField($field);
        }

        return $extendedFields;
    }
    
    /**
     * Find a field by its name
     *
     * @access public
     *
     * @param string $name
     *
     * @return array Field information. If there's no information, return an empty array.
     * @since  unknown
     */
    public function findByName($name)
    {
        try {
            $field = osc_db_table($this->getTableName())->where('s_name', $name)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($field === null) {
            return array();
        }

        return $this->extendField(osc_db_stringify_row($field));
    }

    /**
     * Return an array with from and to date values
     * given a meta field id
     *
     * @param $item_id
     * @param $field_id
     *
     * @return array
     */
    public function getDateIntervalByPrimaryKey($item_id, $field_id)
    {
        if (!is_numeric($item_id) || !is_numeric($field_id)) {
            return array();
        }
        try {
            $aAux = osc_db_table(DB_TABLE_PREFIX . 't_item_meta')
                ->where('fk_i_field_id', $field_id)
                ->where('fk_i_item_id', $item_id)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $aInterval = array();
        foreach (osc_db_stringify_rows($aAux) as $v) {
            $aInterval[$v['s_multi']] = $v['s_value'];
        }

        return $aInterval;
    }

    /**
     * Gets which categories are associated with that field
     *
     * @access public
     *
     * @param string $id
     *
     * @return array
     * @since  unknown
     */
    public function categories($id)
    {
        try {
            $categories = osc_db_table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))
                ->select('fk_i_category_id')
                ->where('fk_i_field_id', $id)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        // Unlike the FieldGroup sibling this returns the raw string ids the legacy
        // result path produced, so the rows are stringified rather than cast.
        $cats = array();
        foreach (osc_db_stringify_rows($categories) as $v) {
            $cats[] = $v['fk_i_category_id'];
        }

        return $cats;
    }

    /**
     * Insert a new field
     *
     * @access public
     *
     * @param string $name
     * @param string $type
     * @param string $slug
     * @param bool   $required
     * @param array  $options
     * @param array  $categories
     *
     * @return bool
     * @since  unknown
     *
     */
    public function insertField($name, $type, $slug, $required, $options, $categories = null)
    {
        if ($slug == '') {
            $slug = preg_replace('|([-]+)|', '-', preg_replace('|[^a-z0-9_-]|', '-', strtolower($name)));
        }
        $slug_tmp = $slug;
        $slug_k   = 0;
        while (true) {
            if (!$this->findBySlug($slug)) {
                break;
            }

            $slug_k++;
            $slug = $slug_tmp . '_' . $slug_k;
        }
        // The field insert had no failure branch (its return was discarded); it
        // stays assumed-success and a DbException would propagate. The new id is
        // taken from the write for the category links below, and — because it is
        // the last statement when no categories are given — it also stays readable
        // through $model->dao->insertedId() on the shared handle, which an
        // external caller (CAdminAjax) reads straight after this returns.
        $id = osc_db_table($this->getTableName())->insert(array(
            's_name'     => $name,
            'e_type'     => $type,
            'b_required' => $required,
            's_slug'     => $slug,
            's_options'  => $options
        ));
        $return = true;
        foreach ((array)$categories as $c) {
            // A rejected link (duplicate, unknown category) was folded into the
            // return value while the rest were still written, so the catch stays
            // inside the loop.
            try {
                osc_db_table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))->insert(
                    array('fk_i_category_id' => $c, 'fk_i_field_id' => $id)
                );
            } catch (\mindstellar\database\DbException $e) {
                $return = false;
            }
        }

        return $return;
    }

    /**
     * Find a field by its name
     *
     * @access public
     *
     * @param string $slug
     *
     * @return array Field information. If there's no information, return an empty array.
     * @since  unknown
     */
    public function findBySlug($slug)
    {
        try {
            $field = osc_db_table($this->getTableName())->where('s_slug', $slug)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($field === null) {
            return array();
        }

        return $this->extendField(osc_db_stringify_row($field));
    }

    /**
     * Save the categories linked to a field
     *
     * @access public
     *
     * @param int   $id
     * @param array $categories
     *
     * @return bool
     * @since  unknown
     */
    public function insertCategories($id, $categories = null)
    {
        // An empty array is loosely equal to null, so `!= null` is false for it
        // too: an empty (or null) list returns false without writing anything —
        // unlike the FieldGroup sibling, which returns true for an empty array.
        if ($categories != null) {
            $return = true;
            foreach ($categories as $c) {
                // A rejected row (duplicate, unknown category) was folded into the
                // return value while the rest were still written, so the catch
                // stays inside the loop.
                try {
                    osc_db_table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))->insert(
                        array('fk_i_category_id' => $c, 'fk_i_field_id' => $id)
                    );
                } catch (\mindstellar\database\DbException $e) {
                    $return = false;
                }
            }

            return $return;
        }

        return false;
    }

    /**
     * Removes categories from a field
     *
     * @access public
     *
     * @param int $id
     *
     * @return bool on success
     * @since  unknown
     */
    public function cleanCategoriesFromField($id)
    {
        // A null id used to fail the query and report false, where a bound null
        // matches nothing and would report 0. Callers distinguish the two, so the
        // failure value is reproduced explicitly.
        if ($id === null) {
            return false;
        }

        try {
            return osc_db_table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))
                ->where('fk_i_field_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Update a field value
     *
     * @access public
     *
     * @param int          $itemId
     * @param int          $field
     * @param string|array $value
     *
     * @return bool|\DBRecordsetClass false on fail, int of num. of affected rows
     * @since  unknown
     */
    public function replace($itemId, $field, $value)
    {
        $table = sprintf('%st_item_meta', DB_TABLE_PREFIX);
        if (is_array($value)) {
            foreach ($value as $key => $v) {
                // Each per-key write has always had its return discarded, so a
                // failing one has never stopped the rest; the catch stays inside
                // the loop and the method still falls through to null.
                try {
                    osc_db_execute(
                        'REPLACE INTO ' . $table . ' (fk_i_item_id, fk_i_field_id, s_multi, s_value) VALUES (?, ?, ?, ?)',
                        array($itemId, $field, $key, $v)
                    );
                } catch (\mindstellar\database\DbException $e) {
                    // discarded, as before
                }
            }
        } else {
            // The legacy write returned bool true on success (a REPLACE is a write
            // query), not the affected-row count, so the boolean is preserved.
            try {
                osc_db_execute(
                    'REPLACE INTO ' . $table . ' (fk_i_item_id, fk_i_field_id, s_value) VALUES (?, ?, ?)',
                    array($itemId, $field, $value)
                );

                return true;
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }
        }
    }

    /**
     * Update JSON fieldName in s_meta json column
     *
     * @param int   $metaId
     * @param int   $fieldName
     * @param mixed $fieldValue
     *
     * @return bool
     */
    public function updateJsonMeta($metaId, $fieldName, $fieldValue)
    {
        // A null id made the read where-clause malformed, so the legacy read
        // failed and the method returned false. A bound null reads cleanly (zero
        // rows) and the method would fall through to the update and return 0
        // instead, which callers distinguish — so the failure value is kept.
        if ($metaId === null) {
            return false;
        }

        try {
            $row = osc_db_table($this->getTableName())->select('s_meta')->where('pk_i_id', $metaId)->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        // Zero rows behaves as the legacy empty row() did: json_decode of the
        // absent s_meta yields null, the key edit then rebuilds it, and the update
        // matches nothing and reports 0.
        $meta = json_decode((string)($row['s_meta'] ?? ''), true);
        // if $fieldValue is '', null
        if ($fieldValue === '' || $fieldValue === null) {
            unset($meta[$fieldName]);
        } else {
            $meta[$fieldName] = $fieldValue;
        }
        $meta = json_encode($meta);

        try {
            return osc_db_table($this->getTableName())->where('pk_i_id', $metaId)->update(array('s_meta' => $meta));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Get JSON fieldValue from s_meta json column
     *
     * @param int    $metaId
     * @param string $fieldName
     * @param array  $field
     *
     * @return mixed
     */
    public function getJsonMetaValue($fieldName, $field = null, $metaId = null)
    {
        // $field is not null
        if ($field !== null) {
            if (isset($field['s_meta']) && $field['s_meta'] !== '') {
                $meta = json_decode($field['s_meta'], true);

                return $meta[$fieldName] ?? false;
            }
        } else {
            if ($metaId === null) {
                return false;
            }
            try {
                $row = osc_db_table($this->getTableName())->select('s_meta')->where('pk_i_id', $metaId)->first();
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }
            $meta = json_decode((string)($row['s_meta'] ?? ''), true);

            return $meta[$fieldName] ?? false;
        }

        return false;
    }
}

/* file end: ./oc-includes/osclass/model/Field.php */
