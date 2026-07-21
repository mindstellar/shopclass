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
 * Deduplicated item-report log.
 *
 * Core's ItemActions::mark() only ever incremented a raw counter in t_item_stats
 * and never deduplicated: the same person could hit the report button repeatedly
 * and drive the counter as high as they liked. This table's PRIMARY KEY is
 * (item, reporter), so one reporter is one vote no matter how many times they
 * report — which is what makes a report-count threshold safe to auto-act on.
 */
class ItemReport extends DAO
{
    /** @var ItemReport */
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_report_log');
        $this->setFields(array(
            'fk_i_item_id',
            's_reporter',
            'fk_i_user_id',
            's_ip',
            's_reason',
            'dt_date',
        ));
    }

    /**
     * @return ItemReport
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Who is reporting, for deduplication: the account when there is one, the IP
     * otherwise. An account is one vote wherever it reports from; everyone else
     * gets one vote per address. With neither (CLI, misconfigured proxy) every
     * anonymous reporter collapses onto one key and the threshold cannot be
     * reached — which is the right way to fail: the listing stays up.
     *
     * @return string
     */
    private function reporterKey()
    {
        if (osc_is_web_user_logged_in()) {
            return 'u:' . (int)osc_logged_user_id();
        }

        return 'ip:' . substr((string)Params::getServerParam('REMOTE_ADDR'), 0, 64);
    }

    /**
     * Record one report. One row per (item, reporter): INSERT IGNORE against the
     * PRIMARY KEY drops the second and every later report from the same identity,
     * and their first reason is the one that stands.
     *
     * @param int    $itemId
     * @param string $reason a report reason (spam|badcat|offensive|repeated|expired|…)
     */
    public function log($itemId, $reason)
    {
        $itemId = (int)$itemId;
        if ($itemId <= 0) {
            return;
        }

        $userId = osc_is_web_user_logged_in() ? (int)osc_logged_user_id() : null;

        $this->dao->query(sprintf(
            'INSERT IGNORE INTO %st_item_report_log'
            . ' (fk_i_item_id, s_reporter, fk_i_user_id, s_ip, s_reason, dt_date)'
            . ' VALUES (%d, %s, %s, %s, %s, NOW())',
            DB_TABLE_PREFIX,
            $itemId,
            $this->dao->escape($this->reporterKey()),
            ($userId === null) ? 'NULL' : $userId,
            $this->dao->escape(substr((string)Params::getServerParam('REMOTE_ADDR'), 0, 64)),
            $this->dao->escape(substr((string)$reason, 0, 20))
        ));
    }

    /**
     * @param int $itemId
     *
     * @return int how many DISTINCT reporters this item has
     */
    public function countReporters($itemId)
    {
        $this->dao->select('COUNT(*) AS c');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_report_log');
        $this->dao->where('fk_i_item_id', (int)$itemId);

        $result = $this->dao->get();
        if ($result === false) {
            return 0;
        }

        $row = $result->row();

        return empty($row['c']) ? 0 : (int)$row['c'];
    }

    /**
     * Per-reason distinct-reporter breakdown for one item, for the admin view.
     *
     * @param int $itemId
     *
     * @return array<string, int> reason => count
     */
    public function reasonBreakdown($itemId)
    {
        $this->dao->select('s_reason, COUNT(*) AS c');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_report_log');
        $this->dao->where('fk_i_item_id', (int)$itemId);
        $this->dao->groupBy('s_reason');

        $result = $this->dao->get();
        if ($result === false) {
            return array();
        }

        $breakdown = array();
        foreach ($result->result() as $row) {
            $breakdown[(string)$row['s_reason']] = (int)$row['c'];
        }

        return $breakdown;
    }

    /**
     * Forget an item's reports — called when an admin re-enables it, so a listing
     * that has been reviewed and cleared is not auto-blocked again by the reports
     * already on file.
     *
     * @param int $itemId
     */
    public function clear($itemId)
    {
        $this->dao->query(sprintf(
            'DELETE FROM %st_item_report_log WHERE fk_i_item_id = %d',
            DB_TABLE_PREFIX,
            (int)$itemId
        ));
    }
}
