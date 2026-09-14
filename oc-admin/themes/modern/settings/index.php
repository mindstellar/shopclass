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
 * The chrome around the declared general-settings form. The form itself -- its headings,
 * its route, its fields, their values and the submit row -- is core's, drawn from the
 * declaration the controller saves through.
 */

$form = __get('main_form');

//customize Head
/**
 * Emit the general-settings form's client-side validation rules.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            oscValidateForm(document.querySelector('form[name=settings_form]'), {
                rules: {
                    pageTitle: { required: true, minlength: 1 },
                    contactEmail: { required: true, email: true },
                    num_rss_items: { required: true, digits: true },
                    max_latest_items_at_home: { required: true, digits: true },
                    default_results_per_page: { required: true, digits: true }
                },
                messages: {
                    pageTitle: {
                        required: '<?php echo osc_esc_js(__('Page title: this field is required')); ?>.',
                        minlength: '<?php echo osc_esc_js(__('Page title: this field is required')); ?>.'
                    },
                    contactEmail: {
                        required: '<?php echo osc_esc_js(__('Email: this field is required')); ?>.',
                        email: '<?php echo osc_esc_js(__('Invalid email address')); ?>.'
                    },
                    num_rss_items: {
                        required: '<?php echo osc_esc_js(__('Listings shown in RSS feed: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Listings shown in RSS feed: this field must only contain numeric characters')); ?>.'
                    },
                    max_latest_items_at_home: {
                        required: '<?php echo osc_esc_js(__('Latest listings shown: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Latest listings shown: this field must only contain numeric characters')); ?>.'
                    },
                    default_results_per_page: {
                        required: '<?php echo osc_esc_js(__('The search page shows: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('The search page shows: this field must only contain numeric characters')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });
        });

        // Date and time format. The radios and the free-text box both write the hidden
        // field the controller reads (dateFormat / timeFormat); the free-text box also asks
        // the server what its format renders as, since PHP's date() is not JS's.
        function previewFormat(format, target) {
            if (!target) {
                return;
            }
            if (format === '') {
                target.textContent = '';

                return;
            }
            fetch("<?php echo osc_admin_base_url(true); ?>?page=ajax&action=date_format&format=" + encodeURIComponent(format), {
                credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                target.textContent = data.str_formatted !== '' ? ' <?php echo osc_esc_js(__('Preview')); ?>: ' + data.str_formatted : '';
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            [['date', 'df'], ['time', 'tf']].forEach(function (pair) {
                var kind    = pair[0];
                var group   = pair[1];
                var hidden  = document.getElementById(kind === 'date' ? 'dateFormat' : 'timeFormat');
                var custom  = document.getElementById(group + '_custom_text');
                var preview = document.getElementById('custom_' + kind);
                var radios  = document.querySelectorAll('input[name="' + group + '"]');
                if (!hidden || !radios.length) {
                    return;
                }

                function sync() {
                    var picked = document.querySelector('input[name="' + group + '"]:checked');
                    if (!picked) {
                        return;
                    }
                    var isCustom = picked.value === group + '_custom';
                    hidden.value = isCustom ? (custom ? custom.value : '') : picked.value;
                    previewFormat(isCustom ? hidden.value : '', preview);
                }

                radios.forEach(function (radio) {
                    radio.addEventListener('change', sync);
                });

                if (custom) {
                    // Typing a format is choosing it: without this the value is entered and
                    // silently discarded because another radio is still selected.
                    custom.addEventListener('input', function () {
                        var radio = document.querySelector('input[name="' + group + '"][value="' + group + '_custom"]');
                        if (radio) {
                            radio.checked = true;
                        }
                        sync();
                    });
                }
            });
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('General Settings'),
    'help'    => __("Change the basic configuration of your Shopclass. From here, you can modify variables such as the site’s name, "
                    . "the default currency or how lists of listings are displayed. <strong>Be careful</strong> when modifying default "
                    . "values if you're not sure what you're doing!"),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-setting">
    <!-- settings form -->
    <div id="general-settings">
        <?php osc_admin_page_head(__('General Settings')); ?>
        <ul id="error_list"></ul>
        <?php osc_admin_settings_form($form['id'], $form); ?>
    </div>
    <!-- /settings form -->
</div>
<script>
    document.getElementById('check-updates').addEventListener('click', function () {
        var lastVersionElement = document.getElementById('last-version-check');
        fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_version')
            .then(function (response) { return response.json(); })
            .then(function (data) {
                lastVersionElement.textContent = '<?php echo osc_esc_js(__('Last checked on ')); ?>' + new Date().toLocaleString();
                setJsMessage('info', data.msg);
            })
            .catch(function (error) { setJsMessage('error', error); });
    });
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
