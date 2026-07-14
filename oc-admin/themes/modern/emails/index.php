<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
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
            <tr class="table-secondary">
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