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

use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\search\FacetIndex;

/**
 * Model database for Field table
 *
 * @package    Shopclass
 * @subpackage Model
 */
class Field extends DAO
{
    use \mindstellar\base\SharedInstance;

    /** Shared with FieldGroup: the fields of a category depend on both. */
    protected $cacheGroup = FieldGroup::CACHE_GROUP;

    /**
     * Current locale code
     *
     * @var string
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
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Find a field by its id.
     *
     * @param int $id
     *
     * @return array<string,mixed> Field information. If there's no information, return an empty array.
     */
    public function findByPrimaryKey($id)
    {
        try {
            $field = Db::table($this->getTableName())->where('pk_i_id', $id)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($field === null) {
            return array();
        }

        return $this->extendField(Db::stringifyRow($field));
    }

    /**
     * Extend s_meta json column to field array
     *
     * @param array<string,mixed> $field
     *
     * @return array<string,mixed>
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
     * @param int $id
     *
     * @return int|false Rows removed from t_meta_fields, or false on a null id or a failure
     */
    public function deleteByPrimaryKey($id)
    {
        // A null id reports false, the failure value; callers tell it apart from 0
        // rows deleted.
        if ($id === null) {
            return false;
        }

        osc_run_hook('before_delete_field', $id);

        // Every one of these tables now also carries ON DELETE CASCADE, so the
        // database would clear it anyway. They are still removed here because an
        // install whose foreign keys were never created -- or were dropped by a
        // migration that ran with FOREIGN_KEY_CHECKS off -- has nothing else to do
        // it, and a redundant delete is a no-op.
        //
        // t_form_submission_value belongs in this list: it holds every value ever
        // submitted for the field through a form, and leaving it out made deleting
        // a field that had been submitted fail outright on the foreign key.
        $dependents = array('t_item_meta', 't_meta_categories', 't_meta_group_fields', 't_form_submission_value');

        try {
            $deleted = Db::transaction(function () use ($id, $dependents) {
                foreach ($dependents as $table) {
                    Db::table(DB_TABLE_PREFIX . $table)->where('fk_i_field_id', $id)->delete();
                    if ($table === 't_item_meta') {
                        FacetIndex::dropField((int) $id);
                    }
                }

                return Db::table($this->getTableName())->where('pk_i_id', $id)->delete();
            });
        } catch (\Throwable $e) {
            // The whole cascade is rolled back, so the field keeps its values and
            // its category and form links rather than surviving as an empty shell.
            // Throwable, not DbException: failing to even open the transaction
            // raises a RuntimeException, and callers expect false either way.
            return false;
        }
        $this->cacheChanged();

        osc_run_hook('after_delete_field', $id);

        return $deleted;
    }

    /**
     * Get all the rows from the table $tableName
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAll()
    {
        try {
            $fields = Db::table($this->getTableName())
                ->select(...$this->getFields())
                ->orderBy('i_position', 'ASC')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $extendedFields = array();
        foreach (Db::stringifyRows($fields) as $field) {
            $extendedFields[]    = $this->extendField($field);
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
        return \mindstellar\fields\FieldQuery::categoryPath((int) $catId);
    }

    /**
     * The fields belonging to a group, ordered by position.
     *
     * @param int $groupId
     *
     * @return array<int,array<string,mixed>> Ordered by their position in the form
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
            $rows = Db::select($sql, array((int)$groupId));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        $extended = array();
        foreach (Db::stringifyRows($rows) as $field) {
            $extended[] = $this->extendField($field);
        }

        return $extended;
    }

    /**
     * The fields of several groups in one query, each group's ordered by position.
     *
     * @param int[] $groupIds
     *
     * @return array<int,array<int,array<string,mixed>>> group id => fields; a group with none is absent
     */
    public function findByGroups(array $groupIds)
    {
        try {
            $byGroup = \mindstellar\fields\FieldQuery::groupFields(array_map('intval', $groupIds));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        foreach ($byGroup as $groupId => $fields) {
            $byGroup[$groupId] = array_map(fn (array $field): array => $this->extendField($field), $fields);
        }

        return $byGroup;
    }

    /**
     * Find the fields that apply to a category, honouring category inheritance: a
     * field assigned to any ancestor of $id resolves for $id too — either directly
     * (a loose field, via t_meta_categories) or through a group assigned to the
     * category (t_meta_group_categories). De-duplicated by field id and ordered.
     *
     * @param int $id
     *
     * @return array<int,array<string,mixed>> Field information. If there's no information, return an empty array.
     */
    public function findByCategory($id)
    {
        try {
            $fields = \mindstellar\fields\FieldQuery::forCategory((int) $id);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return array_map(fn (array $field): array => $this->extendField($field), $fields);
    }

    /**
     * The ids of every searchable field that applies to the given categories.
     *
     * @param array<int,int|string>|int|string $ids Category ids or slugs
     *
     * @return array<int,string> Fields' id
     */
    public function findIDSearchableByCategories($ids)
    {
        if (!is_array($ids)) {
            $ids = array($ids);
        }
        $catIds = array();
        $mCat   = Category::getInstance();
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
            $rows = Db::select($sql, array_merge($pathValues, $pathValues));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $tmp = array();
        foreach (Db::stringifyRows($rows) as $t) {
            $tmp[] = $t['pk_i_id'];
        }

        return $tmp;
    }

    /**
     * Find fields from a category and an item
     *
     * @param int $catId
     * @param int $itemId
     *
     * @return array<int,array<string,mixed>> Field information. If there's no information, return an empty array.
     */
    public function findByCategoryItem($catId, $itemId)
    {
        if (!is_numeric($catId) || (!is_numeric($itemId) && $itemId != null)) {
            return array();
        }

        // The search order: one query decides it for both. A field with several stored rows
        // (DATEINTERVAL from/to) shows its first value, as before.
        try {
            $fields = \mindstellar\fields\FieldQuery::forCategory((int) $catId);
            $values = array();
            if ($fields !== array() && (int) $itemId > 0) {
                $rows = Db::select(
                    'SELECT fk_i_field_id, s_value FROM ' . DB_TABLE_PREFIX . 't_item_meta WHERE fk_i_item_id = ?',
                    array((int) $itemId)
                );
                foreach (Db::stringifyRows($rows) as $row) {
                    $values[$row['fk_i_field_id']] ??= $row;
                }
            }
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $extendedFields = array();
        foreach ($fields as $field) {
            $value               = $values[$field['pk_i_id']] ?? null;
            $field['s_value']      = $value['s_value'] ?? null;
            $field['fk_i_item_id'] = $value === null ? null : (string) (int) $itemId;
            $extendedFields[]    = $this->extendField($field);
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
     * @param int $itemId
     *
     * @return array<int,array<string,mixed>> Empty when the id is not numeric or the query failed
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
            $fields = Db::select($sql, array((int)$itemId));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        // extend fields
        $extendedFields = array();
        foreach (Db::stringifyRows($fields) as $field) {
            $extendedFields[]    = $this->extendField($field);
        }

        return $extendedFields;
    }

    /**
     * Find a field by its name
     *
     * @param string $name
     *
     * @return array<string,mixed> Field information. If there's no information, return an empty array.
     */
    public function findByName($name)
    {
        try {
            $field = Db::table($this->getTableName())->where('s_name', $name)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($field === null) {
            return array();
        }

        return $this->extendField(Db::stringifyRow($field));
    }

    /**
     * Return an array with from and to date values
     * given a meta field id
     *
     * @param int $item_id
     * @param int $field_id
     *
     * @return array<string,string|null> Keyed by s_multi ('from'/'to'); empty when there is no value
     */
    public function getDateIntervalByPrimaryKey($item_id, $field_id)
    {
        if (!is_numeric($item_id) || !is_numeric($field_id)) {
            return array();
        }
        try {
            $aAux = Db::table(DB_TABLE_PREFIX . 't_item_meta')
                ->where('fk_i_field_id', $field_id)
                ->where('fk_i_item_id', $item_id)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $aInterval = array();
        foreach (Db::stringifyRows($aAux) as $v) {
            $aInterval[$v['s_multi']] = $v['s_value'];
        }

        return $aInterval;
    }

    /**
     * Gets which categories are associated with that field
     *
     * @param int $id
     *
     * @return array<int,string> Category ids, as strings
     */
    public function categories($id)
    {
        try {
            $categories = Db::table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))
                ->select('fk_i_category_id')
                ->where('fk_i_field_id', $id)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        // Unlike the FieldGroup sibling this returns the raw string ids the legacy
        // result path produced, so the rows are stringified rather than cast.
        $cats = array();
        foreach (Db::stringifyRows($categories) as $v) {
            $cats[] = $v['fk_i_category_id'];
        }

        return $cats;
    }

    /**
     * Insert a new field
     *
     * @param string                     $name
     * @param string                     $type
     * @param string                     $slug       Derived from $name when empty
     * @param bool|int                   $required
     * @param string                     $options    Serialised option list, stored verbatim
     * @param array<int,int|string>|null $categories
     *
     * @return int The new field id, or 0 when a category link failed
     * @throws \mindstellar\database\DbException when the field row itself cannot be written
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
        // The new id comes from the write itself (osc_db_table()->insert returns it) and is
        // returned below, so the caller uses that id instead of a $model->dao->insertedId()
        // read of the shared connection after this returns — which the category-link inserts
        // below would have overwritten, and which any statement on the shared handle can zero.
        $id = Db::table($this->getTableName())->insert(array(
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
                Db::table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))->insert(
                    array('fk_i_category_id' => $c, 'fk_i_field_id' => $id)
                );
            } catch (\mindstellar\database\DbException $e) {
                $return = false;
            }
        }

        $this->cacheChanged();

        // Return the new field id on success (0 if a category link failed), so the caller
        // never has to read it back off the shared connection.
        return $return ? (int) $id : 0;
    }

    /**
     * Find a field by its slug
     *
     * @param string $slug
     *
     * @return array<string,mixed> Field information. If there's no information, return an empty array.
     */
    public function findBySlug($slug)
    {
        try {
            $field = Db::table($this->getTableName())->where('s_slug', $slug)->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
        if ($field === null) {
            return array();
        }

        return $this->extendField(Db::stringifyRow($field));
    }

    /**
     * Save the categories linked to a field
     *
     * @param int                        $id
     * @param array<int,int|string>|null $categories
     *
     * @return bool False for an empty list, or when any row was rejected
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
                    Db::table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))->insert(
                        array('fk_i_category_id' => $c, 'fk_i_field_id' => $id)
                    );
                } catch (\mindstellar\database\DbException $e) {
                    $return = false;
                }
            }
            $this->cacheChanged();

            return $return;
        }

        return false;
    }

    /**
     * Removes categories from a field
     *
     * @param int $id
     *
     * @return int|false Rows removed, or false on a null id or a query failure
     */
    public function cleanCategoriesFromField($id)
    {
        // A null id reports false, the failure value; callers tell it apart from 0
        // rows removed.
        if ($id === null) {
            return false;
        }

        try {
            return Db::table(sprintf('%st_meta_categories', DB_TABLE_PREFIX))
                ->where('fk_i_field_id', $id)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        } finally {
            $this->cacheChanged();
        }
    }

    /**
     * Update a field value
     *
     * @param int                             $itemId
     * @param int                             $field
     * @param string|array<string,string|null> $value A map writes one row per s_multi key
     *
     * @return bool|null True/false for a scalar value; null once a multi-value map is written
     */
    public function replace($itemId, $field, $value)
    {
        $table = sprintf('%st_item_meta', DB_TABLE_PREFIX);
        try {
            // Inside a listing save this joins that transaction; on its own it retries a deadlock once.
            return Db::retryOnce(static fn () => Db::transaction(static function () use ($table, $itemId, $field, $value) {
                if (is_array($value)) {
                    // A failing key has never stopped the rest, and the method returns null.
                    foreach ($value as $key => $v) {
                        try {
                            Db::execute(
                                'REPLACE INTO ' . $table . ' (fk_i_item_id, fk_i_field_id, s_multi, s_value) VALUES (?, ?, ?, ?)',
                                array($itemId, $field, $key, $v)
                            );
                        } catch (DbException $e) {
                            Db::assertIntact();
                        }
                    }
                } else {
                    try {
                        Db::execute(
                            'REPLACE INTO ' . $table . ' (fk_i_item_id, fk_i_field_id, s_value) VALUES (?, ?, ?)',
                            array($itemId, $field, $value)
                        );
                    } catch (DbException $e) {
                        Db::assertIntact();

                        return false;
                    }
                }
                FacetIndex::rewriteField((int) $itemId, (int) $field, $value);

                return is_array($value) ? null : true;
            }));
        } catch (DbException $e) {
            return is_array($value) ? null : false;
        }
    }

    /**
     * Update JSON fieldName in s_meta json column
     *
     * @param int    $metaId
     * @param string $fieldName
     * @param mixed  $fieldValue An empty string or null removes the key
     *
     * @return int|false Rows updated, or false on a null id or a query failure
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
            $row = Db::table($this->getTableName())->select('s_meta')->where('pk_i_id', $metaId)->first();
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
            return Db::table($this->getTableName())->where('pk_i_id', $metaId)->update(array('s_meta' => $meta));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        } finally {
            $this->cacheChanged();
        }
    }

    /**
     * Get JSON fieldValue from s_meta json column
     *
     * @param string                   $fieldName
     * @param array<string,mixed>|null $field   An already-loaded field row, read instead of $metaId
     * @param int|null                 $metaId  Field id to read when $field is null
     *
     * @return mixed False when the key is absent, unreadable, or both sources are null
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
                $row = Db::table($this->getTableName())->select('s_meta')->where('pk_i_id', $metaId)->first();
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
