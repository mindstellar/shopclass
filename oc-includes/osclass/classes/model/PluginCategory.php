<?php

/*
 * This file is part of Osclass (Mindstellar).
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
 *
 */
class PluginCategory extends DAO
{
    /**
     *
     * @var
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_plugin_category');
        /* $this->setPrimaryKey('pk_i_id'); */
        $this->setFields(array('s_plugin_name', 'fk_i_category_id'));
    }

    /**
     *
     * @return \PluginCategory
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Return all information given a category id
     *
     * @access public
     *
     * @param $categoryId
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByCategoryId($categoryId)
    {
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_category_id', $categoryId);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Return list of categories asociated with a plugin
     *
     * @access public
     *
     * @param string $plugin
     *
     * @return array
     * @since  unknown
     */
    public function listSelected($plugin)
    {
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $this->dao->where('s_plugin_name', $plugin);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        $list = array();
        foreach ($result->result() as $sel) {
            $list[] = $sel['fk_i_category_id'];
        }

        return $list;
    }

    /**
     * Check if a category is asociated with a plugin
     *
     * @access public
     *
     * @param string $pluginName
     * @param int    $categoryId
     *
     * @return bool
     * @since  unknown
     */
    public function isThisCategory($pluginName, $categoryId)
    {
        $this->dao->select('COUNT(*) AS numrows');
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_category_id', $categoryId);
        $this->dao->where('s_plugin_name', $pluginName);

        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() == 0) {
            return false;
        }

        $row = $result->row();

        return !($row['numrows'] == 0);
    }
}

/* file end: ./oc-includes/osclass/model/PluginCategory.php */
