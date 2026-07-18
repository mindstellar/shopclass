<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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

$maintenance = file_exists(osc_base_path() . '.maintenance');

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


function addHelp()
{
    echo '<p>'
         . __('Show a "Site in maintenance mode" message to your users while you\'re updating your site or modifying its configuration.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Tools'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Maintenance &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="backup-setting">
    <!-- settings form -->
    <div id="backup-settings">
        <h2 class="render-title"><?php _e('Maintenance'); ?></h2>
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
