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
 * The chrome around the declared comment-settings form. The form itself -- its route, its
 * fields, their values and the submit row -- is core's, drawn from the declaration the
 * controller saves through.
 */

$form = __get('comment_form');

//customize Head
/**
 * Emit the comment-settings form's client-side validation rules.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            oscValidateForm(document.querySelector('form[name=comments_form]'), {
                rules: {
                    num_moderate_comments: { required: true, digits: true },
                    comments_per_page: { required: true, digits: true }
                },
                messages: {
                    num_moderate_comments: {
                        required: '<?php echo osc_esc_js(__('Moderated comments: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Moderated comments: this field must only contain numeric characters')); ?>.'
                    },
                    comments_per_page: {
                        required: '<?php echo osc_esc_js(__('Comments per page: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Comments per page: this field must only contain numeric characters')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });

            var moderate = document.querySelector('input[name="moderate_comments"]');
            function setApproved(display) {
                document.querySelectorAll('.comments_approved').forEach(function (el) { el.style.display = display; });
            }
            if (moderate) {
                if (!moderate.checked) { setApproved('none'); }
                moderate.addEventListener('change', function () {
                    setApproved(moderate.checked ? '' : 'none');
                });
            }
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Comment Settings'),
    'help'    => __("Modify the options that allow your users to publish comments on your site's listings."),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-settings">
    <ul id="error_list"></ul>
    <?php osc_admin_settings_form($form['id'], $form); ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
