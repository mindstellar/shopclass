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
 * Class CategoryForm
 */
class CategoryForm extends Form
{
    /**
     * @param $category
     */
    public static function primary_input_hidden($category)
    {
        $attributes['id'] = 'id';
        echo (new self())->hidden('id', $category['pk_i_id'], $attributes);
    }

    /**
     * @param        $categories
     * @param        $category
     * @param null   $default_item
     * @param string $name
     */
    public static function category_select(
        $categories,
        $category,
        $default_item = null,
        $name = 'sCategory'
    ) {
        $options['selectOptions'] = self::prepareOptionsArray($categories, 0);
        $attribute['id'] = 'id';
        $options['selectPlaceholder'] = $default_item;
        echo (new self())->select($name, $category['pk_i_id'] ?? '', $attribute, $options);
    }

    /**
     * @param $array
     * @param $deep
     *
     * @return array
     */
    private static function prepareOptionsArray($array, $deep)
    {
        $deep_string = str_repeat('&nbsp;&nbsp;', $deep);
        $deep++;
        $values = [];
        foreach ($array as $c) {
            $option['option']['value'] = $c['pk_i_id'];
            $option['option']['label'] = $deep_string.$c['s_name'];

            if (isset($c['categories']) && is_array($c['categories']) && ! empty($c['categories'])) {
                $option['children'] = self::prepareOptionsArray($c['categories'], $deep);
            }
            $values[] = $option;
            unset($option);
        }
        return $values;
    }

    /**
     * @param      $categories
     * @param      $category
     * @param null $default_item
     * @param int  $deep
     */
    public static function subcategory_select(
        $categories,
        $category,
        $default_item = null,
        $deep = 0
    ) {
        $deep_string = str_repeat('&nbsp;&nbsp;', $deep);
        $deep++;
        foreach ($categories as $c) {
            if ((isset($category['pk_i_id']) && $category['pk_i_id'] === $c['pk_i_id'])) {
                echo '<option value="' . $c['pk_i_id'] . '"' . ('selected="selected"') . '>' . $deep_string
                    . $c['s_name'] . '</option>';
            } else {
                echo '<option value="' . $c['pk_i_id'] . '"' . ('') . '>' . $deep_string . $c['s_name'] . '</option>';
            }
            if (isset($c['categories']) && is_array($c['categories'])) {
                self::subcategory_select($c['categories'], $category, $default_item, $deep);
            }
        }
    }

    /**
     * @param null $categories
     * @param null $selected
     * @param int  $depth
     */
    public static function categories_tree($categories = null, $selected = null, $depth = 0)
    {
        if (($categories != null) && is_array($categories)) {
            echo '<ul id="cat' . $categories[0]['fk_i_parent_id'] . '">';

            $d_string = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);

            foreach ($categories as $c) {
                echo '<li>';
                echo $d_string . '<input type="checkbox" name="categories[]" value="'
                    . $c['pk_i_id'] . '" onclick="javascript:checkCat(\'' . $c['pk_i_id']
                    . '\', this.checked);" ' . (in_array($c['pk_i_id'], $selected)
                        ? 'checked="checked"' : '') . ' />' . (($depth == 0) ? '<span>' : '')
                    . $c['s_name'] . (($depth == 0) ? '</span>' : '');
                self::categories_tree($c['categories'], $selected, $depth + 1);
                echo '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * @param null $category
     */
    public static function expiration_days_input_text($category = null)
    {
        $attributes['id'] ='i_expiration_days';
        $attributes['maxlength'] = 3;
        echo (new self())->text('i_expiration_days', $category['i_expiration_days'] ?? '', $attributes);
    }

    /**
     * @param null $category
     */
    public static function position_input_text($category = null)
    {
        $attributes['id'] = 'i_position';
        $attributes['maxlength'] = 3;
        echo (new self())->text('i_position', $category['i_position'] ?? '', $attributes);
    }

    /**
     * @param null $category
     */
    public static function enabled_input_checkbox($category = null)
    {
        $attributes['id']           = 'b_enabled';
        if ((isset($category['b_enabled']) && $category['b_enabled'])) {
            $attributes['checked'] = true;
        }
        echo (new self())->checkbox('b_enabled', 1, $attributes);
    }

    /**
     * @param null $category
     */
    public static function apply_changes_to_subcategories($category = null)
    {
        if ($category['fk_i_parent_id'] === null) {
            $attributes['id']           = 'apply_changes_to_subcategories';
            $attributes['checked'] = true;
            echo (new self())->checkbox('apply_changes_to_subcategories', 1, $attributes);
        }
    }

    /**
     * @param null $category
     */
    public static function price_enabled_for_category($category = null)
    {
        $attributes['id']           = 'b_price_enabled';
        if ((isset($category['b_price_enabled']) && $category['b_price_enabled'])
        ) {
            $attributes['checked'] = true;
        }
        echo (new self())->checkbox('b_price_enabled', 1, $attributes);
    }

    /**
     * @param      $locales
     * @param null $category
     */
    public static function multilanguage_name_description($locales, $category = null)
    {
        $tabs    = array();
        $content = array();
        $current_locale_code = OC_ADMIN?osc_current_admin_locale():osc_current_user_locale();
        foreach ($locales as $locale) {
            $value         = isset($category['locale'][$locale['pk_c_code']])
                ? $category['locale'][$locale['pk_c_code']]['s_name'] : '';
            $name          = $locale['pk_c_code'] . '#s_name';

            $nameSlug      = $locale['pk_c_code'] . '#s_slug';
            $valueSlug     = isset($category['locale'][$locale['pk_c_code']])
                ? $category['locale'][$locale['pk_c_code']]['s_slug'] : '';

            $nameTextarea  = $locale['pk_c_code'] . '#s_description';
            $valueTextarea = isset($category['locale'][$locale['pk_c_code']])
                ? $category['locale'][$locale['pk_c_code']]['s_description'] : '';
            if ($current_locale_code === $locale['pk_c_code']) {
                $active_class = ' class="ui-tabs-active ui-state-active"';
            } else {
                $active_class = '';
            }
            $contentTemp = '<div id="' . $category['pk_i_id'] . '-' . $locale['pk_c_code']
                . '" class="category-details-form">';
            $contentTemp .= '<div class="form-controls"><label>' . __('Name') . '</label><input id="'
                . $name . '" type="text" name="' . $name . '" value="'
                . osc_esc_html(htmlentities($value, ENT_COMPAT, 'UTF-8')) . '"/></div>';

            $contentTemp .= '<div class="form-controls"><label>' . __('Slug') . '</label><input id="'
                . $name . '" type="text" name="' . $nameSlug . '" value="'
                . urldecode($valueSlug) . '" /></div>';

            $contentTemp .= '<div class="form-controls"><label>' . __('Description') . '</label>';
            $contentTemp .= '<textarea id="' . $nameTextarea . '" name="' . $nameTextarea
                . '" rows="10">' . $valueTextarea . '</textarea>';
            $contentTemp .= '</div></div>';
            $tabs[]      =
                '<li'.$active_class.'><a href="#' . $category['pk_i_id'] . '-' . $locale['pk_c_code'] . '">'
                . $locale['s_name'] . '</a></li>';
            $content[]   = $contentTemp;
        }
        echo '<div class="ui-osc-tabs osc-tab">';
        echo '<ul>' . implode('', $tabs) . '</ul>';
        echo implode('', $content);
        echo '</div>';
    }
}

/* file end: ./oc-includes/osclass/form/CategoryForm.php */
