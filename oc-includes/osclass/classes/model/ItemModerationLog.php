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
 * Durable "why was this listing hidden" record.
 *
 * When a listing is quarantined by the keyword filter or auto-disabled past the
 * report threshold, a row is written here naming the source and the detail (the
 * matched keyword, the report count). Without it a hidden listing is a mystery:
 * the admin sees a disabled ad and has no way to tell whether a filter did it,
 * let alone which keyword was responsible.
 */
class ItemModerationLog extends DAO
{
    /** @var ItemModerationLog */
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_moderation_log');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array(
            'pk_i_id',
            'fk_i_item_id',
            's_source',
            's_reason',
            's_field',
            's_action',
            'dt_date',
        ));
    }

    /**
     * @return ItemModerationLog
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Record one moderation event.
     *
     * @param int    $itemId
     * @param string $source keyword|report_threshold
     * @param string $reason the matched keyword / detail
     * @param string $field  the scope/field that fired (title|description|meta|…)
     * @param string $action spam|disable
     *
     * @return bool
     */
    public function add($itemId, $source, $reason, $field = '', $action = '')
    {
        return (bool)$this->insert(array(
            'fk_i_item_id' => (int)$itemId,
            's_source'     => substr((string)$source, 0, 20),
            's_reason'     => substr((string)$reason, 0, 191),
            's_field'      => substr((string)$field, 0, 20),
            's_action'     => substr((string)$action, 0, 20),
            'dt_date'      => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Moderation events for one item, newest first.
     *
     * @param int $itemId
     *
     * @return array
     */
    public function findByItem($itemId)
    {
        $this->dao->select('*');
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_item_id', (int)$itemId);
        $this->dao->orderBy('dt_date', 'DESC');

        $result = $this->dao->get();
        if ($result === false) {
            return array();
        }

        return $result->result();
    }

    /**
     * The most recent moderation event for one item, or null.
     *
     * @param int $itemId
     *
     * @return array|null
     */
    public function latestForItem($itemId)
    {
        $rows = $this->findByItem($itemId);

        return empty($rows) ? null : $rows[0];
    }
}
