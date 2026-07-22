<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\model;

use mindstellar\database\QueryBuilder;
use Throwable;

/**
 * Model for form submissions — the polymorphic sink used when a form is placed off
 * an item (a contact/survey/lead form on a page or in a layout). t_item_meta stays
 * the item sink; this stores everything else.
 *
 * A submission (t_form_submission) records which form, which placement context
 * (s_context_type + i_context_id, mirroring t_resource's polymorphic owner), the
 * submitter/ip/time and a triage status. Its values (t_form_submission_value) share
 * t_item_meta's (field_id, s_value, s_multi) shape so field types serialize the
 * same to both sinks.
 *
 * Parameterized osc_db_* / QueryBuilder throughout; does not extend the legacy DAO.
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      5.3.0
 */
class FormSubmission
{
    private const TABLE       = 't_form_submission';
    private const VALUE_TABLE = 't_form_submission_value';

    /** Triage statuses. */
    public const STATUSES = array('new', 'read', 'spam', 'archived');

    /** @var FormSubmission */
    private static $instance;

    public static function newInstance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    private function table(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . self::TABLE);
    }

    private function valueTable(): QueryBuilder
    {
        return osc_db_table(DB_TABLE_PREFIX . self::VALUE_TABLE);
    }

    /**
     * Store a submission and its field values in one transaction.
     *
     * @param int         $formId
     * @param string      $contextType e.g. 'page' | 'widget' | plugin type
     * @param int         $contextId
     * @param int|null    $userId      logged-in submitter, or null
     * @param string|null $ip
     * @param array       $values      fieldId => scalar, or fieldId => [multiKey => scalar]
     *
     * @return int|false the new submission id, or false on failure
     */
    public function create(int $formId, string $contextType, int $contextId, ?int $userId, ?string $ip, array $values)
    {
        try {
            $submissionId = 0;
            osc_db_transaction(function () use (&$submissionId, $formId, $contextType, $contextId, $userId, $ip, $values) {
                $submissionId = osc_db_table(DB_TABLE_PREFIX . self::TABLE)->insert(array(
                    'fk_i_group_id'  => $formId,
                    's_context_type' => $contextType,
                    'i_context_id'   => $contextId,
                    'fk_i_user_id'   => $userId,
                    's_ip'           => $ip,
                    's_status'       => 'new',
                    'dt_created'     => date('Y-m-d H:i:s'),
                ));

                foreach ($values as $fieldId => $value) {
                    if (is_array($value)) {
                        foreach ($value as $multi => $v) {
                            osc_db_table(DB_TABLE_PREFIX . self::VALUE_TABLE)->insert(array(
                                'fk_i_submission_id' => $submissionId,
                                'fk_i_field_id'      => (int) $fieldId,
                                's_multi'            => (string) $multi,
                                's_value'            => (string) $v,
                            ));
                        }
                    } else {
                        osc_db_table(DB_TABLE_PREFIX . self::VALUE_TABLE)->insert(array(
                            'fk_i_submission_id' => $submissionId,
                            'fk_i_field_id'      => (int) $fieldId,
                            's_multi'            => '',
                            's_value'            => (string) $value,
                        ));
                    }
                }
            });
        } catch (Throwable $e) {
            return false;
        }

        return $submissionId > 0 ? $submissionId : false;
    }

    /**
     * Submissions for a form (newest first), optionally filtered by status.
     *
     * @return array
     */
    public function listByForm(int $formId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $q = $this->table()->where('fk_i_group_id', $formId);
        if ($status !== null && self::isValidStatus($status)) {
            $q->where('s_status', $status);
        }

        return $q->orderBy('dt_created', 'DESC')->limit($limit)->offset($offset)->get();
    }

    public function countByForm(int $formId, ?string $status = null): int
    {
        $q = $this->table()->where('fk_i_group_id', $formId);
        if ($status !== null && self::isValidStatus($status)) {
            $q->where('s_status', $status);
        }

        return $q->count();
    }

    /**
     * Status => count for a form (only non-zero statuses appear).
     *
     * @return array<string,int>
     */
    public function statusCounts(int $formId): array
    {
        $rows = $this->table()
            ->select('s_status', 'COUNT(*) AS n')
            ->where('fk_i_group_id', $formId)
            ->groupBy('s_status')
            ->get();
        $out = array();
        foreach ($rows as $r) {
            $out[(string) $r['s_status']] = (int) $r['n'];
        }

        return $out;
    }

    public function findByPrimaryKey(int $id): ?array
    {
        return $this->table()->where('pk_i_id', $id)->first();
    }

    /**
     * The stored values for a submission, keyed by field id. A field with s_multi
     * parts (a date range) becomes an array; a scalar field a string.
     *
     * @return array<int,mixed>
     */
    public function valuesFor(int $submissionId): array
    {
        $rows = $this->valueTable()->where('fk_i_submission_id', $submissionId)->get();
        $out  = array();
        foreach ($rows as $r) {
            $fid   = (int) $r['fk_i_field_id'];
            $multi = (string) $r['s_multi'];
            if ($multi === '') {
                $out[$fid] = (string) $r['s_value'];
            } else {
                if (!isset($out[$fid]) || !is_array($out[$fid])) {
                    $out[$fid] = array();
                }
                $out[$fid][$multi] = (string) $r['s_value'];
            }
        }

        return $out;
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!self::isValidStatus($status)) {
            return false;
        }
        try {
            $this->table()->where('pk_i_id', $id)->update(array('s_status' => $status));
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete a submission (its values cascade via FK).
     */
    public function delete(int $id): bool
    {
        try {
            $this->table()->where('pk_i_id', $id)->delete();
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete every submission for a form (values cascade). Used when a form is
     * deleted or an admin purges. Returns affected rows or false.
     *
     * @return int|false
     */
    public function deleteByForm(int $formId)
    {
        try {
            return $this->table()->where('fk_i_group_id', $formId)->delete();
        } catch (Throwable $e) {
            return false;
        }
    }
}
