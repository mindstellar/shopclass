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
 * Class UserForm
 */
class UserForm extends Form
{

    /**
     * @param $user
     */
    public static function primary_input_hidden($user)
    {
        parent::generic_input_hidden('id', (isset($user['pk_i_id']) ? $user['pk_i_id'] : ''));
    }

    /**
     * @param null $user
     */
    public static function name_text($user = null)
    {
        if (Session::newInstance()->_getForm('user_s_name') != '') {
            $user['s_name'] = Session::newInstance()->_getForm('user_s_name');
        }
        parent::generic_input_text(
            's_name',
            isset($user['s_name']) ? $user['s_name'] : '',
            null,
            false
        );
    }

    /**
     * @param null $user
     */
    public static function username_text($user = null)
    {
        if (Session::newInstance()->_getForm('user_s_username') != '') {
            $user['s_username'] = Session::newInstance()->_getForm('user_s_username');
        }
        parent::generic_input_text(
            's_username',
            isset($user['s_username']) ? $user['s_username'] : '',
            null,
            false
        );
    }

    /**
     * @param null $user
     */
    public static function email_login_text($user = null)
    {
        parent::generic_input_text(
            'email',
            isset($user['s_email']) ? $user['s_email'] : '',
            null,
            false
        );
    }

    /**
     * @param null $user
     */
    public static function password_login_text($user = null)
    {
        parent::generic_password('password', '', null, false);
    }

    /**
     * @param null $user
     */
    public static function rememberme_login_checkbox($user = null)
    {
        parent::generic_input_checkbox('remember', '1', false);
    }

    /**
     * @param null $user
     */
    public static function old_password_text($user = null)
    {
        parent::generic_password('old_password', '', null, false);
    }

    /**
     * @param null $user
     */
    public static function password_text($user = null)
    {
        parent::generic_password('s_password', '', null, false);
    }

    /**
     * @param null $user
     */
    public static function check_password_text($user = null)
    {
        parent::generic_password('s_password2', '', null, false);
    }

    /**
     * @param null $user
     */
    public static function email_text($user = null)
    {
        if (Session::newInstance()->_getForm('user_s_email') != '') {
            $user['s_email'] = Session::newInstance()->_getForm('user_s_email');
        }
        parent::generic_input_text(
            's_email',
            isset($user['s_email']) ? $user['s_email'] : '',
            null,
            false
        );
    }

    /**
     * @param null $user
     */
    public static function website_text($user = null)
    {
        parent::generic_input_text(
            's_website',
            isset($user['s_website']) ? $user['s_website'] : '',
            null,
            false
        );
    }

    /**
     * @param null $user
     */
    public static function mobile_text($user = null)
    {
        if (Session::newInstance()->_getForm('user_s_phone_mobile') != '') {
            $user['s_phone_mobile'] = Session::newInstance()->_getForm('user_s_phone_mobile');
        }
        parent::generic_input_text(
            's_phone_mobile',
            isset($user['s_phone_mobile']) ? $user['s_phone_mobile'] : '',
            null,
            false
        );
    }

    /**
     * @param null $user
     */
    public static function phone_land_text($user = null)
    {
        if (Session::newInstance()->_getForm('user_s_phone_land') != '') {
            $user['s_phone_land'] = Session::newInstance()->_getForm('user_s_phone_land');
        }
        parent::generic_input_text(
            's_phone_land',
            isset($user['s_phone_land']) ? $user['s_phone_land'] : '',
            null,
            false
        );
    }

    /**
     * @param      $locales
     * @param null $user
     */
    public static function multilanguage_info($locales, $user = null)
    {

        $num_locales = $locales;
        if (count($num_locales) > 1) {
            echo '<div id="language-tab" class="mt-3">';
            echo '<ul class="nav nav-tabs" id="multiLangTabs" role="tablist">';
            foreach ($num_locales as $locale) {
                $active = '';
                if ($locale['pk_c_code'] === osc_current_admin_locale()) {
                    $active = 'show active';
                }
                echo '<li class="nav-item"><a class="nav-link btn-sm ' . $active . '" href="#' . $locale['pk_c_code']
                     . '" data-bs-toggle="tab">'
                     . $locale['s_name'] . '</a></li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        echo '<div class="tab-content mb-3" id="multiLangTabsContentUser">';
        foreach ($locales as $locale) {
            $active = '';
            if ($locale['pk_c_code'] === osc_current_admin_locale()) {
                $active = 'show active';
            }
            echo '<div class="tab-pane fade ' . $active . '" id="' . $locale['pk_c_code'] . '" role="tabpanel">';
            $name         = 's_info' . '[' . $locale['pk_c_code'] . ']';
            $attributes   = [
                'id'          => $name,
                'placeholder' => __('Enter user description') ,
            ];

            $info = '';
            if (isset($user['locale'][$locale['pk_c_code']]['s_info']) && is_array($user)) {
                $info = $user['locale'][$locale['pk_c_code']]['s_info'];
            }
            $attributes['id'] = 's_info' . '[' . $locale['pk_c_code'] . ']';
            try {
                echo (new UserForm())->textarea($name, $info, $attributes);
            } catch (Exception $e) {
                if (OSC_DEBUG) {
                    trigger_error($e->getTraceAsString());
                }
            }
            echo '</div>';
        }
        echo '</div>';

    }

    /**
     * @param        $name
     * @param string $locale
     * @param string $value
     */
    public static function info_textarea($name, $locale = 'en_US', $value = '')
    {
        parent::generic_textarea($name . '[' . $locale . ']', $value);
    }

    /**
     * @param      $countries
     * @param null $user
     */
    public static function country_select($countries, $user = null)
    {
        if (count($countries) > 1) {
            parent::generic_select(
                'countryId',
                $countries,
                'pk_c_code',
                's_name',
                __('Select a country...'),
                (isset($user['fk_c_country_code'])) ? $user['fk_c_country_code'] : null
            );
        } else {
            parent::generic_input_text(
                'country',
                (!empty($user['s_country']) ? $user['s_country'] : @$countries[0]['s_name'])
            );
            parent::generic_input_hidden('countryId', '');
        }
    }

    /**
     * @param null $user
     */
    public static function country_text($user = null)
    {
        parent::generic_input_text(
            'country',
            (isset($user['s_country'])) ? $user['s_country'] : null
        );
    }

    /**
     * @param      $regions
     * @param null $user
     */
    public static function region_select($regions, $user = null)
    {
        if (count($regions) >= 1) {
            parent::generic_select(
                'regionId',
                $regions,
                'pk_i_id',
                's_name',
                __('Select a region...'),
                (isset($user['fk_i_region_id'])) ? $user['fk_i_region_id'] : null
            );
        } else {
            parent::generic_input_text(
                'region',
                (isset($user['s_region'])) ? $user['s_region'] : null
            );
        }
    }

    /**
     * @param null $user
     */
    public static function region_text($user = null)
    {
        parent::generic_input_text('region', (isset($user['s_region'])) ? $user['s_region'] : null);
    }

    /**
     * @param      $cities
     * @param null $user
     */
    public static function city_select($cities, $user = null)
    {
        if (count($cities) >= 1) {
            parent::generic_select(
                'cityId',
                $cities,
                'pk_i_id',
                's_name',
                __('Select a city...'),
                (isset($user['fk_i_city_id'])) ? $user['fk_i_city_id'] : null
            );
        } else {
            parent::generic_input_text('city', (isset($user['s_city'])) ? $user['s_city'] : null);
        }
    }

    /**
     * @param null $user
     */
    public static function city_text($user = null)
    {
        parent::generic_input_text('city', (isset($user['s_city'])) ? $user['s_city'] : null);
    }

    /**
     * @param null $user
     */
    public static function city_area_text($user = null)
    {
        parent::generic_input_text(
            'cityArea',
            (isset($user['s_city_area'])) ? $user['s_city_area'] : null
        );
    }

    /**
     * @param null $user
     */
    public static function address_text($user = null)
    {
        parent::generic_input_text(
            'address',
            (isset($user['s_address'])) ? $user['s_address'] : null
        );
    }

    /**
     * @param null $user
     */
    public static function zip_text($user = null)
    {
        parent::generic_input_text('zip', (isset($user['s_zip'])) ? $user['s_zip'] : null);
    }

    /**
     * @param null $user
     * @param null $user_label
     * @param null $company_label
     */
    public static function is_company_select(
        $user = null,
        $user_label = null,
        $company_label = null
    ) {
        $options = array(
            array('i_value' => '0', 's_text' => ($user_label ?: __('User'))),
            array('i_value' => '1', 's_text' => ($company_label ?: __('Company')))
        );

        parent::generic_select(
            'b_company',
            $options,
            'i_value',
            's_text',
            null,
            $user['b_company'] ?? 0
        );
    }

    /**
     * @param $users
     */
    public static function user_select($users)
    {
        Form::generic_select('userId', $users, 'pk_i_id', 's_name', __('All'), null);
    }

    public static function js_validation()
    {
        // Self-contained vanilla validation (no jQuery). Renders on the public register
        // form and the admin add-user form, so it depends on no external helper.
        ?>
        <script type="text/javascript">
            (function () {
                var form = document.querySelector('form[name="register"]');
                if (!form) { return; }
                var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                form.addEventListener('submit', function (e) {
                    var errors = [];
                    form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
                    function val(name) { var el = form.querySelector('[name="' + name + '"]'); return el ? el.value.trim() : ''; }
                    function flag(name, msg) {
                        var el = form.querySelector('[name="' + name + '"]');
                        errors.push({el: el, msg: msg});
                        if (el) { el.classList.add('is-invalid'); }
                    }
                    if (val('s_name') === '') { flag('s_name', "<?php echo osc_esc_js(__('Name: this field is required')); ?>."); }
                    var em = val('s_email');
                    if (em === '') { flag('s_email', "<?php echo osc_esc_js(__('Email: this field is required')); ?>."); }
                    else if (!emailRe.test(em)) { flag('s_email', "<?php echo osc_esc_js(__('Invalid email address')); ?>."); }
                    var p1 = val('s_password'), p2 = val('s_password2');
                    if (p1 === '') { flag('s_password', "<?php echo osc_esc_js(__('Password: this field is required')); ?>."); }
                    else if (p1.length < 5) { flag('s_password', "<?php echo osc_esc_js(__('Password: enter at least 5 characters')); ?>."); }
                    if (p2 === '') { flag('s_password2', "<?php echo osc_esc_js(__('Second password: this field is required')); ?>."); }
                    else if (p2.length < 5) { flag('s_password2', "<?php echo osc_esc_js(__('Second password: enter at least 5 characters')); ?>."); }
                    else if (p1 !== p2) { flag('s_password2', "<?php echo osc_esc_js(__("Passwords don't match")); ?>."); }
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
                        form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) { b.disabled = true; });
                    }
                });
            })();
        </script>
        <?php
    }

    public static function js_validation_edit()
    {
        // Self-contained vanilla validation (no jQuery). Editing a user: the password
        // fields are optional, but if filled they must be >= 5 chars and match.
        ?>
        <script>
            (function () {
                var form = document.querySelector('form[name="register"]');
                if (!form) { return; }
                var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                form.addEventListener('submit', function (e) {
                    var errors = [];
                    form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
                    function val(name) { var el = form.querySelector('[name="' + name + '"]'); return el ? el.value.trim() : ''; }
                    function flag(name, msg) {
                        var el = form.querySelector('[name="' + name + '"]');
                        errors.push({el: el, msg: msg});
                        if (el) { el.classList.add('is-invalid'); }
                    }
                    if (val('s_name') === '') { flag('s_name', "<?php echo osc_esc_js(__('Name: this field is required')); ?>."); }
                    var em = val('s_email');
                    if (em === '') { flag('s_email', "<?php echo osc_esc_js(__('Email: this field is required')); ?>."); }
                    else if (!emailRe.test(em)) { flag('s_email', "<?php echo osc_esc_js(__('Invalid email address')); ?>."); }
                    var p1 = val('s_password'), p2 = val('s_password2');
                    if (p1 !== '' && p1.length < 5) { flag('s_password', "<?php echo osc_esc_js(__('Password: enter at least 5 characters')); ?>."); }
                    if (p2 !== '' && p2.length < 5) { flag('s_password2', "<?php echo osc_esc_js(__('Second password: enter at least 5 characters')); ?>."); }
                    else if (p1 !== p2) { flag('s_password2', "<?php echo osc_esc_js(__("Passwords don't match")); ?>."); }
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
                        form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) { b.disabled = true; });
                    }
                });
            })();
        </script>
        <?php
    }

    /**
     * @param string $path
     */
    public static function location_javascript($path = 'front')
    {
        // Vanilla (no jQuery) country -> region -> city cascade. Region/city each toggle
        // between a <select> (id regionId/cityId) when the parent has children, and a free
        // text <input> (id region/city) when it does not. Renders on the admin user editor
        // and the public register/profile forms, so it depends on no external library.
        $base = ($path === 'admin') ? osc_admin_base_url(true) : osc_base_url(true);
        ?>
        <script>
            (function () {
                var base = '<?php echo $base; ?>';
                var strRegion = "<?php echo osc_esc_js(__('Select a region...')); ?>";
                var strCity = "<?php echo osc_esc_js(__('Select a city...')); ?>";

                function byId(id) { return document.getElementById(id); }
                function replaceEl(oldId, newEl) {
                    var old = byId(oldId);
                    if (old) { old.replaceWith(newEl); }
                }
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
                    return fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'X-Requested-With': 'XMLHttpRequest'}
                    }).then(function (r) { return r.json(); }).catch(function () { return []; });
                }

                var country = byId('countryId');
                if (country) {
                    country.addEventListener('change', function () {
                        var code = this.value;
                        if (code === '') {
                            // No country: reset region + city to empty, disabled selects.
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
                                // No sub-regions: fall back to free-text inputs.
                                if (byId('regionId')) { replaceEl('regionId', makeControl('input', 'region')); }
                                if (byId('cityId')) { replaceEl('cityId', makeControl('input', 'city')); }
                            }
                        });
                    });
                }

                // Delegated so it keeps working after #regionId is recreated by the country
                // handler (the old jQuery bound directly and lost the handler on rebuild).
                document.addEventListener('change', function (e) {
                    if (!e.target || e.target.id !== 'regionId') { return; }
                    var code = e.target.value;
                    if (code === '') {
                        if (byId('cityId')) { byId('cityId').disabled = true; }
                        return;
                    }
                    if (byId('cityId')) { byId('cityId').disabled = false; }
                    post(base + '?page=ajax&action=cities&regionId=' + encodeURIComponent(code)).then(function (data) {
                        if (data.length > 0) {
                            if (byId('city')) { replaceEl('city', makeControl('select', 'cityId')); }
                            if (byId('cityId')) { byId('cityId').innerHTML = optionsHtml(strCity, data); }
                        } else {
                            if (byId('cityId')) { replaceEl('cityId', makeControl('input', 'city')); }
                        }
                    });
                });

                // Initial disabled state.
                var region = byId('regionId');
                if (region && region.value === '' && byId('cityId')) { byId('cityId').disabled = true; }
                if (country && country.tagName === 'SELECT' && country.value === '' && byId('regionId')) {
                    byId('regionId').disabled = true;
                }
            })();
        </script>
        <?php
    }
}

/* file end: ./oc-includes/osclass/form/UserForm.php */
