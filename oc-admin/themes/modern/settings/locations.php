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

$locations    = __get('locations');
$locationForm = __get('locationForm');

osc_admin_page(array(
    'section' => __('Listings'),
    'title'   => __('Locations'),
    'help'    => __("Add, edit or delete the countries, regions and cities installed on your Shopclass. "
                    . '<strong>Be careful</strong>: modifying locations can cause your statistics to be incorrect '
                    . "until they're recalculated. Modify only if you're sure what you're doing!"),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => $locations['base'] . '&form=import',
            'title' => __('Import new'),
            'attrs' => array('id' => 'b_import', 'data-loc-form' => ''),
        ),
    ),
));

osc_enqueue_script('admin-location');
osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Locations')); ?>
    <div class="locations-app"
         data-i18n='<?php echo osc_esc_html(json_encode(array(
             'loadError'     => __('The list could not be loaded. Check your connection and try again.'),
             'saveError'     => __('Something went wrong. Please try again.'),
             'nothingPicked' => __('Select at least one location to delete.'),
             'noAction'      => __('Choose a bulk action first.'),
         ), JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
        <?php if (is_array($locationForm)) { ?>
            <section class="loc-inline-form" aria-label="<?php echo osc_esc_html(__('Location form')); ?>">
                <?php osc_current_admin_theme_path('settings/locations/form.php'); ?>
            </section>
        <?php } ?>
        <div id="loc-list" class="loc-list-region">
            <?php osc_current_admin_theme_path('settings/locations/list.php'); ?>
        </div>
        <p id="loc-announce" class="visually-hidden" aria-live="polite"></p>
        <dialog id="locationModal" class="osc-dialog loc-dialog"
                aria-label="<?php echo osc_esc_html(__('Location form')); ?>"></dialog>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
