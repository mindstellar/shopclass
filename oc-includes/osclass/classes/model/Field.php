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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('pk_i_id', $id);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        $field = $result->row();

        return $this->extendField($field);
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
        $this->dao->delete(sprintf('%st_item_meta', DB_TABLE_PREFIX), array('fk_i_field_id' => $id));
        $this->dao->delete(sprintf('%st_meta_categories', DB_TABLE_PREFIX), array('fk_i_field_id' => $id));

        return $this->dao->delete($this->getTableName(), array('pk_i_id' => $id));
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
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $this->dao->orderBy('i_position', 'ASC');
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        $fields         = $result->result();
        $extendedFields = array();
        foreach ($fields as $field) {
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
            $result  = $this->dao->query(sprintf(
                'SELECT fk_i_parent_id FROM %st_category WHERE pk_i_id = %d',
                DB_TABLE_PREFIX,
                $current
            ));
            if ($result === false) {
                break;
            }
            $row     = $result->row();
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_group_id', (int)$groupId);
        $this->dao->orderBy('i_position', 'ASC');
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }
        $extended = array();
        foreach ($result->result() as $field) {
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
        $inList = implode(',', array_map('intval', $path));
        $p      = DB_TABLE_PREFIX;

        // Loose fields directly assigned, plus grouped fields whose group is assigned.
        $sql = 'SELECT query.* FROM ('
            . 'SELECT mf.*, 0 AS cf_group_position FROM ' . $p . 't_meta_fields mf, ' . $p . 't_meta_categories mc'
            . ' WHERE mc.fk_i_category_id IN (' . $inList . ') AND mf.pk_i_id = mc.fk_i_field_id'
            . ' AND mf.fk_i_group_id IS NULL'
            . ' UNION '
            . 'SELECT mf.*, g.i_position AS cf_group_position FROM ' . $p . 't_meta_fields mf'
            . ' JOIN ' . $p . 't_meta_group g ON mf.fk_i_group_id = g.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = g.pk_i_id'
            . ' WHERE gc.fk_i_category_id IN (' . $inList . ')'
            . ') AS query GROUP BY query.pk_i_id ORDER BY query.cf_group_position ASC, query.i_position ASC';

        $result = $this->dao->query($sql);

        if ($result == false) {
            return array();
        }

        $fields         = $result->result();
        $extendedFields = [];
        foreach ($fields as $field) {
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
        $inList = implode(',', array_map('intval', $pathIds));
        $p      = DB_TABLE_PREFIX;

        // Searchable loose fields directly assigned, plus searchable grouped fields
        // whose group is assigned, across the inheritance path.
        $sql = 'SELECT DISTINCT pk_i_id FROM ('
            . 'SELECT f.pk_i_id FROM ' . $p . 't_meta_fields f, ' . $p . 't_meta_categories c'
            . ' WHERE c.fk_i_category_id IN (' . $inList . ') AND f.pk_i_id = c.fk_i_field_id'
            . ' AND f.b_searchable = 1 AND f.fk_i_group_id IS NULL'
            . ' UNION '
            . 'SELECT f.pk_i_id FROM ' . $p . 't_meta_fields f'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = f.fk_i_group_id'
            . ' WHERE gc.fk_i_category_id IN (' . $inList . ') AND f.b_searchable = 1'
            . ') AS q';

        $result = $this->dao->query($sql);

        if ($result == false) {
            return array();
        }

        $tmp = array();
        foreach ($result->result() as $t) {
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
        $inList = implode(',', array_map('intval', $path));
        $p      = DB_TABLE_PREFIX;
        $itemId = (int)$itemId;

        $sql = 'SELECT query.*, im.s_value as s_value, im.fk_i_item_id FROM ('
            . 'SELECT mf.*, NULL AS cf_group_name, 0 AS cf_group_position'
            . ' FROM ' . $p . 't_meta_fields mf, ' . $p . 't_meta_categories mc'
            . ' WHERE mc.fk_i_category_id IN (' . $inList . ') AND mf.pk_i_id = mc.fk_i_field_id'
            . ' AND mf.fk_i_group_id IS NULL'
            . ' UNION '
            . 'SELECT mf.*, g.s_name AS cf_group_name, g.i_position AS cf_group_position'
            . ' FROM ' . $p . 't_meta_fields mf'
            . ' JOIN ' . $p . 't_meta_group g ON mf.fk_i_group_id = g.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = g.pk_i_id'
            . ' WHERE gc.fk_i_category_id IN (' . $inList . ')'
            . ') as query'
            . ' LEFT JOIN ' . $p . 't_item_meta im ON im.fk_i_field_id = query.pk_i_id AND im.fk_i_item_id = ' . $itemId
            . ' GROUP BY query.pk_i_id ORDER BY query.cf_group_position ASC, query.i_position ASC';

        $result = $this->dao->query($sql);

        if ($result == false) {
            return array();
        }

        $fields = $result->result();
        // extend fields
        $extendedFields = array();
        foreach ($fields as $field) {
            $extendedFields[] = $this->extendField($field);
        }

        return $extendedFields;
    }

    /**
     * Find a field by item id with matching item category
     * @param $itemId
     * @return array
     */
    public function findByItem($itemId)
    {
        if (!is_numeric($itemId)) {
            return array();
        }
        $this->dao->select('mf.pk_i_id as pk_i_id, im.s_value as s_value, mf.s_name as s_name, mf.e_type as e_type, im.s_multi as s_multi, mf.s_slug as s_slug, mf.s_meta as s_meta');
        $this->dao->from(sprintf('%st_item i', DB_TABLE_PREFIX));
        $this->dao->join(sprintf('%st_item_meta im', DB_TABLE_PREFIX), 'i.pk_i_id = im.fk_i_item_id', 'LEFT');
        $this->dao->join(sprintf('%st_meta_fields mf', DB_TABLE_PREFIX), 'mf.pk_i_id = im.fk_i_field_id', 'LEFT');
        $this->dao->join(sprintf('%st_meta_categories mc', DB_TABLE_PREFIX), 'i.fk_i_category_id = mc.fk_i_category_id', 'LEFT');
        $this->dao->where('mf.pk_i_id = mc.fk_i_field_id');
        $this->dao->where('im.fk_i_item_id = ' . $itemId);
        $this->dao->orderBy('mf.i_position', 'ASC');

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        $fields = $result->result();
        // extend fields
        $extendedFields = array();
        foreach ($fields as $field) {
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_name', $name);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        $field = $result->row();

        return $this->extendField($field);
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
        $this->dao->select();
        $this->dao->from(DB_TABLE_PREFIX . 't_item_meta');
        $this->dao->where('fk_i_field_id', $field_id);
        $this->dao->where('fk_i_item_id', $item_id);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        $aAux      = $result->result();
        $aInterval = array();
        foreach ($aAux as $k => $v) {
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
        $this->dao->select('fk_i_category_id');
        $this->dao->from(sprintf('%st_meta_categories', DB_TABLE_PREFIX));
        $this->dao->where('fk_i_field_id', $id);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        $categories = $result->result();
        $cats       = array();
        foreach ($categories as $k => $v) {
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
        $this->dao->insert($this->getTableName(), array(
            's_name'     => $name,
            'e_type'     => $type,
            'b_required' => $required,
            's_slug'     => $slug,
            's_options'  => $options
        ));
        $id     = $this->dao->insertedId();
        $return = true;
        foreach ($categories as $c) {
            $result = $this->dao->insert(
                sprintf('%st_meta_categories', DB_TABLE_PREFIX),
                array('fk_i_category_id' => $c, 'fk_i_field_id' => $id)
            );
            if (!$result) {
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_slug', $slug);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }
        $field = $result->row();

        return $this->extendField($field);
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
        if ($categories != null) {
            $return = true;
            foreach ($categories as $c) {
                $result = $this->dao->insert(
                    sprintf('%st_meta_categories', DB_TABLE_PREFIX),
                    array('fk_i_category_id' => $c, 'fk_i_field_id' => $id)
                );
                if (!$result) {
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
        return $this->dao->delete(sprintf('%st_meta_categories', DB_TABLE_PREFIX), array('fk_i_field_id' => $id));
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
        if (is_array($value)) {
            foreach ($value as $key => $v) {
                $this->dao->replace(
                    sprintf('%st_item_meta', DB_TABLE_PREFIX),
                    array('fk_i_item_id' => $itemId, 'fk_i_field_id' => $field, 's_multi' => $key, 's_value' => $v)
                );
            }
        } else {
            return $this->dao->replace(
                sprintf('%st_item_meta', DB_TABLE_PREFIX),
                array('fk_i_item_id' => $itemId, 'fk_i_field_id' => $field, 's_value' => $value)
            );
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
        $this->dao->select('s_meta');
        $this->dao->from($this->getTableName());
        $this->dao->where('pk_i_id', $metaId);
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        $meta = $result->row();
        $meta = json_decode($meta['s_meta'], true);
        // if $fieldValue is '', null
        if ($fieldValue === '' || $fieldValue === null) {
            unset($meta[$fieldName]);
        } else {
            $meta[$fieldName] = $fieldValue;
        }
        $meta = json_encode($meta);

        return $this->dao->update($this->getTableName(), array('s_meta' => $meta), array('pk_i_id' => $metaId));
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
            $this->dao->select('s_meta');
            $this->dao->from($this->getTableName());
            $this->dao->where('pk_i_id', $metaId);
            $result = $this->dao->get();
            if ($result == false) {
                return false;
            }
            $meta = $result->row();
            $meta = json_decode($meta['s_meta'], true);

            return $meta[$fieldName] ?? false;
        }

        return false;
    }
}

/* file end: ./oc-includes/osclass/model/Field.php */
