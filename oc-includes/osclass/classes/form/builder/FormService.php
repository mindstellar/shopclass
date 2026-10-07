<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\form\builder;

use mindstellar\database\Db;
use Throwable;

/**
 * Class FormService
 *
 * Link-table operations for the forms builder — the many-to-many membership of
 * fields in forms (t_meta_group_fields). A form is a t_meta_group row; a field is
 * a t_meta_fields row; this service owns the join between them and the per-form
 * ordering.
 *
 * The SQL lives in FormFieldLinkStore. The legacy Field / FieldGroup models keep their
 * own resolution/CRUD; this class is the write path the drag-and-drop builder calls.
 *
 * @package mindstellar\form\builder
 */
final class FormService
{
    /**
     * The ordered field ids belonging to a form.
     *
     * @param int $formId
     *
     * @return int[]
     */
    public function formFieldIds(int $formId): array
    {
        return FormFieldLinkStore::fieldIds($formId);
    }

    /**
     * Replace a form's field set with exactly $fieldIds, in the given order. Handles
     * add, remove and reorder in one call — the builder posts a form's whole field
     * list after every drag. Only THIS form's links are touched, so a field that
     * also lives in other forms keeps those memberships.
     *
     * @param int   $formId
     * @param int[] $fieldIds ordered
     *
     * @return bool
     */
    public function setFormFields(int $formId, array $fieldIds): bool
    {
        // de-dup while preserving order (a field can only sit once in a given form)
        $ordered = array();
        foreach ($fieldIds as $fid) {
            $fid = (int) $fid;
            if ($fid > 0 && !in_array($fid, $ordered, true)) {
                $ordered[] = $fid;
            }
        }

        try {
            Db::transaction(static function () use ($formId, $ordered) {
                FormFieldLinkStore::replace($formId, $ordered);
            });
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Ids of every field that belongs to at least one form. The builder uses this to
     * mark palette chips that are already placed (and to know which fields are the
     * legacy "loose" ones — those with no membership).
     *
     * @return int[]
     */
    public function placedFieldIds(): array
    {
        return FormFieldLinkStore::placedFieldIds();
    }

    /**
     * Move legacy "loose" fields into forms so the builder can manage them.
     *
     * A field created before the builder is assigned straight to categories
     * (t_meta_categories) and belongs to no form; it renders on those listings via
     * the loose branch of Field::findByCategoryItem(). This gathers every such field
     * into forms — one form per DISTINCT category set — and links them through the
     * link table, so each field keeps rendering on exactly the categories it had.
     *
     * The old t_meta_categories rows are left in place: once a field is in a form
     * they lie dormant (the resolver's NOT EXISTS guard skips them), so the move is
     * reversible — delete the form and the field is loose on its categories again.
     * Fields already in a form, and loose fields with no category, are skipped.
     *
     * @return array{forms:int, fields:int} how many forms were created and fields moved.
     */
    public function migrateLooseFields(): array
    {
        $fieldManager = \Field::getInstance();
        $groupManager = \FieldGroup::getInstance();

        $placed = array_fill_keys(array_map('intval', $this->placedFieldIds()), true);

        // Bucket loose fields by their exact category set. listAll() is ordered by
        // i_position, so each bucket preserves the fields' existing order.
        $buckets    = array();
        $bucketCats = array();
        foreach ($fieldManager->listAll() as $field) {
            $fid = (int) $field['pk_i_id'];
            if (isset($placed[$fid])) {
                continue;
            }
            $catIds = array_map('intval', $fieldManager->categories($fid));
            if (empty($catIds)) {
                continue;
            }
            sort($catIds);
            $key = implode(',', $catIds);
            if (!isset($buckets[$key])) {
                $buckets[$key]    = array();
                $bucketCats[$key] = $catIds;
            }
            $buckets[$key][] = $fid;
        }

        $formsCreated = 0;
        $fieldsMoved  = 0;
        $index        = 0;
        $multiple     = count($buckets) > 1;
        foreach ($buckets as $key => $fieldIds) {
            $index++;
            $name = $multiple
                ? sprintf(__('Imported fields %d'), $index)
                : __('Imported fields');
            $groupId = $groupManager->insertGroup($name);
            if ($groupId === false) {
                continue;
            }
            $groupManager->insertCategories($groupId, $bucketCats[$key]);
            if ($this->setFormFields((int) $groupId, $fieldIds)) {
                $formsCreated++;
                $fieldsMoved += count($fieldIds);
            }
        }

        return array('forms' => $formsCreated, 'fields' => $fieldsMoved);
    }

    /**
     * How many forms a field belongs to. Used to warn, when editing a field, that
     * the change is shared: a field definition is edited once and takes effect in
     * every form that placed it.
     *
     * @param int $fieldId
     *
     * @return int
     */
    public function formCountForField(int $fieldId): int
    {
        return FormFieldLinkStore::formCount($fieldId);
    }
}
