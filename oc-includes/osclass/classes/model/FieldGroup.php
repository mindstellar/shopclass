<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Model database for the field-group table (t_meta_group).
 *
 * A field group is a named, ordered, reusable set of custom fields attached to
 * categories as a unit (t_meta_group_categories) and rendered as a form section
 * on the item form. Category assignment inherits down the tree exactly like loose
 * fields — the ancestry walk is shared with Field::categoryPath().
 *
 * @package    Shopclass
 * @subpackage Model
 */
class FieldGroup extends DAO
{
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_meta_group');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 's_name', 's_slug', 'i_position', 's_meta'));
    }

    /**
     * @return FieldGroup
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * @param int $id
     *
     * @return array
     */
    public function findByPrimaryKey($id)
    {
        try {
            $row = osc_db_table($this->getTableName())->where('pk_i_id', $id)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * @param string $slug
     *
     * @return array
     */
    public function findBySlug($slug)
    {
        try {
            $row = osc_db_table($this->getTableName())->where('s_slug', $slug)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * All groups, ordered by position.
     *
     * @return array
     */
    public function listAll()
    {
        try {
            $rows = osc_db_table($this->getTableName())->orderBy('i_position', 'ASC')->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Insert a new group, deriving/uniquifying a slug from the name when needed.
     *
     * @param string $name
     * @param string $slug
     * @param int    $position
     *
     * @return int|false the new group id, or false on failure.
     */
    public function insertGroup($name, $slug = '', $position = 0)
    {
        $slug = $this->uniqueSlug($slug !== '' ? $slug : $name);
        try {
            $id = osc_db_table($this->getTableName())->insert(array(
                's_name'     => $name,
                's_slug'     => $slug,
                'i_position' => (int)$position,
            ));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return $id;
    }

    /**
     * Build a slug unique across groups, appending _N on collision.
     *
     * @param string $base
     *
     * @return string
     */
    public function uniqueSlug($base)
    {
        $slug = preg_replace('|([-]+)|', '-', preg_replace('|[^a-z0-9_-]|', '-', strtolower($base)));
        if ($slug === '') {
            $slug = 'group';
        }
        $slugTmp = $slug;
        $k       = 0;
        while ($this->findBySlug($slug)) {
            $k++;
            $slug = $slugTmp . '_' . $k;
        }

        return $slug;
    }

    /**
     * Delete a form: unlink its category assignments and its field-membership links
     * (a field with no remaining links becomes loose again), then remove the row.
     * The legacy fk_i_group_id column is cleared too so the deprecated column can't
     * point at a deleted form during the rollback window.
     *
     * @param int $id
     *
     * @return bool
     */
    public function deleteByPrimaryKey($id)
    {
        // The first three statements have always had their return value thrown
        // away, and the query layer they used reported failure without raising,
        // so a failure on one of them never stopped the rest from running. Each
        // therefore keeps its own swallowed catch: one shared try would abort the
        // cascade at the first failure and leave orphaned links behind.
        try {
            osc_db_table(sprintf('%st_meta_group_categories', DB_TABLE_PREFIX))
                ->where('fk_i_group_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            // discarded, as before
        }
        try {
            osc_db_table(sprintf('%st_meta_group_fields', DB_TABLE_PREFIX))
                ->where('fk_i_group_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            // discarded, as before
        }
        try {
            osc_db_table(sprintf('%st_meta_fields', DB_TABLE_PREFIX))
                ->where('fk_i_group_id', $id)
                ->update(array('fk_i_group_id' => null));
        } catch (\mindstellar\database\DbException $e) {
            // discarded, as before
        }

        // A null id used to build a comparison with no right-hand side, so the
        // delete failed and the method reported false. A bound null is valid SQL
        // that simply matches nothing and would report 0 instead — callers tell
        // those two apart, so the failure value is reproduced explicitly.
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
     * Set or clear a key in a form's s_meta JSON (layout flags such as 'placeable').
     * An empty/null value removes the key, keeping s_meta compact. Mirrors
     * Field::updateJsonMeta.
     *
     * @param int    $id
     * @param string $key
     * @param mixed  $value
     *
     * @return bool
     */
    public function setMeta($id, $key, $value)
    {
        $row  = $this->findByPrimaryKey($id);
        $meta = (isset($row['s_meta']) && $row['s_meta'] !== '') ? json_decode($row['s_meta'], true) : array();
        if (!is_array($meta)) {
            $meta = array();
        }
        if ($value === '' || $value === null) {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }

        try {
            return osc_db_table($this->getTableName())
                ->where('pk_i_id', (int)$id)
                ->update(array('s_meta' => empty($meta) ? null : json_encode($meta)));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Set a field's membership to a single form via the link table (t_meta_group_fields),
     * appending it at the end of that form. Used by the legacy single-group field editor
     * during the transition to the multi-form builder; the builder manages links directly.
     * $groupId of 0 removes the field from every form (making it loose again).
     *
     * @param int $fieldId
     * @param int $groupId
     *
     * @return void
     */
    public function setFieldSingleGroup($fieldId, $groupId)
    {
        $link = DB_TABLE_PREFIX . 't_meta_group_fields';
        // Both statements have always discarded their result: the unlink is not
        // conditional on the insert succeeding, and an insert the foreign key
        // rejects has always left the field detached without raising. Each keeps
        // its own swallowed catch so that stays true.
        try {
            osc_db_table($link)->where('fk_i_field_id', (int)$fieldId)->delete();
        } catch (\mindstellar\database\DbException $e) {
            // discarded, as before
        }
        if ((int)$groupId > 0) {
            try {
                // $link is built from the DB_TABLE_PREFIX constant and a literal
                // suffix; the only caller-supplied value is bound.
                $pos = (int)osc_db_scalar(
                    'SELECT COALESCE(MAX(i_position), -1) + 1 AS pos FROM ' . $link
                    . ' WHERE fk_i_group_id = ?',
                    array((int)$groupId)
                );
            } catch (\mindstellar\database\DbException $e) {
                $pos = 0;
            }
            try {
                osc_db_table($link)->insert(array(
                    'fk_i_group_id' => (int)$groupId,
                    'fk_i_field_id' => (int)$fieldId,
                    'i_position'    => $pos,
                ));
            } catch (\mindstellar\database\DbException $e) {
                // discarded, as before
            }
        }
    }

    /**
     * Category ids directly assigned to a group.
     *
     * @param int $id
     *
     * @return int[]
     */
    public function categories($id)
    {
        try {
            $rows = osc_db_table(sprintf('%st_meta_group_categories', DB_TABLE_PREFIX))
                ->select('fk_i_category_id')
                ->where('fk_i_group_id', $id)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        // No stringify pass here: this method has always returned native ints,
        // unlike every other read on this model.
        $cats = array();
        foreach ($rows as $row) {
            $cats[] = (int)$row['fk_i_category_id'];
        }

        return $cats;
    }

    /**
     * Save the categories a group applies to.
     *
     * @param int   $id
     * @param array $categories
     *
     * @return bool
     */
    public function insertCategories($id, $categories = null)
    {
        if (!is_array($categories)) {
            return false;
        }
        $return = true;
        foreach ($categories as $c) {
            // A rejected row (duplicate assignment, unknown category) has always
            // been folded into the return value while the remaining ids were
            // still written, so the catch stays inside the loop.
            try {
                osc_db_table(sprintf('%st_meta_group_categories', DB_TABLE_PREFIX))->insert(
                    array('fk_i_group_id' => $id, 'fk_i_category_id' => (int)$c)
                );
            } catch (\mindstellar\database\DbException $e) {
                $return = false;
            }
        }

        return $return;
    }

    /**
     * Remove all category assignments from a group.
     *
     * @param int $id
     *
     * @return bool
     */
    public function cleanCategoriesFromGroup($id)
    {
        // Same divergence as deleteByPrimaryKey: a null id used to fail the query
        // and report false, where a bound null matches nothing and would report 0.
        if ($id === null) {
            return false;
        }

        try {
            return osc_db_table(sprintf('%st_meta_group_categories', DB_TABLE_PREFIX))
                ->where('fk_i_group_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * The groups that apply to a category, honouring category inheritance, each with
     * its ordered 'fields'. A group with no member fields is skipped so an empty
     * section never renders.
     *
     * @param int $categoryId
     *
     * @return array
     */
    public function findByCategory($categoryId)
    {
        $path = Field::newInstance()->categoryPath($categoryId);
        if (empty($path)) {
            return array();
        }

        // Aliased cross join: the builder's identifier allowlist cannot express
        // `g`/`gc` or the column-to-column join condition, so the query stays
        // hand-written. Every identifier below is a compile-time literal or the
        // DB_TABLE_PREFIX constant; the only values are the ancestry ids, one
        // bound placeholder each.
        $prefix       = DB_TABLE_PREFIX;
        $placeholders = implode(', ', array_fill(0, count($path), '?'));
        $sql          = 'SELECT g.* FROM ' . $prefix . 't_meta_group g'
            . ' CROSS JOIN ' . $prefix . 't_meta_group_categories gc'
            . ' WHERE gc.fk_i_category_id IN (' . $placeholders . ')'
            . ' AND g.pk_i_id = gc.fk_i_group_id'
            . ' GROUP BY g.pk_i_id'
            . ' ORDER BY g.i_position ASC';

        try {
            $rows = osc_db_select($sql, $path);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $groups = array();
        foreach (osc_db_stringify_rows($rows) as $group) {
            $fields = Field::newInstance()->findByGroup($group['pk_i_id']);
            if (empty($fields)) {
                continue;
            }
            $group['fields'] = $fields;
            $groups[]        = $group;
        }

        return $groups;
    }
}

/* file end: ./oc-includes/osclass/classes/model/FieldGroup.php */
