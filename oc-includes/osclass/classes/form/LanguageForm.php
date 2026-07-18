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
 * Class LanguageForm
 */
class LanguageForm extends Form
{

    /**
     * @param $locale
     */
    public static function primary_input_hidden($locale)
    {
        parent::generic_input_hidden('pk_c_code', $locale['pk_c_code']);
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function name_input_text($locale = null)
    {
        parent::generic_input_text('s_name', isset($locale) ? $locale['s_name'] : '');

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function short_name_input_text($locale = null)
    {
        parent::generic_input_text('s_short_name', isset($locale) ? $locale['s_short_name'] : '');

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function description_input_text($locale = null)
    {
        parent::generic_input_text('s_description', isset($locale) ? $locale['s_description'] : '');

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function currency_format_input_text($locale = null)
    {
        parent::generic_input_text(
            's_currency_format',
            isset($locale) ? $locale['s_currency_format'] : ''
        );

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function dec_point_input_text($locale = null)
    {
        parent::generic_input_text('s_dec_point', isset($locale) ? $locale['s_dec_point'] : '');

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function num_dec_input_text($locale = null)
    {
        parent::generic_input_text('i_num_dec', isset($locale) ? $locale['i_num_dec'] : '');

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function thousands_sep_input_text($locale = null)
    {
        parent::generic_input_text(
            's_thousands_sep',
            isset($locale) ? $locale['s_thousands_sep'] : ''
        );

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function date_format_input_text($locale = null)
    {
        parent::generic_input_text('s_date_format', isset($locale) ? $locale['s_date_format'] : '');

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function description_textarea($locale = null)
    {
        parent::generic_textarea('s_stop_words', $locale['s_stop_words']);

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function enabled_input_checkbox($locale = null)
    {
        parent::generic_input_checkbox('b_enabled', '1', $locale['b_enabled'] == 1);

        return true;
    }

    /**
     * @param null $locale
     *
     * @return bool
     */
    public static function enabled_bo_input_checkbox($locale = null)
    {
        parent::generic_input_checkbox('b_enabled_bo', '1', $locale['b_enabled_bo'] == 1);

        return true;
    }

    public static function text_direction_select($aLocale = null)
    {
        $options['selectOptions'] = 'ltr,rtl';
        $attributes['id'] = 's_direction';
        $value = $aLocale['s_direction'];

        echo (new Form)->select('s_direction', $value, $attributes, $options );
    }

    /**
     * @param bool $admin
     */
    public static function js_validation($admin = false)
    {
        // Admin-only form: uses the admin's native validator (ui-osc.js), not jQuery.
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                oscValidateForm(document.querySelector('form[name="language_form"]'), {
                    rules: {
                        s_name: {required: true},
                        s_short_name: {required: true},
                        s_description: {required: true},
                        s_currency_format: {required: true},
                        i_num_dec: {required: true, digits: true},
                        s_dec_point: {required: true},
                        s_thousand_sep: {required: true},
                        s_date_format: {required: true}
                    },
                    messages: {
                        s_name: {required: "<?php _e('Name: this field is required'); ?>."},
                        s_short_name: {required: "<?php _e('Short name: this field is required'); ?>."},
                        s_description: {required: "<?php _e('Description: this field is required'); ?>."},
                        s_currency_format: {required: "<?php _e('Currency format: this field is required'); ?>."},
                        i_num_dec: {
                            required: "<?php _e('Number of decimals: this field is required'); ?>.",
                            digits: "<?php _e('Number of decimals: this field must only contain numeric characters'); ?>."
                        },
                        s_dec_point: {required: "<?php _e('Decimal point: this field is required'); ?>."},
                        s_thousand_sep: {required: "<?php _e('Thousands separator: this field is required'); ?>."},
                        s_date_format: {required: "<?php _e('Date format: this field is required'); ?>."}
                    },
                    errorContainer: '#error_list',
                    onInvalid: function () {
                        window.scrollTo({top: 0, behavior: 'smooth'});
                    }
                });
            });
        </script>
        <?php
    }
}
