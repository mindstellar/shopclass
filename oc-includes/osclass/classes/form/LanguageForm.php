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
 * Class LanguageForm
 */
class LanguageForm extends Form
{
    /**
     * Echo the client-side validation script for the language form.
     *
     * @param bool $admin Unused
     *
     * @return void
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
