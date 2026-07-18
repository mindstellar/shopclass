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
 * Class ItemForm
 */
class ItemForm extends Form
{

    /**
     * @param $item
     */
    public static function primary_input_hidden($item)
    {
        if ($item == null) {
            $item = osc_item();
        }
        parent::generic_input_hidden('id', $item['pk_i_id']);
    }

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
        if (Session::newInstance()->_getForm('catId') != '') {
            $catId = Session::newInstance()->_getForm('catId');
        }

        if ($categories == null) {
            if (View::newInstance()->_exists('categories')) {
                $categories = View::newInstance()->_get('categories');
            } else {
                $categories = osc_get_categories();
            }
        }

        if ($item == null) {
            $item = osc_item();
        }

        echo '<select name="catId" id="catId">';
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
                echo '<option value="' . $c['pk_i_id'] . '"' . ($selected ? ' selected="selected"'
                        : '') . '>' . $c['s_name'] . '</option>';
                if (isset($c['categories']) && is_array($c['categories'])) {
                    self::subcategory_select($c['categories'], $item, $default_item, 1);
                }
            }
        }
        echo '</select>';

        return true;
    }

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
        if (Session::newInstance()->_getForm('catId') != '') {
            $catId = Session::newInstance()->_getForm('catId');
        }
        // How many indents to add?
        $deep_string = str_repeat('&nbsp;&nbsp;', $deep);
        $deep++;

        foreach ($categories as $c) {
            $selected =
                ((isset($item['fk_i_category_id']) && $item['fk_i_category_id'] == $c['pk_i_id'])
                    || (isset($catId) && $catId == $c['pk_i_id']));

            echo '<option value="' . $c['pk_i_id'] . '"' . ($selected ? ' selected="selected'
                    . ($item['fk_i_category_id'] ?? '') . '"' : '') . '>'
                    . $deep_string . $c['s_name']
                . '</option>';
            if (isset($c['categories']) && is_array($c['categories'])) {
                self::subcategory_select($c['categories'], $item, $default_item, $deep);
            }
        }
    }
    public static function category_multiple_selects(
        $categories = null,
        $item = null,
        $default_item = null,
        $parent_selectable = false
    ) {

        $categoryID = Params::getParam('catId');
        if (osc_item_category_id() != null) {
            $categoryID = osc_item_category_id();
        }

        if (Session::newInstance()->_getForm('catId') != '') {
            $categoryID = Session::newInstance()->_getForm('catId');
        }

        if ($item == null) {
            $item = osc_item();
        }

        if (isset($item['fk_i_category_id'])) {
            $categoryID = $item['fk_i_category_id'];
        }

        $tmp_categories_tree = Category::newInstance()->toRootTree($categoryID);
        $categories_tree     = array();
        foreach ($tmp_categories_tree as $t) {
            $categories_tree[] = $t['pk_i_id'];
        }
        unset($tmp_categories_tree);

        if ($categories == null) {
            $categories = Category::newInstance()->listEnabled();
        }

        self::generic_input_hidden('catId', $categoryID);

        ?>
        <div id="select_holder"></div>
        <script type="text/javascript" charset="utf-8">
            <?php
            $tmp_cat = array();
            foreach ($categories as $c) {
                if ($c['fk_i_parent_id'] == null) {
                    $c['fk_i_parent_id'] = 0;
                }
                $tmp_cat[$c['fk_i_parent_id']][] = array($c['pk_i_id'], $c['s_name']);
            }
            foreach ($tmp_cat as $k => $v) {
                echo 'var categories_' . $k . ' = ' . json_encode($v) . ';' . PHP_EOL;
            }
            ?>

            if (typeof osc === 'undefined') { var osc = {}; }
            if (osc.langs == undefined) { osc.langs = {}; }
            if (osc.langs.select_category == undefined) {
                osc.langs.select_category = '<?php echo osc_esc_js(__('Select category')); ?>';
            }
            if (osc.langs.select_subcategory == undefined) {
                osc.langs.select_subcategory = '<?php echo osc_esc_js(__('Select subcategory')); ?>';
            }
            osc.item_post = {};
            osc.item_post.category_id = '<?php echo $categoryID; ?>';
            osc.item_post.category_tree_id = <?php echo json_encode($categories_tree); ?>;

            function draw_select(select, categoryID) {
                var tmp = window['categories_' + categoryID];
                if (tmp == null || !Array.isArray(tmp)) { return; }
                var holder = document.getElementById('select_holder');
                var sel = document.createElement('select');
                sel.id = 'select_' + select;
                // osc-category-select is a styling hook for theme authors; the Bootstrap
                // classes keep the default look.
                sel.className = 'form-select form-select-sm osc-category-select';
                sel.name = 'select_' + select;
                sel.setAttribute('depth', select);
                var options = '<option value="' + categoryID + '">' + osc.langs.select_category + '</option>';
                tmp.forEach(function (value) {
                    options += '<option value="' + value[0] + '"' +
                        (value[0] === osc.item_post.category_tree_id[select - 1] ? ' selected="selected"' : '') +
                        '>' + value[1] + '</option>';
                });
                osc.item_post.category_tree_id[select - 1] = null;
                sel.innerHTML = options;
                if (holder && holder.parentNode) { holder.parentNode.insertBefore(sel, holder); }
                var next = sel.nextElementSibling;
                if (next) {
                    var label = next.querySelector('.select-box-label');
                    if (label) { label.textContent = osc.langs.select_subcategory; }
                }
                // State hook: a 'created' event (JS) themes/plugins can react to.
                sel.dispatchEvent(new CustomEvent('created', {bubbles: true}));
            }
            window.draw_select = draw_select;

            (function () {
                function drawInitial() {
                    <?php if ($categoryID == array()) { ?>
                    draw_select(1, 0);
                    <?php } else { ?>
                    draw_select(1, 0);
                        <?php for ($i = 0; $i < count($categories_tree) - 1; $i++) { ?>
                    draw_select(<?php echo($i + 2); ?>, <?php echo $categories_tree[$i]; ?>);
                        <?php } ?>
                    <?php } ?>
                }
                if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', drawInitial); } else { drawInitial(); }

                document.addEventListener('change', function (e) {
                    var t = e.target;
                    if (!t || !t.name || t.name.indexOf('select_') !== 0) { return; }
                    var depth = parseInt(t.getAttribute('depth'), 10);
                    for (var d = depth + 1; d <= 4; d++) {
                        var deeper = document.getElementById('select_' + d);
                        if (deeper) {
                            deeper.dispatchEvent(new CustomEvent('removed', {bubbles: true}));
                            deeper.remove();
                        }
                    }
                    var catId = document.getElementById('catId');
                    if (catId) {
                        catId.value = t.value;
                        catId.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                    var enabled = typeof catPriceEnabled !== 'undefined' && catPriceEnabled[t.value] == 1;
                    document.querySelectorAll('.price').forEach(function (el) {
                        el.style.display = enabled ? '' : 'none';
                        el.classList.toggle('osc-price-visible', enabled);
                        el.classList.toggle('osc-price-hidden', !enabled);
                    });
                    if (!enabled) {
                        var price = document.getElementById('price');
                        if (price) { price.value = ''; }
                    }
                    var prev = document.getElementById('select_' + (depth - 1));
                    if ((depth === 1 && t.value != 0) || (depth > 1 && prev && t.value !== prev.value)) {
                        draw_select(depth + 1, t.value);
                    }
                });
            })();
        </script>
        <?php
    }

    /**
     * @param null $users
     * @param null $item
     * @param null $default_item
     *
     * @return bool
     */
    public static function user_select($users = null, $item = null, $default_item = null)
    {
        if ($users == null) {
            $users = User::newInstance()->listAll();
        }
        if ($item == null) {
            $item = osc_item();
        }
        $userId = '';
        if (Session::newInstance()->_getForm('userId')) {
            $userId = Session::newInstance()->_getForm('userId');
        }
        echo '<select name="userId" id="userId">';
        if (isset($default_item)) {
            echo '<option value="">' . $default_item . '</option>';
        }
        foreach ($users as $user) {
            $bool = false;
            if ($userId && $userId === $user['pk_i_id']) {
                $bool = true;
            }
            if (isset($item['fk_i_user_id']) && $item['fk_i_user_id'] === $user['pk_i_id']) {
                $bool = true;
            }
            echo '<option value="' . $user['pk_i_id'] . '"' . ($bool ? ' selected="selected"' : '')
                . '>';

            if (isset($user['s_name']) && !empty($user['s_name'])) {
                echo $user['s_name'];
            } else {
                echo $user['s_email'];
            }
            echo '</option>';
        }
        echo '</select>';

        return true;
    }

    /**
     * @param string $type
     * @param string $value
     *
     * @return bool
     */
    public static function expiration_input($type = 'add', $value = '')
    {
        if ($type === 'edit') {
            $value = '-1';  // default no change expiration date
        }
        echo '<input class="form-control form-control-sm" id="dt_expiration" type="text" name="dt_expiration" value="'
            . osc_esc_html(htmlentities($value, ENT_COMPAT, 'UTF-8'))
            . '" placeholder="yyyy-mm-dd HH:mm:ss" />';

        return true;
    }
    public static function multilanguage_title_description($locales = null, $item = null)
    {
        if ($locales === null) {
            $locales = osc_get_locales();
        }
        if ($item === null) {
            $item = osc_item();
        }
        $num_locales = count($locales);

        if ($num_locales > 1) {
            echo '<div class="tabber">';
        }
        foreach ($locales as $locale) {
            if ($num_locales > 1) {
                echo '<div class="tabbertab">';
            }
            if ($num_locales > 1) {
                echo '<h2>' . $locale['s_name'] . '</h2>';
            }
            echo '<div class="title">';
            echo '<div><label for="title">' . __('Title') . ' *</label></div>';
            $title = (isset($item) && isset($item['locale'][$locale['pk_c_code']])
                && isset($item['locale'][$locale['pk_c_code']]['s_title']))
                ? $item['locale'][$locale['pk_c_code']]['s_title'] : '';
            if (Session::newInstance()->_getForm('title') != '') {
                $title_ = Session::newInstance()->_getForm('title');
                if ($title_[$locale['pk_c_code']] != '') {
                    $title = $title_[$locale['pk_c_code']];
                }
            }
            self::title_input('title', $locale['pk_c_code'], $title);
            echo '</div>';
            echo '<div class="description">';
            echo '<div><label for="description">' . __('Description') . ' *</label></div>';
            $description = (isset($item) && isset($item['locale'][$locale['pk_c_code']])
                && isset($item['locale'][$locale['pk_c_code']]['s_description']))
                ? $item['locale'][$locale['pk_c_code']]['s_description'] : '';
            if (Session::newInstance()->_getForm('description') != '') {
                $description_ = Session::newInstance()->_getForm('description');
                if ($description_[$locale['pk_c_code']] != '') {
                    $description = $description_[$locale['pk_c_code']];
                }
            }
            self::description_textarea('description', $locale['pk_c_code'], $description);
            echo '</div>';
            if ($num_locales > 1) {
                echo '</div>';
            }
        }
        if ($num_locales > 1) {
            echo '</div>';
        }
    }

    /**
     * @param        $name
     * @param string $locale
     * @param string $value
     *
     * @return bool
     */
    public static function title_input($name, $locale = 'en_US', $value = '')
    {
        parent::generic_input_text($name . '[' . $locale . ']', $value);

        return true;
    }

    /**
     * @param        $name
     * @param string $locale
     * @param string $value
     *
     * @return bool
     */
    public static function description_textarea($name, $locale = 'en_US', $value = '')
    {
        $attributes['id'] = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name . '[' . $locale . ']');
        $options['sanitize'] = null;
        echo (new self())->textarea($name . '[' . $locale . ']', $value, $attributes, $options);
        return true;
    }

    /**
     * @param null $item
     */
    public static function price_input_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('price') != '') {
            $item['i_price'] = Session::newInstance()->_getForm('price');
        }
        parent::generic_input_text(
            'price',
            isset($item['i_price']) ? osc_prepare_price($item['i_price']) : null
        );
    }

    /**
     * @param array|null $currencies
     * @param array|null $item
     */
    public static function currency_select($currencies = null, $item = null)
    {
        if ($currencies == null) {
            $currencies = osc_get_currencies();
        }
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('currency') != '') {
            $item['fk_c_currency_code'] = Session::newInstance()->_getForm('currency');
        }
        if (count($currencies) > 1) {
            $default_key = null;
            $currency    = osc_get_preference('currency');
            if (isset($item['fk_c_currency_code'])) {
                $default_key = $item['fk_c_currency_code'];
            } elseif (isset($currency)) {
                $default_key = $currency;
            }

            parent::generic_select(
                'currency',
                $currencies,
                'pk_c_code',
                's_description',
                null,
                $default_key
            );
        } elseif (count($currencies) == 1) {
            parent::generic_input_hidden('currency', $currencies[0]['pk_c_code']);
            echo $currencies[0]['s_description'];
        }
    }

    /**
     * @param array|null $countries
     * @param array|null $item
     *
     * @return bool
     */
    public static function country_select($countries = null, $item = null)
    {
        if ($countries == null) {
            $countries = osc_get_countries();
        }
        if ($item == null) {
            $item = osc_item();
        }
        if (count($countries) >= 1) {
            if (Session::newInstance()->_getForm('countryId') != '') {
                $item['fk_c_country_code'] = Session::newInstance()->_getForm('countryId');
            }
            parent::generic_select(
                'countryId',
                $countries,
                'pk_c_code',
                's_name',
                __('Select a country...'),
                isset($item['fk_c_country_code']) ? $item['fk_c_country_code'] : null
            );

            return true;
        }

        if (Session::newInstance()->_getForm('country') != '') {
            $item['s_country'] = Session::newInstance()->_getForm('country');
        }
        parent::generic_input_text(
            'country',
            isset($item['s_country']) ? $item['s_country'] : null
        );

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function country_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('country') != '') {
            $item['s_country'] = Session::newInstance()->_getForm('country');
        }
        $only_one = false;
        if (!isset($item['s_country'])) {
            $countries = osc_get_countries();
            if (count($countries) == 1) {
                $item['s_country']         = $countries[0]['s_name'];
                $item['fk_c_country_code'] = $countries[0]['pk_c_code'];
                $only_one                  = true;
            }
        }
        parent::generic_input_text(
            'countryName',
            isset($item['s_country']) ? $item['s_country'] : null,
            null,
            $only_one
        );
        parent::generic_input_hidden(
            'countryId',
            (isset($item['fk_c_country_code']) && $item['fk_c_country_code'] != null)
                ? $item['fk_c_country_code'] : ''
        );

        return true;
    }

    /**
     * @param null $regions
     * @param null $item
     *
     * @return bool
     */
    public static function region_select($regions = null, $item = null)
    {

        if ($item == null) {
            $item = osc_item();
        }

        if (Session::newInstance()->_getForm('countryId') != '') {
            $regions =
                Region::newInstance()->findByCountry(Session::newInstance()->_getForm('countryId'));
        } elseif ($regions == null) {
            $regions = Region::newInstance()->findByCountry($item['fk_c_country_code']);
        }

        if (count($regions) >= 1) {
            if (Session::newInstance()->_getForm('regionId') != '') {
                $item['fk_i_region_id'] = Session::newInstance()->_getForm('regionId');
            }
            parent::generic_select(
                'regionId',
                $regions,
                'pk_i_id',
                's_name',
                __('Select a region...'),
                isset($item['fk_i_region_id']) ? $item['fk_i_region_id'] : null
            );

            return true;
        }

        if (Session::newInstance()->_getForm('region') != '') {
            $item['s_region'] = Session::newInstance()->_getForm('region');
        }
        parent::generic_input_text(
            'region',
            isset($item['s_region']) ? $item['s_region'] : null
        );

        return true;
    }


    /**
     * @param null $cities
     * @param null $item
     *
     * @return bool
     */
    public static function city_select($cities = null, $item = null)
    {

        if ($item == null) {
            $item = osc_item();
        }

        if (Session::newInstance()->_getForm('regionId') != '') {
            $cities =
                City::newInstance()->findByRegion(Session::newInstance()->_getForm('regionId'));
        } elseif ($cities == null && isset($item['fk_i_region_id'])) {
            $cities = City::newInstance()->findByRegion($item['fk_i_region_id']);
        }

        if (!empty($cities) && count($cities) >= 1) {
            if (Session::newInstance()->_getForm('cityId') != '') {
                $item['fk_i_city_id'] = Session::newInstance()->_getForm('cityId');
            }
            parent::generic_select(
                'cityId',
                $cities,
                'pk_i_id',
                's_name',
                __('Select a city...'),
                isset($item['fk_i_city_id']) ? $item['fk_i_city_id'] : null
            );

            return true;
        }

        if (Session::newInstance()->_getForm('city') != '') {
            $item['s_city'] = Session::newInstance()->_getForm('city');
        }
        parent::generic_input_text('city', isset($item['s_city']) ? $item['s_city'] : null);

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function region_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('region') != '') {
            $item['s_region'] = Session::newInstance()->_getForm('region');
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
     * @param null $item
     *
     * @return bool
     */
    public static function city_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('city') != '') {
            $item['s_city'] = Session::newInstance()->_getForm('city');
        }
        parent::generic_input_text('city', isset($item['s_city']) ? $item['s_city'] : null, false);
        parent::generic_input_hidden(
            'cityId',
            (isset($item['fk_i_city_id']) && $item['fk_i_city_id'] != null) ? $item['fk_i_city_id']
                : ''
        );

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function city_area_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('cityArea') != '') {
            $item['s_city_area'] = Session::newInstance()->_getForm('cityArea');
        }
        parent::generic_input_text(
            'cityArea',
            isset($item['s_city_area']) ? $item['s_city_area'] : null
        );
        parent::generic_input_hidden(
            'cityAreaId',
            (isset($item['fk_i_city_area_id']) && $item['fk_i_city_area_id'] != null)
                ? $item['fk_i_city_area_id'] : ''
        );

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function address_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('address') != '') {
            $item['s_address'] = Session::newInstance()->_getForm('address');
        }
        parent::generic_input_text(
            'address',
            isset($item['s_address']) ? $item['s_address'] : null
        );

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function zip_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('zip') != '') {
            $item['s_zip'] = Session::newInstance()->_getForm('zip');
        }
        parent::generic_input_text('zip', isset($item['s_zip']) ? $item['s_zip'] : null);

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function contact_name_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('contactName') != '') {
            $item['s_contact_name'] = Session::newInstance()->_getForm('contactName');
        }
        parent::generic_input_text(
            'contactName',
            isset($item['s_contact_name']) ? $item['s_contact_name'] : null
        );

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function contact_email_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('contactEmail') != '') {
            $item['s_contact_email'] = Session::newInstance()->_getForm('contactEmail');
        }
        parent::generic_input_text(
            'contactEmail',
            isset($item['s_contact_email']) ? $item['s_contact_email'] : null
        );

        return true;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function contact_phone_text($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (Session::newInstance()->_getForm('contactPhone') != '') {
            $item['s_contact_phone'] = Session::newInstance()->_getForm('contactPhone');
        }
        parent::generic_input_text(
            'contactPhone',
            isset($item['s_contact_phone']) ? $item['s_contact_phone'] : null
        );

        return true;
    }
    // NOTHING TO DO

    /**
     * @return bool
     */
    public static function user_data_hidden()
    {
        if (isset($_SESSION['userId']) && $_SESSION['userId'] != null) {
            $user = User::newInstance()->findByPrimaryKey($_SESSION['userId']);
            parent::generic_input_hidden('contactName', $user['s_name']);
            parent::generic_input_hidden('contactEmail', $user['s_email']);

            return true;
        }

        return false;
    }

    /**
     * @param null $item
     *
     * @return bool
     */
    public static function show_email_checkbox($item = null)
    {
        if ($item == null) {
            $item = osc_item();
        }
        if (!Session::newInstance()->_getForm('showEmail')) {
            $item['b_show_email'] = Session::newInstance()->_getForm('showEmail');
        }
        parent::generic_input_checkbox(
            'showEmail',
            '1',
            isset($item['b_show_email']) ? $item['b_show_email'] : false
        );

        return true;
    }

    /**
     * @param string $path
     */
    public static function location_javascript_new($path = 'front')
    {
        ?>
        <script>
            (function () {
                // The location autocomplete endpoints live under the public base URL.
                var base = '<?php echo osc_base_url(true); ?>';

                function byId(id) { return document.getElementById(id); }
                function setVal(id, v) { var el = byId(id); if (el) { el.value = v; } }
                function getVal(id) { var el = byId(id); return el ? el.value : ''; }

                function initAutocomplete() {
                    var countryName = byId('countryName');
                    if (countryName) {
                        oscAutocomplete(countryName, {
                            source: base + '?page=ajax&action=location_countries',
                            minLength: 0,
                            onSearch: function () { setVal('countryId', ''); },
                            onSelect: function (item) {
                                setVal('countryId', item.id);
                                setVal('regionId', ''); setVal('region', '');
                                setVal('cityId', ''); setVal('city', '');
                                return true;
                            }
                        });
                    }
                    var region = byId('region');
                    if (region) {
                        oscAutocomplete(region, {
                            source: function () {
                                var country = getVal('countryId') || getVal('country');
                                return base + '?page=ajax&action=location_regions&country=' + encodeURIComponent(country);
                            },
                            minLength: 2,
                            onSearch: function () { setVal('regionId', ''); },
                            onSelect: function (item) {
                                setVal('regionId', item.id);
                                setVal('cityId', ''); setVal('city', '');
                                return true;
                            }
                        });
                    }
                    var city = byId('city');
                    if (city) {
                        oscAutocomplete(city, {
                            source: function () {
                                var r = getVal('regionId') || getVal('region');
                                return base + '?page=ajax&action=location_cities&region=' + encodeURIComponent(r);
                            },
                            minLength: 2,
                            onSearch: function () { setVal('cityId', ''); },
                            onSelect: function (item) { setVal('cityId', item.id); return true; }
                        });
                    }
                    var countryId = byId('countryId');
                    if (countryId) {
                        countryId.addEventListener('change', function () {
                            setVal('regionId', ''); setVal('region', '');
                            setVal('cityId', ''); setVal('city', '');
                        });
                    }
                }

                function initValidation() {
                    var rules = {
                        catId: {required: true, digits: true},
                        <?php if (osc_price_enabled_at_items()) { ?>
                        price: {maxlength: 50},
                        currency: {required: true},
                        <?php } ?>
                        <?php if ($path === 'front') { ?>
                        contactName: {minlength: 3, maxlength: 35},
                        contactEmail: {required: true, email: true},
                        <?php } ?>
                        city: {maxlength: 60},
                        region: {maxlength: 60},
                        address: {minlength: 3, maxlength: 100}
                        <?php osc_run_hook('item_form_new_validation_rules'); ?>
                    };
                    var messages = {
                        catId: "<?php echo osc_esc_js(__('Choose one category')); ?>.",
                        <?php if (osc_price_enabled_at_items()) { ?>
                        price: {maxlength: "<?php echo osc_esc_js(__('Price: no more than 50 characters')); ?>."},
                        currency: "<?php echo osc_esc_js(__('Currency: make your selection')); ?>.",
                        <?php } ?>
                        <?php if ($path === 'front') { ?>
                        contactName: {
                            minlength: "<?php echo osc_esc_js(__('Name: enter at least 3 characters')); ?>.",
                            maxlength: "<?php echo osc_esc_js(__('Name: no more than 35 characters')); ?>."
                        },
                        contactEmail: {
                            required: "<?php echo osc_esc_js(__('Email: this field is required')); ?>.",
                            email: "<?php echo osc_esc_js(__('Invalid email address')); ?>."
                        },
                        <?php } ?>
                        address: {
                            minlength: "<?php echo osc_esc_js(__('Address: enter at least 3 characters')); ?>.",
                            maxlength: "<?php echo osc_esc_js(__('Address: no more than 100 characters')); ?>."
                        }
                        <?php osc_run_hook('item_form_new_validation_messages'); ?>
                    };

                    var form = document.querySelector('form[name="item"]');
                    if (!form) { return; }
                    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    var msgFor = function (field, rule) {
                        var m = messages[field];
                        if (m == null) { return ''; }
                        return (typeof m === 'string') ? m : (m[rule] || '');
                    };
                    var fieldError = function (name, spec) {
                        var el = form.querySelector('[name="' + name + '"]');
                        if (!el) { return null; }
                        if (typeof spec === 'string') { spec = (spec === 'required') ? {required: true} : {}; }
                        var v = (el.value == null ? '' : String(el.value)).trim();
                        if (spec.required && v === '') { return {el: el, msg: msgFor(name, 'required')}; }
                        if (v === '') { return null; }
                        if (spec.minlength && v.length < spec.minlength) { return {el: el, msg: msgFor(name, 'minlength')}; }
                        if (spec.maxlength && v.length > spec.maxlength) { return {el: el, msg: msgFor(name, 'maxlength')}; }
                        if (spec.email && !emailRe.test(v)) { return {el: el, msg: msgFor(name, 'email')}; }
                        if (spec.digits && !/^\d+$/.test(v)) { return {el: el, msg: msgFor(name, 'digits')}; }
                        return null;
                    };
                    form.addEventListener('submit', function (e) {
                        var errors = [];
                        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
                        Object.keys(rules).forEach(function (name) {
                            var err = fieldError(name, rules[name]);
                            if (err) { errors.push(err); if (err.el) { err.el.classList.add('is-invalid'); } }
                        });
                        var container = document.querySelector('#error_list');
                        if (container) {
                            container.innerHTML = '';
                            errors.forEach(function (er) { var li = document.createElement('li'); li.textContent = er.msg; container.appendChild(li); });
                        }
                        if (errors.length) {
                            e.preventDefault();
                            window.scrollTo({top: 0, behavior: 'smooth'});
                            if (errors[0].el && errors[0].el.focus) { errors[0].el.focus(); }
                        } else {
                            var btns = form.querySelectorAll('button[type=submit], input[type=submit]');
                            btns.forEach(function (b) { b.disabled = true; });
                            setTimeout(function () { btns.forEach(function (b) { b.disabled = false; }); }, 5000);
                        }
                    });
                }

                function boot() {
                    if (typeof oscAutocomplete === 'function') { initAutocomplete(); }
                    initValidation();
                }
                if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
            })();

            // Strip HTML tags to count visible characters. Kept global: markup and plugins call it.
            function strip_tags(html) {
                if (arguments.length < 3) {
                    return html.replace(/<\/?(?!\!)[^>]*>/gi, '');
                }
                var specified = ('' + arguments[2]).split(',').map(function (s) { return s.trim(); });
                if (arguments[1]) {
                    return html.replace(new RegExp('</?(?!(' + specified.join('|') + '))\\b[^>]*>', 'gi'), '');
                }
                return html.replace(new RegExp('</?(' + specified.join('|') + ')\\b[^>]*>', 'gi'), '');
            }

            function delete_image(id, item_id, name, secret) {
                var ok = confirm('<?php echo osc_esc_js(__("This action can't be undone. Are you sure you want to continue?")); ?>');
                if (!ok) { return; }
                fetch('<?php echo osc_base_url(true); ?>?page=ajax&action=delete_image&id=' + id + '&item=' + item_id + '&code=' + name + '&secret=' + secret, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success) {
                        var row = document.querySelector('div[name="' + name + '"]');
                        if (row) { row.remove(); }
                    }
                    var flash = document.getElementById('flash_js');
                    if (flash) {
                        flash.innerHTML = '<div class="pubMessages ' + (data.success ? 'ok' : 'error') + '" id="flashmessage"></div>';
                        var fm = document.getElementById('flashmessage');
                        fm.innerHTML = data.msg;
                        fm.style.display = 'block';
                        setTimeout(function () { fm.style.display = 'none'; }, 3000);
                    }
                });
            }
        </script>
        <?php
    }


    /**
     * @param string $path
     */
    public static function location_javascript($path = 'front')
    {
        ?>
        <script>
            (function () {
                // ---------- location cascade: country -> region -> city ----------
                var base = '<?php echo ($path === 'admin') ? osc_admin_base_url(true) : osc_base_url(true); ?>';
                var strRegion = "<?php echo osc_esc_js(__('Select a region...')); ?>";
                var strCity = "<?php echo osc_esc_js(__('Select a city...')); ?>";

                function byId(id) { return document.getElementById(id); }
                function replaceEl(oldId, newEl) { var o = byId(oldId); if (o) { o.replaceWith(newEl); } }
                function makeControl(tag, name) {
                    var el = document.createElement(tag);
                    if (tag === 'input') { el.type = 'text'; }
                    el.name = name;
                    el.id = name;
                    return el;
                }
                function optionsHtml(placeholder, data) {
                    var html = '<option value="">' + placeholder + '</option>';
                    for (var k = 0; k < data.length; k++) {
                        html += '<option value="' + data[k].pk_i_id + '">' + data[k].s_name + '</option>';
                    }
                    return html;
                }
                function post(url) {
                    return fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                        .then(function (r) { return r.json(); }).catch(function () { return []; });
                }
                function fireChange(el) { if (el) { el.dispatchEvent(new Event('change', {bubbles: true})); } }

                var country = byId('countryId');
                if (country) {
                    country.addEventListener('change', function () {
                        var code = this.value;
                        if (code === '') {
                            if (byId('region')) { replaceEl('region', makeControl('select', 'regionId')); }
                            if (byId('city')) { replaceEl('city', makeControl('select', 'cityId')); }
                            if (byId('regionId')) { byId('regionId').innerHTML = '<option value="">' + strRegion + '</option>'; byId('regionId').disabled = true; }
                            if (byId('cityId')) { byId('cityId').innerHTML = '<option value="">' + strCity + '</option>'; byId('cityId').disabled = true; }
                            return;
                        }
                        if (byId('regionId')) { byId('regionId').disabled = false; }
                        if (byId('cityId')) { byId('cityId').disabled = true; }
                        post(base + '?page=ajax&action=regions&countryId=' + encodeURIComponent(code)).then(function (data) {
                            if (data.length > 0) {
                                if (byId('region')) { replaceEl('region', makeControl('select', 'regionId')); }
                                if (byId('city')) { replaceEl('city', makeControl('select', 'cityId')); }
                                if (byId('regionId')) { byId('regionId').innerHTML = optionsHtml(strRegion, data); }
                                if (byId('cityId')) { byId('cityId').innerHTML = '<option value="">' + strCity + '</option>'; }
                            } else {
                                if (byId('regionId')) { replaceEl('regionId', makeControl('input', 'region')); }
                                if (byId('cityId')) { replaceEl('cityId', makeControl('input', 'city')); }
                            }
                            fireChange(byId('regionId'));
                            fireChange(byId('cityId'));
                        });
                    });
                }

                // Delegated so it survives #regionId being recreated by the country handler.
                document.addEventListener('change', function (e) {
                    if (!e.target || e.target.id !== 'regionId') { return; }
                    var code = e.target.value;
                    if (code === '') { if (byId('cityId')) { byId('cityId').disabled = true; } return; }
                    if (byId('cityId')) { byId('cityId').disabled = false; }
                    post(base + '?page=ajax&action=cities&regionId=' + encodeURIComponent(code)).then(function (data) {
                        if (data.length > 0) {
                            if (byId('city')) { replaceEl('city', makeControl('select', 'cityId')); }
                            if (byId('cityId')) { byId('cityId').innerHTML = optionsHtml(strCity, data); }
                        } else {
                            if (byId('cityId')) { replaceEl('cityId', makeControl('input', 'city')); }
                        }
                        fireChange(byId('cityId'));
                    });
                });

                var regionInit = byId('regionId');
                if (regionInit && regionInit.value === '' && byId('cityId')) { byId('cityId').disabled = true; }
                if (country && country.tagName === 'SELECT' && country.value === '' && byId('regionId')) { byId('regionId').disabled = true; }

                // ---------- item form validation (jquery-validate-style config, vanilla) ----------
                var rules = {
                    catId: {required: true, digits: true},
                    <?php if (osc_price_enabled_at_items()) { ?>
                    price: {maxlength: 15},
                    currency: {required: true},
                    <?php } ?>
                    <?php if ($path === 'front') { ?>
                    contactName: {minlength: 3, maxlength: 35},
                    contactEmail: {required: true, email: true},
                    <?php } ?>
                    regionId: {required: true, digits: true},
                    cityId: {required: true, digits: true},
                    cityArea: {minlength: 3, maxlength: 50},
                    address: {minlength: 3, maxlength: 100}
                    <?php osc_run_hook('item_form_validation_rules'); ?>
                };
                var messages = {
                    catId: "<?php echo osc_esc_js(__('Choose one category')); ?>.",
                    <?php if (osc_price_enabled_at_items()) { ?>
                    price: {maxlength: "<?php echo osc_esc_js(__('Price: no more than 50 characters')); ?>."},
                    currency: "<?php echo osc_esc_js(__('Currency: make your selection')); ?>.",
                    <?php } ?>
                    <?php if ($path === 'front') { ?>
                    contactName: {
                        minlength: "<?php echo osc_esc_js(__('Name: enter at least 3 characters')); ?>.",
                        maxlength: "<?php echo osc_esc_js(__('Name: no more than 35 characters')); ?>."
                    },
                    contactEmail: {
                        required: "<?php echo osc_esc_js(__('Email: this field is required')); ?>.",
                        email: "<?php echo osc_esc_js(__('Invalid email address')); ?>."
                    },
                    <?php } ?>
                    regionId: "<?php echo osc_esc_js(__('Select a region')); ?>.",
                    cityId: "<?php echo osc_esc_js(__('Select a city')); ?>.",
                    cityArea: {
                        minlength: "<?php echo osc_esc_js(__('City area: enter at least 3 characters')); ?>.",
                        maxlength: "<?php echo osc_esc_js(__('City area: no more than 50 characters')); ?>."
                    },
                    address: {
                        minlength: "<?php echo osc_esc_js(__('Address: enter at least 3 characters')); ?>.",
                        maxlength: "<?php echo osc_esc_js(__('Address: no more than 100 characters')); ?>."
                    }
                    <?php osc_run_hook('item_form_validation_messages'); ?>
                };

                var form = document.querySelector('form[name="item"]');
                if (form) {
                    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    var msgFor = function (field, rule) {
                        var m = messages[field];
                        if (m == null) { return ''; }
                        return (typeof m === 'string') ? m : (m[rule] || '');
                    };
                    var fieldError = function (name, spec) {
                        var el = form.querySelector('[name="' + name + '"]');
                        if (!el) { return null; }
                        if (typeof spec === 'string') { spec = (spec === 'required') ? {required: true} : {}; }
                        var v = (el.value == null ? '' : String(el.value)).trim();
                        if (spec.required && v === '') { return {el: el, msg: msgFor(name, 'required')}; }
                        if (v === '') { return null; }
                        if (spec.minlength && v.length < spec.minlength) { return {el: el, msg: msgFor(name, 'minlength')}; }
                        if (spec.maxlength && v.length > spec.maxlength) { return {el: el, msg: msgFor(name, 'maxlength')}; }
                        if (spec.email && !emailRe.test(v)) { return {el: el, msg: msgFor(name, 'email')}; }
                        if (spec.digits && !/^\d+$/.test(v)) { return {el: el, msg: msgFor(name, 'digits')}; }
                        return null;
                    };
                    form.addEventListener('submit', function (e) {
                        var errors = [];
                        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
                        Object.keys(rules).forEach(function (name) {
                            var err = fieldError(name, rules[name]);
                            if (err) { errors.push(err); if (err.el) { err.el.classList.add('is-invalid'); } }
                        });
                        var container = document.querySelector('#error_list');
                        if (container) {
                            container.innerHTML = '';
                            errors.forEach(function (er) { var li = document.createElement('li'); li.textContent = er.msg; container.appendChild(li); });
                        }
                        if (errors.length) {
                            e.preventDefault();
                            window.scrollTo({top: 0, behavior: 'smooth'});
                            if (errors[0].el && errors[0].el.focus) { errors[0].el.focus(); }
                        } else {
                            var btns = form.querySelectorAll('button[type=submit], input[type=submit]');
                            btns.forEach(function (b) { b.disabled = true; });
                            setTimeout(function () { btns.forEach(function (b) { b.disabled = false; }); }, 5000);
                        }
                    });
                }
            })();

            // Strip HTML tags to count visible characters. Kept global: markup and plugins call it.
            function strip_tags(html) {
                if (arguments.length < 3) {
                    return html.replace(/<\/?(?!\!)[^>]*>/gi, '');
                }
                var specified = ('' + arguments[2]).split(',').map(function (s) { return s.trim(); });
                if (arguments[1]) {
                    return html.replace(new RegExp('</?(?!(' + specified.join('|') + '))\\b[^>]*>', 'gi'), '');
                }
                return html.replace(new RegExp('</?(' + specified.join('|') + ')\\b[^>]*>', 'gi'), '');
            }

            function delete_image(id, item_id, name, secret) {
                var ok = confirm('<?php echo osc_esc_js(__("This action can't be undone. Are you sure you want to continue?")); ?>');
                if (!ok) { return; }
                fetch('<?php echo osc_base_url(true); ?>?page=ajax&action=delete_image&id=' + id + '&item=' + item_id + '&code=' + name + '&secret=' + secret, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success) {
                        var row = document.querySelector('div[name="' + name + '"]');
                        if (row) { row.remove(); }
                    }
                    var flash = document.getElementById('flash_js');
                    if (flash) {
                        flash.innerHTML = '<div class="pubMessages ' + (data.success ? 'ok' : 'error') + '" id="flashmessage"></div>';
                        var fm = document.getElementById('flashmessage');
                        fm.innerHTML = data.msg;
                        fm.style.display = 'block';
                        setTimeout(function () { fm.style.display = 'none'; }, 3000);
                    }
                });
            }
        </script>
        <?php
    }


    /**
     * @param null $resources
     *
     */
    public static function photos($resources = null)
    {
        if ($resources == null) {
            $resources = osc_get_item_resources();
        }
        if ($resources != null && is_array($resources) && count($resources) > 0) { ?>
            <div class="photos_div">
                <?php foreach ($resources as $_r) { ?>
                    <div id="<?php echo $_r['pk_i_id']; ?>"
                         fkid="<?php echo $_r['fk_i_item_id']; ?>"
                         name="<?php echo $_r['s_name']; ?>">
                        <img src="
                        <?php echo osc_apply_filter(
                                'resource_path',
                                osc_base_url() . $_r['s_path']
                            )
                            . $_r['pk_i_id'] . '_thumbnail.'
                            . $_r['s_extension']; ?>"/><a
                                href="javascript:delete_image(<?php echo $_r['pk_i_id'] . ', '
                                    . $_r['fk_i_item_id'] . ", '" . $_r['s_name'] . "', '"
                                    . Params::getParam('secret') . "'"; ?>);"
                                class="delete"><?php _e('Delete'); ?></a>
                    </div>
                <?php } ?>
            </div>
        <?php }
    }

    public static function photos_javascript()
    {
        ?>
        <script>
            var photoIndex = 0;

            function gebi(id) {
                return document.getElementById(id);
            }

            function ce(name) {
                return document.createElement(name);
            }

            function re(id) {
                var e = gebi(id);
                e.parentNode.removeChild(e);
            }

            function addNewPhoto() {
                var max = <?php echo osc_max_images_per_item(); ?>;
                var num_img = document.querySelectorAll('input[name="photos[]"]').length + document.querySelectorAll('a.delete').length;
                if ((max != 0 && num_img < max) || max == 0) {
                    var id = 'p-' + photoIndex++;

                    var i = ce('input');
                    i.setAttribute('type', 'file');
                    i.setAttribute('name', 'photos[]');

                    var a = ce('a');
                    a.style.fontSize = 'x-small';
                    a.style.paddingLeft = '10px';
                    a.setAttribute('href', '#');
                    a.setAttribute('divid', id);
                    a.onclick = function () {
                        re(this.getAttribute('divid'));
                        return false;
                    };
                    a.appendChild(document.createTextNode('<?php echo osc_esc_js(__('Remove')); ?>'));

                    var d = ce('div');
                    d.setAttribute('id', id);
                    d.setAttribute('style', 'padding: 4px 0;');

                    d.appendChild(i);
                    d.appendChild(a);

                    gebi('photos').appendChild(d);

                } else {
                    alert('<?php echo osc_esc_js(__('Sorry, you have reached the maximum number of images per listing')); ?>');
                }
            }

            // Listener: automatically add new file field when the visible ones are full.
            setInterval(add_file_field, 250);

            /**
             * Timed: if there are no empty file fields, add new file field.
             */
            function add_file_field() {
                var fields = document.querySelectorAll('input[name="photos[]"]');
                var count = 0;
                fields.forEach(function (el) {
                    if (el.value === '') {
                        count++;
                    }
                });
                var max = <?php echo osc_max_images_per_item(); ?>;
                var num_img = fields.length + document.querySelectorAll('a.delete').length;
                if (count == 0 && (max == 0 || (max != 0 && num_img < max))) {
                    addNewPhoto();
                }
            }
        </script>
        <?php
    }

    public static function plugin_edit_item()
    {
        self::plugin_post_item('edit&itemId=' . osc_item_id());
    }

    /**
     * @param string $case
     */
    public static function plugin_post_item($case = 'form')
    {
        ?>
        <script>
            var catPriceEnabled = [];
            <?php
            $categories = Category::newInstance()->listAll(false);
            foreach ($categories as $c) {
                echo 'catPriceEnabled[' . $c['pk_i_id'] . '] = ' . $c['b_price_enabled'] . ';';
            }
            ?>
            (function () {
                var url = '<?php echo (defined('OC_ADMIN') && OC_ADMIN) ? osc_admin_base_url(true) : osc_base_url(true); ?>';

                function updatePrice(catId, fireEvents) {
                    var price = document.getElementById('price');
                    if (!price) { return; }
                    var wrap = price.closest('div');
                    var enabled = catPriceEnabled[catId] == 1;
                    // State hooks for theme authors: a class on the wrapper (CSS) plus the
                    // show-price/hide-price events (JS). Replaces the old jQuery .trigger().
                    if (wrap) {
                        wrap.style.display = enabled ? '' : 'none';
                        wrap.classList.toggle('osc-price-visible', enabled);
                        wrap.classList.toggle('osc-price-hidden', !enabled);
                    }
                    if (!enabled) { price.value = ''; }
                    if (fireEvents) {
                        price.dispatchEvent(new CustomEvent(enabled ? 'show-price' : 'hide-price', {bubbles: true}));
                    }
                }

                function loadHook(catId) {
                    var body = new URLSearchParams();
                    body.set('page', 'ajax');
                    body.set('action', 'runhook');
                    body.set('hook', 'item_<?php echo $case; ?>');
                    body.set('catId', catId);
                    fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body})
                        .then(function (r) { return r.text(); })
                        .then(function (html) {
                            var hook = document.getElementById('plugin-hook');
                            if (hook) { hook.innerHTML = html; }
                        });
                }

                function apply(catId, fireEvents) {
                    if (catId !== '') { updatePrice(catId, fireEvents); loadHook(catId); }
                }

                function init() {
                    var catId = document.getElementById('catId');
                    if (!catId) { return; }
                    catId.addEventListener('change', function () { apply(this.value, true); });
                    apply(catId.value, false);
                }
                if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
            })();
        </script>
        <div id="plugin-hook">
        </div>
        <?php
    }

    /**
     * @param null $resources
     *
     */
    public static function ajax_photos($resources = null)
    {
        if ($resources == null) {
            $resources = osc_get_item_resources();
        }
        $aImages = array();
        if (Session::newInstance()->_getForm('photos') != '') {
            $aImages = Session::newInstance()->_getForm('photos');
            if (isset($aImages['name'])) {
                $aImages = $aImages['name'];
            } else {
                $aImages = array();
            }
            Session::newInstance()->_drop('photos');
            Session::newInstance()->_dropKeepForm('photos');
        }

        $aExt              = explode(',', osc_allowed_extension());
        $allowedExtensions = "'" . implode("','", $aExt) . "'";
        $acceptAttr        = '.' . implode(',.', $aExt);
        $maxSize           = osc_max_size_kb() * 1024;
        $maxImages         = osc_max_images_per_item();
        $isAdd             = Params::getParam('action') === 'item_add';
        $secret            = Params::getParam('secret');
        ?>
        <div class="osc-uploader" id="osc-uploader">
            <div class="osc-uploader-grid">
                <?php foreach ($resources as $_r) {
                    $img   = $_r['pk_i_id'] . '.' . $_r['s_extension'];
                    $thumb = osc_apply_filter('resource_path', osc_base_url() . $_r['s_path'])
                             . $_r['pk_i_id'] . '_thumbnail.' . $_r['s_extension']; ?>
                    <div class="osc-uploader-item"
                         data-id="<?php echo osc_esc_html($_r['pk_i_id']); ?>"
                         data-item="<?php echo osc_esc_html($_r['fk_i_item_id']); ?>"
                         data-code="<?php echo osc_esc_html($_r['s_name']); ?>"
                         data-secret="<?php echo osc_esc_html($secret); ?>">
                        <img class="osc-uploader-thumb" src="<?php echo osc_esc_html($thumb); ?>" alt="<?php echo osc_esc_html($img); ?>">
                        <button type="button" class="osc-uploader-remove" aria-label="<?php echo osc_esc_html(__('Delete')); ?>">&times;</button>
                    </div>
                <?php } ?>
                <?php foreach ($aImages as $img) { ?>
                    <div class="osc-uploader-item" data-temp="<?php echo osc_esc_html($img); ?>">
                        <img class="osc-uploader-thumb" src="<?php echo osc_esc_html(osc_base_url() . 'oc-content/uploads/temp/' . $img); ?>" alt="<?php echo osc_esc_html($img); ?>">
                        <input type="hidden" name="ajax_photos[]" value="<?php echo osc_esc_html($img); ?>">
                        <button type="button" class="osc-uploader-remove" aria-label="<?php echo osc_esc_html(__('Delete')); ?>">&times;</button>
                    </div>
                <?php } ?>
            </div>
            <label class="osc-uploader-drop">
                <input type="file" class="osc-uploader-input" multiple accept="<?php echo osc_esc_html($acceptAttr); ?>" hidden>
                <span><?php _e('Click, or drop images here, to upload'); ?></span>
            </label>
            <div class="osc-uploader-errors"></div>
        </div>
        <script>
            (function () {
                // osc-uploader.js is printed in the footer (self-enqueued above), so wait
                // for the document to finish parsing before initialising.
                function boot() {
                    var root = document.getElementById('osc-uploader');
                    if (!root || typeof oscPhotoUploader !== 'function') { return; }
                    oscPhotoUploader(root, {
                    endpoint: '<?php echo osc_base_url(true); ?>?page=ajax&action=ajax_upload',
                    deleteEndpoint: '<?php echo osc_base_url(true); ?>?page=ajax&action=delete_image',
                    tempBase: '<?php echo osc_base_url(); ?>oc-content/uploads/temp/',
                    fieldName: 'qqfile',
                    maxImages: <?php echo (int)$maxImages; ?>,
                    maxSizeBytes: <?php echo (int)$maxSize; ?>,
                    allowedExtensions: [<?php echo $allowedExtensions; ?>],
                    showPrimary: <?php echo $isAdd ? 'true' : 'false'; ?>,
                    i18n: {
                        confirmDelete: "<?php echo osc_esc_js(__("This action can't be undone. Are you sure you want to continue?")); ?>",
                        typeError: "<?php echo osc_esc_js(__('{file} has an invalid extension. Valid extension(s): {extensions}.')); ?>",
                        sizeError: "<?php echo osc_esc_js(__('{file} is too large.')); ?>",
                        tooMany: "<?php echo osc_esc_js(__('Too many images. The limit is {limit}.')); ?>",
                        failUpload: "<?php echo osc_esc_js(__('{file} could not be uploaded.')); ?>",
                        primary: "<?php echo osc_esc_js(__('Primary')); ?>",
                        makePrimary: "<?php echo osc_esc_js(__('Make primary image')); ?>",
                        "delete": "<?php echo osc_esc_js(__('Delete')); ?>",
                        close: "<?php echo osc_esc_js(__('Close')); ?>"
                    }
                    });
                }
                if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
            })();
        </script>
        <?php
    }
}
