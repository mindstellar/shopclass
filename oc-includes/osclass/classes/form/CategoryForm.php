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
 * Class CategoryForm
 */
class CategoryForm extends Form
{
    /**
     * Echo the hidden input carrying the category id.
     *
     * @param array<string,mixed> $category
     *
     * @return void
     */
    public static function primary_input_hidden($category)
    {
        $attributes['id'] = 'id';
        echo (new self())->hidden('id', $category['pk_i_id'] ?? '', $attributes);
    }

    /**
     * Echo a select box of the whole category tree, with the given category selected.
     *
     * @param array<int,array<string,mixed>> $categories   Nested category tree
     * @param array<string,mixed>|null       $category     The currently selected category
     * @param string|null                    $default_item Placeholder option label
     * @param string                         $name
     *
     * @return void
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
     * Flatten a nested category tree into the select-options shape, indenting by depth.
     *
     * @param array<int,array<string,mixed>> $array
     * @param int                            $deep
     *
     * @return array<int,array<string,mixed>>
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
     * Echo the <option> rows for a category tree, recursing into subcategories.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<string,mixed>|null       $category     The currently selected category
     * @param string|null                    $default_item Unused
     * @param int                            $deep
     *
     * @return void
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
                    . osc_esc_html((string) $c['s_name']) . '</option>';
            } else {
                echo '<option value="' . $c['pk_i_id'] . '"' . ('') . '>' . $deep_string . osc_esc_html((string) $c['s_name']) . '</option>';
            }
            if (isset($c['categories']) && is_array($c['categories'])) {
                self::subcategory_select($c['categories'], $category, $default_item, $deep);
            }
        }
    }

    /**
     * Echo a nested checkbox list of the category tree, ticking the selected ids.
     *
     * @param array<int,array<string,mixed>>|null $categories
     * @param int[]|null                          $selected   Checked category ids
     * @param int                                 $depth
     *
     * @return void
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
                    . osc_esc_html((string) $c['s_name']) . (($depth == 0) ? '</span>' : '');
                self::categories_tree($c['categories'], $selected, $depth + 1);
                echo '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * Echo the expiration-days text input.
     *
     * @param array<string,mixed>|null $category
     *
     * @return void
     */
    public static function expiration_days_input_text($category = null)
    {
        $attributes['id'] = 'i_expiration_days';
        $attributes['maxlength'] = 3;
        echo (new self())->text('i_expiration_days', $category['i_expiration_days'] ?? '', $attributes);
    }

    /**
     * Echo the category position text input.
     *
     * @param array<string,mixed>|null $category
     *
     * @return void
     */
    public static function position_input_text($category = null)
    {
        $attributes['id'] = 'i_position';
        $attributes['maxlength'] = 3;
        echo (new self())->text('i_position', $category['i_position'] ?? '', $attributes);
    }

    /**
     * Echo the "enabled" checkbox, ticked when the category is enabled.
     *
     * @param array<string,mixed>|null $category
     *
     * @return void
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
     * Echo the "apply to subcategories" checkbox, unticked. The caller decides when it shows.
     *
     * @param array<string,mixed>|null $category
     *
     * @return void
     */
    public static function apply_changes_to_subcategories($category = null)
    {
        $attributes['id'] = 'apply_changes_to_subcategories';
        echo (new self())->checkbox('apply_changes_to_subcategories', 1, $attributes);
    }

    /**
     * Echo the expiry chips (Never, 30, 60, 90 days, Other) with the day count field, and
     * optionally the "Listings already posted" choice, hidden until the script shows it.
     *
     * @param int    $days    Saved expiry in days, 0 = never
     * @param string $prefix  Id prefix, also the radio group name stem
     * @param string $name    Name of the day count field
     * @param bool   $posted  Print the "Listings already posted" fieldset
     */
    public static function expiry_control(
        int $days,
        string $prefix,
        string $name = 'i_expiration_days',
        bool $posted = true
    ): void {
        $days   = max(0, $days);
        $choice = $days === 0 ? 'never' : (in_array($days, [30, 60, 90], true) ? (string) $days : 'other');
        $chips  = [
            'never' => __('Never'),
            '30'    => sprintf(_n('%d day', '%d days', 30), 30),
            '60'    => sprintf(_n('%d day', '%d days', 60), 60),
            '90'    => sprintf(_n('%d day', '%d days', 90), 90),
            'other' => __('Other…'),
        ];
        $p = osc_esc_html($prefix);

        echo '<div class="cat-exp" data-cat-exp data-saved="' . $days . '">';
        echo '<div class="cat-exp-chips" role="radiogroup" aria-label="' . osc_esc_html(__('Listings expire')) . '">';
        foreach ($chips as $value => $label) {
            $value = (string) $value;
            echo '<label class="cat-exp-chip"><input type="radio" name="' . $p . '-choice" value="' . $value . '"'
                . ($value === $choice ? ' checked' : '') . '>' . osc_esc_html($label) . '</label>';
        }
        echo '</div>';
        echo '<input type="number" min="0" max="999" name="' . osc_esc_html($name) . '" id="' . $p . '-days"'
            . ' class="form-control form-control-sm cat-exp-days" value="' . $days . '"'
            . ' aria-label="' . osc_esc_html(__('Expiration (days)')) . '"' . ($choice === 'other' ? '' : ' hidden') . '>';
        echo '</div>';

        if ($posted) {
            self::expiry_posted($prefix);
        }
    }

    /**
     * Echo the "Listings already posted" choice of an expiry control, hidden until the script
     * shows it. expiry_control() prints it unless told not to, for a form that puts it elsewhere.
     *
     * @param string $prefix Id prefix, the same as the control's
     */
    public static function expiry_posted(string $prefix): void
    {
        $p = osc_esc_html($prefix);
        echo '<fieldset class="cat-exp-posted" id="' . $p . '-posted" hidden>';
        echo '<legend>' . osc_esc_html(__('Listings already posted')) . '</legend>';
        echo '<label class="cat-exp-opt"><input type="radio" name="expiry_listings" value="keep" checked>'
            . '<span>' . osc_esc_html(__('Keep their end dates')) . '</span></label>';
        echo '<p class="form-text">' . osc_esc_html(__('Only new and renewed listings use the new expiry.')) . '</p>';
        echo '<label class="cat-exp-opt"><input type="radio" name="expiry_listings" value="new">'
            . '<span data-cat-redate>' . osc_esc_html(__('Give them new end dates')) . '</span></label>';
        echo '<p class="form-text" data-cat-redate-hint>'
            . osc_esc_html(__('Each one ends after the new number of days, counted from when it was published.'))
            . '</p>';
        echo '</fieldset>';
    }

    /**
     * Echo the "price enabled" checkbox for a category.
     *
     * @param array<string,mixed>|null $category
     *
     * @return void
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
     * Echo the tabbed per-locale name, slug and description fields for a category.
     *
     * @param array<int,array<string,mixed>> $locales
     * @param array<string,mixed>|null       $category
     *
     * @return void
     */
    public static function multilanguage_name_description($locales, $category = null)
    {
        $tabs    = array();
        $content = array();
        $locales = \mindstellar\admin\ui\Field::siteFirst($locales);
        $current_locale_code = \mindstellar\admin\ui\Field::openLocaleOf($locales);
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
            $contentTemp = '<div id="' . ($category['pk_i_id'] ?? 'new') . '-' . $locale['pk_c_code']
                . '" class="category-details-form">';
            $contentTemp .= '<div class="form-controls"><label>' . __('Name') . '</label><input id="'
                . $name . '" type="text" name="' . $name . '" value="'
                . osc_esc_html(\mindstellar\utility\Escape::htmlEntities($value, ENT_COMPAT, 'UTF-8', false)) . '"/></div>';

            // A new category gets its slug from the name it is saved with.
            if (isset($category['pk_i_id']) && $category['pk_i_id'] !== '') {
                $contentTemp .= '<div class="form-controls"><label for="' . $nameSlug . '">' . __('Web address name')
                    . '</label><input id="' . $nameSlug . '" type="text" name="' . $nameSlug . '" value="'
                    . osc_esc_html(urldecode($valueSlug)) . '" aria-describedby="' . $nameSlug . '-hint" />'
                    . '<p class="form-text" id="' . $nameSlug . '-hint">'
                    . osc_esc_html(__('Part of this category\'s web address. Letters, numbers and dashes.'))
                    . '</p></div>';
            }

            $contentTemp .= '<div class="form-controls"><label>' . __('Description') . '</label>';
            $contentTemp .= '<textarea id="' . $nameTextarea . '" name="' . $nameTextarea
                . '" rows="10">' . osc_esc_html($valueTextarea) . '</textarea>';
            $contentTemp .= '</div></div>';
            $tabs[]      = \mindstellar\admin\ui\Field::localeTab(
                ($category['pk_i_id'] ?? 'new') . '-' . $locale['pk_c_code'],
                $locale['s_name'],
                $locale['pk_c_code'],
                $current_locale_code
            );
            $content[]   = $contentTemp;
        }
        echo \mindstellar\admin\ui\Field::localeStrip($tabs, array('panels' => implode('', $content)));
    }
}

/* file end: ./oc-includes/osclass/form/CategoryForm.php */
