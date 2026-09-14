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
 * Model database for the t_plugin_category table (per-plugin category selections).
 *
 * @package    Shopclass
 * @subpackage Model
 */
class PluginCategory extends DAO
{
    /**
     * It references to self object: PluginCategory.
     * It is used as a singleton
     *
     * @var PluginCategory
     */
    private static $instance;

    /**
     * Set data related to t_plugin_category table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_plugin_category');
        /* $this->setPrimaryKey('pk_i_id'); */
        $this->setFields(array('s_plugin_name', 'fk_i_category_id'));
    }

    /**
     * Return the shared PluginCategory model instance, creating it on first use.
     *
     * @return \PluginCategory
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Return all information given a category id
     *
     * @param int $categoryId
     *
     * @return array<int,array<string,string|null>> Empty when nothing matches or the query failed
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
     * @param string $plugin
     *
     * @return array<int,string> Category ids
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
     * @param string $pluginName
     * @param int    $categoryId
     *
     * @return bool False when the pairing does not exist or the query failed
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
