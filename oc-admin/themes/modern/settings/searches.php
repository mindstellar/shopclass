<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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
 * The chrome around the declared latest-searches form. The form itself -- its route, its
 * fields, their values and the submit row -- is core's, drawn from the declaration the
 * controller saves through.
 */

$form = __get('searches_form');

//customize Head
/**
 * Emit the search-settings form's client-side validation rules.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            oscValidateForm(document.querySelector('form[name=searches_form]'), {
                rules: {
                    custom_queries: {
                        digits: true,
                        // Required only when the "custom" purge option is selected.
                        custom: function (value, form) {
                            var picked = form.querySelector('input[name=purge_searches]:checked');
                            if (picked && picked.value === 'custom') {
                                return value !== '';
                            }
                            return true;
                        }
                    }
                },
                messages: {
                    custom_queries: {
                        digits: '<?php echo osc_esc_js(__('Custom number: this field must only contain numeric characters')); ?>.',
                        custom: '<?php echo osc_esc_js(__('Custom number: this field cannot be left empty')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });

            // The radios and the free-text box both write the hidden field the form stores.
            // Typing a number is choosing it, or the value is entered and discarded.
            var purge  = document.getElementById('customPurge');
            var custom = document.getElementById('custom_queries');
            if (purge) {
                var sync = function () {
                    var picked = document.querySelector('input[name=purge_searches]:checked');
                    if (!picked) {
                        return;
                    }
                    purge.value = picked.value === 'custom' ? (custom ? custom.value : '') : picked.value;
                };
                document.querySelectorAll('input[name=purge_searches]').forEach(function (radio) {
                    radio.addEventListener('change', sync);
                });
                if (custom) {
                    custom.addEventListener('input', function () {
                        var radio = document.querySelector('input[name=purge_searches][value=custom]');
                        if (radio) {
                            radio.checked = true;
                        }
                        sync();
                    });
                }
            }
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Latest searches Settings'),
    'help'    => __("Save the searches users do on your site. In this way, you can get information on what they're most "
                    . 'interested in. From here, you can manage the options on how much information you want to save.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-setting">
    <!-- settings form -->
    <div id="general-settings">
        <?php osc_admin_page_head(__('Latest searches Settings')); ?>
        <ul id="error_list"></ul>
        <?php osc_admin_settings_form($form['id'], $form); ?>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
