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
 * Class ManageItemsForm
 */
class ManageItemsForm extends Form
{

    // OK
    /**
     * @param null $categories
     * @param null $item
     * @param null $default_item
     * @param bool $parent_selectable
     *
     * @return bool
     */
    public static function category_select(
        $categories = null,
        $item = null,
        $default_item = null,
        $parent_selectable = false
    ) {
        // Did user select a specific category to post in?
        $catId = Params::getParam('catId');

        if ($categories == null) {
            if (View::newInstance()->_exists('categories')) {
                $categories = View::newInstance()->_get('categories');
            } else {
                $categories = osc_get_categories();
            }
        }

        echo '<select class="form-select-sm form-select" name="catId" id="catId">';
        if (isset($default_item)) {
            echo '<option value="">' . $default_item . '</option>';
        } else {
            echo '<option value="">' . __('Select a category') . '</option>';
        }

        if (count($categories) == 1) {
            $parent_selectable = 1;
        }

        foreach ($categories as $c) {
            if (!osc_selectable_parent_categories() && !$parent_selectable) {
                echo '<optgroup label="' . $c['s_name'] . '">';
                if (isset($c['categories']) && is_array($c['categories'])) {
                    self::subcategory_select($c['categories'], $item, $default_item, 1);
                }
            } else {
                $selected = ((isset($item['fk_i_category_id'])
                        && $item['fk_i_category_id'] == $c['pk_i_id'])
                    || (isset($catId) && $catId == $c['pk_i_id']));
                echo '<option value="' . $c['pk_i_id'] . '"' . ($selected ? 'selected="selected"'
                        : '') . '>' . $c['s_name'] . '</option>';
                if (isset($c['categories']) && is_array($c['categories'])) {
                    self::subcategory_select($c['categories'], $item, $default_item, 1);
                }
            }
        }
        echo '</select>';

        return true;
    }

    // OK

    /**
     * @param      $categories
     * @param      $item
     * @param null $default_item
     * @param int  $deep
     */
    public static function subcategory_select($categories, $item, $default_item = null, $deep = 0)
    {
        // Did user select a specific category to post in?
        $catId = Params::getParam('catId');
        // How many indents to add?
        $deep_string = str_repeat('&nbsp;&nbsp;', $deep);
        $deep++;

        foreach ($categories as $c) {
            $selected =
                ((isset($item['fk_i_category_id']) && $item['fk_i_category_id'] == $c['pk_i_id'])
                    || (isset($catId) && $catId == $c['pk_i_id']));

            echo '<option value="' . $c['pk_i_id'] . '"' . ($selected ? 'selected="selected'
                    . $item['fk_i_category_id'] . '"' : '') . '>' . $deep_string . $c['s_name']
                . '</option>';
            if (isset($c['categories']) && is_array($c['categories'])) {
                self::subcategory_select($c['categories'], $item, $default_item, $deep);
            }
        }
    }

    /**
     * @return bool
     */
    public static function country_text()
    {
        // get params GET (only manageItems)
        if (Params::getParam('countryName') != '') {
            $item['s_country']         = Params::getParam('countryName');
            $item['fk_c_country_code'] = Params::getParam('countryId');
        }

        parent::generic_input_text(
            'countryName',
            isset($item['s_country']) ? $item['s_country'] : null,
            false
        );
        parent::generic_input_hidden(
            'countryId',
            (isset($item['fk_c_country_code']) && $item['fk_c_country_code'] != null)
                ? $item['fk_c_country_code'] : ''
        );

        return true;
    }

    /**
     * @return bool
     */
    public static function region_text()
    {
        // get params GET (only manageItems)
        if (Params::getParam('region') != '') {
            $item['s_region']       = Params::getParam('region');
            $item['fk_i_region_id'] = Params::getParam('regionId');
        }
        parent::generic_input_text(
            'region',
            isset($item['s_region']) ? $item['s_region'] : null,
            false
        );
        parent::generic_input_hidden(
            'regionId',
            (isset($item['fk_i_region_id']) && $item['fk_i_region_id'] != null)
                ? $item['fk_i_region_id'] : ''
        );

        return true;
    }

    /**
     * @return bool
     */
    public static function city_text()
    {
        // get params GET (only manageItems)
        if (Params::getParam('city') != '') {
            $item['s_city']       = Params::getParam('city');
            $item['fk_i_city_id'] = Params::getParam('cityId');
        }
        parent::generic_input_text('city', isset($item['s_city']) ? $item['s_city'] : null, false);
        parent::generic_input_hidden(
            'cityId',
            (isset($item['fk_i_city_id']) && $item['fk_i_city_id'] != null) ? $item['fk_i_city_id']
                : ''
        );

        return true;
    }
}
