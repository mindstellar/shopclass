<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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

$form = __get('item_form');

osc_admin_page(array(
    'section' => __('Listings'),
    'title'   => __('Listing Settings'),
    'help'    => __('Modify the general settings for your listings. Decide if users have to register in order to publish something, the number of pictures allowed for each listing, etc.'),
));

//customize Head
/**
 * Emit the listing-settings script: the moderation toggle shows and resets the moderated-listing count.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var moderate = document.querySelector('input[name="moderate_items"]');
            function setRows(display) {
                document.querySelectorAll('.num-moderated-items').forEach(function (el) { el.style.display = display; });
            }
            if (moderate) {
                moderate.addEventListener('change', function () {
                    if (moderate.checked) {
                        setRows('');
                        var num = document.querySelector('input[name="num_moderate_items"]');
                        if (num) { num.value = 0; }
                    } else {
                        var lv = document.querySelector('input[name="logged_user_item_validation"]');
                        if (lv) { lv.checked = false; }
                        setRows('none');
                    }
                });
                if (!moderate.checked) {
                    setRows('none');
                }
            }
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-setting">
    <div id="item-settings">
        <?php osc_admin_settings_form($form['id'], $form); ?>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
