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
 * Model database for ItemLocation table
 *
 * @package    Osclass
 * @subpackage Model
 * @since      unknown
 */
class ItemLocation extends DAO
{
    /**
     * It references to self object: ItemLocation.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var ItemResource
     */
    private static $instance;

    /**
     * Set data related to t_item_location table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_location');
        $this->setPrimaryKey('fk_i_item_id');
        $array_fields = array(
            'fk_i_item_id',
            'fk_c_country_code',
            's_country',
            's_address',
            's_zip',
            'fk_i_region_id',
            's_region',
            'fk_i_city_id',
            's_city',
            'fk_i_city_area_id',
            's_city_area',
            'd_coord_lat',
            'd_coord_long'
        );
        $this->setFields($array_fields);
    }

    /**
     * It creates a new ItemLocation object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return ItemLocation
     * @since  unknown
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }
}

/* file end: ./oc-includes/osclass/model/ItemLocation.php */
