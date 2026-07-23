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
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('fk_i_category_id', $categoryId)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
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
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('s_plugin_name', $plugin)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $list = array();
        foreach (osc_db_stringify_rows($rows) as $sel) {
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
        try {
            $count = osc_db_table($this->getTableName())
                ->where('fk_i_category_id', $categoryId)
                ->where('s_plugin_name', $pluginName)
                ->count();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return $count > 0;
    }
}

/* file end: ./oc-includes/osclass/model/PluginCategory.php */
