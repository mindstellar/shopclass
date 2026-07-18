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
     * @return \Cron
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Return crons by type
     *
     * @access public
     *
     * @param string $type
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function getCronByType($type)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('e_type', $type);
        $result = $this->dao->get();

        if ($result->numRows == 0) {
            return false;
        }

        return $result->row();
    }
}

/* file end: ./oc-includes/osclass/model/Cron.php */
