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

/**
 * Class Cron
 */
class Cron extends DAO
{
    /**
     *
     * @var Cron
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_cron');
        $this->setFields(array('e_type', 'd_last_exec', 'd_next_exec'));
    }

    /**
     * Return the shared Cron model instance, creating it on first use.
     *
     * @return \Cron
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Return crons by type
     *
     * @param string $type
     *
     * @return array<string,string|null>|false The row, or false when no cron of that type exists
     */
    public function getCronByType($type)
    {
        $row = osc_db_table($this->getTableName())
            ->where('e_type', $type)
            ->first();

        if ($row === null) {
            return false;
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Take a due run: move the schedule on, but only if it still says what the caller read.
     * Of two requests (or servers) that saw the same run due, only one gets true.
     *
     * @param string $type     HOURLY, DAILY or WEEKLY
     * @param string $seenNext d_next_exec as the caller read it
     * @param string $lastExec
     * @param string $nextExec
     *
     * @return bool
     */
    public function claim(string $type, string $seenNext, string $lastExec, string $nextExec): bool
    {
        $q = osc_db_table($this->getTableName())->where('e_type', $type)->where('d_next_exec', $seenNext);

        try {
            return $q->update(array('d_last_exec' => $lastExec, 'd_next_exec' => $nextExec)) === 1;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/Cron.php */
