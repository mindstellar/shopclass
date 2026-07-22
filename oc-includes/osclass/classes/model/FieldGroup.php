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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('pk_i_id', $id);
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        return $result->row();
    }

    /**
     * @param string $slug
     *
     * @return array
     */
    public function findBySlug($slug)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_slug', $slug);
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        return $result->row();
    }

    /**
     * All groups, ordered by position.
     *
     * @return array
     */
    public function listAll()
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->orderBy('i_position', 'ASC');
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        return $result->result();
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
        $ok   = $this->dao->insert($this->getTableName(), array(
            's_name'     => $name,
            's_slug'     => $slug,
            'i_position' => (int)$position,
        ));
        if (!$ok) {
            return false;
        }

        return $this->dao->insertedId();
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
        $this->dao->delete(sprintf('%st_meta_group_categories', DB_TABLE_PREFIX), array('fk_i_group_id' => $id));
        $this->dao->delete(sprintf('%st_meta_group_fields', DB_TABLE_PREFIX), array('fk_i_group_id' => $id));
        $this->dao->update(
            sprintf('%st_meta_fields', DB_TABLE_PREFIX),
            array('fk_i_group_id' => null),
            array('fk_i_group_id' => $id)
        );

        return $this->dao->delete($this->getTableName(), array('pk_i_id' => $id));
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
        $this->dao->delete($link, array('fk_i_field_id' => (int)$fieldId));
        if ((int)$groupId > 0) {
            $result = $this->dao->query(
                'SELECT COALESCE(MAX(i_position), -1) + 1 AS pos FROM ' . $link
                . ' WHERE fk_i_group_id = ' . (int)$groupId
            );
            $pos = $result ? (int)$result->row()['pos'] : 0;
            $this->dao->insert($link, array(
                'fk_i_group_id' => (int)$groupId,
                'fk_i_field_id' => (int)$fieldId,
                'i_position'    => $pos,
            ));
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
        $this->dao->select('fk_i_category_id');
        $this->dao->from(sprintf('%st_meta_group_categories', DB_TABLE_PREFIX));
        $this->dao->where('fk_i_group_id', $id);
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }
        $cats = array();
        foreach ($result->result() as $row) {
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
            $ok = $this->dao->insert(
                sprintf('%st_meta_group_categories', DB_TABLE_PREFIX),
                array('fk_i_group_id' => $id, 'fk_i_category_id' => (int)$c)
            );
            if (!$ok) {
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
        return $this->dao->delete(
            sprintf('%st_meta_group_categories', DB_TABLE_PREFIX),
            array('fk_i_group_id' => $id)
        );
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

        $this->dao->select('g.*');
        $this->dao->from(sprintf('%st_meta_group g, %st_meta_group_categories gc', DB_TABLE_PREFIX, DB_TABLE_PREFIX));
        $this->dao->whereIn('gc.fk_i_category_id', $path);
        $this->dao->where('g.pk_i_id = gc.fk_i_group_id');
        $this->dao->groupBy('g.pk_i_id');
        $this->dao->orderBy('g.i_position', 'ASC');
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        $groups = array();
        foreach ($result->result() as $group) {
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
