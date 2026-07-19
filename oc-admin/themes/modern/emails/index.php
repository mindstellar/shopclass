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

function addHelp()
{
    echo '<p>'
         . __("Modify the emails your site's users receive when they join your site,"
              . " when someone shows interest in their ad, to recover their password... "
              . "<strong>Be careful</strong>: don't modify any of the words that appear within brackets.")
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Email templates &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$aData = __get('aEmails');

osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Emails templates'); ?></h2>
    <div id="email-templates" class="table-contains-actions">
        <table class="table" cellpadding="0" cellspacing="0">
            <thead>
            <tr>
                <th class="col-name"><?php _e('Name'); ?></th>
                <th class="col-title"><?php _e('Title'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($aData['aaData']) > 0) { ?>
                <?php foreach ($aData['aaData'] as $array) { ?>
                    <tr>
                        <?php foreach ($array as $key => $value) { ?>
                            <td data-col-name="<?php echo ucfirst($key); ?>">
                                <?php echo $value; ?>
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            <?php } else { ?>
                <tr>
                    <td colspan="6" class="text-center">
                        <p><?php _e('No data available in table'); ?></p>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <div id="table-row-actions"></div> <!-- used for table actions -->
    </div>
<?php
osc_show_pagination_admin($aData);
?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>