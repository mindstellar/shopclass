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

$maintenance = file_exists(osc_base_path() . '.maintenance');

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Maintenance'),
    'help'    => __('Show a "Site in maintenance mode" message to your users while you\'re updating your site or modifying its configuration.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="backup-setting">
    <!-- settings form -->
    <div id="backup-settings">
        <?php osc_admin_page_head(__('Maintenance')); ?>
        <form>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <?php _e("While in maintenance mode, users can't access your website. Useful if you need to "
                                 . "make changes on your website. Use the following button to toggle maintenance mode ON/OFF."); ?>
                        <div class="<?php echo $maintenance ? 'callout-danger' : 'callout-success'; ?>">
                            <?php printf(__('Maintenance mode is: <strong>%s</strong>'),
                                ($maintenance ? __('ON') : __('OFF'))); ?>
                        </div>
                    </div>
                    <div class="form-actions">
                        <input type="button"
                               value="<?php echo($maintenance ? osc_esc_html(__('Disable maintenance mode'))
                                   : osc_esc_html(__('Enable maintenance mode'))); ?>"
                               onclick="window.location.href='<?php echo osc_admin_base_url(true);
                                ?>?page=tools&amp;action=maintenance&amp;mode=<?php
                               echo ($maintenance ? 'off' : 'on') . '&amp;' . osc_csrf_token_url();
?>';" class="btn btn-submit"/>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
