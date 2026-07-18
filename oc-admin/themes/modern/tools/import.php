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


osc_add_hook('admin_page_header', 'customPageHeader');

function addHelp()
{
    /* xgettext:no-php-format */
    echo '<p>'
         . __("Upload registers from other Shopclass installations or upload new geographic information to your site. "
              . "<strong>Be careful</strong>: don’t use this option if you're not 100% sure what you're doing.")
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

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
    return sprintf(__('Import &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<!-- settings form -->
<div id="backup-settings">
    <h2 class="render-title"><?php _e('Import'); ?></h2>
    <form id="backup_form" name="backup_form" action="<?php echo osc_admin_base_url(true); ?>"
          enctype="multipart/form-data" method="post">
        <input type="hidden" name="page" value="tools"/>
        <input type="hidden" name="action" value="import_post"/>
        <fieldset>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('File (.sql)'); ?></div>
                    <div class="form-controls">
                        <input type="file" name="sql" id="sql"/>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Import data')); ?></button>
                </div>
            </div>
        </fieldset>
    </form>
</div>
<!-- /settings form -->
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
