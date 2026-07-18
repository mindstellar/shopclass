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
 * Class Widget
 */
class Widget extends DAO
{
    /**
     *
     * @var \Widget
     */
    private static $instance;

    /**
     * Widget constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_widget');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 's_description', 's_location', 'e_kind', 's_content'));
    }

    /**
     * @return \Widget
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     *
     * @access public
     *
     * @param string $location
     *
     * @return array
     * @since  unknown
     */
    public function findByLocation($location)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_location', $location);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     *
     * @access public
     *
     * @param string $description
     *
     * @return array
     * @since  3.3.3+
     */
    public function findByDescription($description)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_description', $description);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }
}

/* file end: ./oc-includes/osclass/model/Widget.php */
