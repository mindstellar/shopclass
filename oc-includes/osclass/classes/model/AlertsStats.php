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

use mindstellar\model\KeyValue;

/**
 * How many alert e-mails went out each day, in the `alerts_sent` group of t_key_value,
 * one key per 'Y-m-d' date.
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      3.1
 */
class AlertsStats
{
    public const KV_GROUP = 'alerts_sent';

    /**
     * @var AlertsStats
     */
    private static $instance;

    /**
     * @return AlertsStats
     * @since  3.1
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Increase the alerts-sent counter for one day.
     *
     * @param string $date 'Y-m-d'
     *
     * @return bool False when the date is malformed or the write failed
     * @since  3.1
     */
    public function increase($date)
    {
        if (!preg_match('|^[0-9]{4}-[0-9]{2}-[0-9]{2}$|', (string) $date)) {
            return false;
        }

        try {
            (new KeyValue())->increment(self::KV_GROUP, (string) $date);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }
}

/* file end: ./oc-includes/osclass/model/AlertsStats.php */
