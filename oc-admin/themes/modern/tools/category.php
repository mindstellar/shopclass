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
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


osc_admin_page(array(
    'section' => __('Statistics'),
    'title'   => __('Category stats'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="backup-setting">
    <!-- settings form -->
    <div id="backup-settings">
        <?php osc_admin_page_head(__('Category stats')); ?>
        <p>
            <?php _e('You can recalculate your category stats if they are incorrect.'); ?>
        </p>
        <form id="backup_form" name="backup_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="category_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <?php osc_admin_form_actions(array(
                        array('label' => __('Calculate category stats'), 'type' => 'submit', 'variant' => 'primary', 'attrs' => array('id' => 'backup_save')),
                    )); ?>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
