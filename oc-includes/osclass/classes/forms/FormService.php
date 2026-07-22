<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\forms;

use Throwable;

/**
 * Class FormService
 *
 * Link-table operations for the forms builder — the many-to-many membership of
 * fields in forms (t_meta_group_fields). A form is a t_meta_group row; a field is
 * a t_meta_fields row; this service owns the join between them and the per-form
 * ordering.
 *
 * New code, so it uses the QueryBuilder facade (osc_db_table / osc_db_transaction)
 * rather than the legacy DAO. The legacy Field / FieldGroup models keep their own
 * resolution/CRUD; this class is the write path the drag-and-drop builder calls.
 *
 * @package mindstellar\forms
 */
final class FormService
{
    private string $linkTable;

    public function __construct()
    {
        $this->linkTable = DB_TABLE_PREFIX . 't_meta_group_fields';
    }

    /**
     * The ordered field ids belonging to a form.
     *
     * @param int $formId
     *
     * @return int[]
     */
    public function formFieldIds(int $formId): array
    {
        $rows = osc_db_table($this->linkTable)
            ->select('fk_i_field_id')
            ->where('fk_i_group_id', $formId)
            ->orderBy('i_position', 'ASC')
            ->get();

        return array_map(static fn ($r) => (int) $r['fk_i_field_id'], $rows);
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

        $link = $this->linkTable;
        try {
            osc_db_transaction(static function () use ($formId, $ordered, $link) {
                osc_db_table($link)->where('fk_i_group_id', $formId)->delete();
                $position = 0;
                foreach ($ordered as $fieldId) {
                    osc_db_table($link)->insert(array(
                        'fk_i_group_id' => $formId,
                        'fk_i_field_id' => $fieldId,
                        'i_position'    => $position,
                    ));
                    $position++;
                }
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
        $rows = osc_db_table($this->linkTable)
            ->select('fk_i_field_id')
            ->groupBy('fk_i_field_id')
            ->get();

        return array_map(static fn ($r) => (int) $r['fk_i_field_id'], $rows);
    }
}
