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
$locationTab  = __get('locationTab') === 'data' ? 'data' : 'browse';
$locationData = __get('locationData');

osc_admin_page(array(
    'section' => __('Listings'),
    'title'   => __('Locations'),
    'help'    => __("Add, edit or delete the countries, regions and cities installed on your Shopclass. "
                    . '<strong>Be careful</strong>: modifying locations can cause your statistics to be incorrect '
                    . "until they're recalculated. Modify only if you're sure what you're doing!"),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => $locations['base'] . '&form=add',
            'title' => __('Add country'),
            'attrs' => array('id' => 'b_import', 'data-loc-form' => ''),
        ),
    ),
));

osc_enqueue_script('admin-location');
osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Locations')); ?>
    <?php $inDrawer = is_array($locationForm) && in_array($locationForm['kind'], array('add', 'edit'), true)
        && ($locationForm['kind'] === 'add' || $locationForm['record'] !== null); ?>
    <div class="locations-app"
         data-ajax="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=ajax'); ?>"
         data-base="<?php echo osc_esc_html($locations['base']); ?>"
         data-tab="<?php echo $locationTab; ?>"
         data-csrf="<?php echo osc_esc_html(osc_csrf_token_url()); ?>"
         data-i18n='<?php echo osc_esc_html(json_encode(array(
             'loading'       => __('Loading…'),
             'loadError'     => __('The list could not be loaded. Check your connection and try again.'),
             'saveError'     => __('Something went wrong. Please try again.'),
             'nothingPicked' => __('Select at least one location to delete.'),
             'noAction'      => __('Choose a bulk action first.'),
             'selected'      => __('Selected: %s'),
             'countsError'   => __('Could not be counted'),
             'slugTaken'     => __('%s already uses this slug. Saving makes one from the name instead.'),
             'searchError'   => __('The search could not be run. Check your connection and try again.'),
             'matches'       => __('Matches: %s'),
             'cities'        => __('Cities'),
             'regions'       => __('Regions'),
             'countries'     => __('Countries'),
             'edit'          => __('Edit'),
             'editName'      => __('Edit %s'),
             'hidden'        => __('Hidden'),
             'nothingTitle'  => __('Nothing named “%s” anywhere'),
             'nothingText'   => __('Names are matched from their first letters.'),
             'clearSearch'   => __('Clear search'),
             'hitsMore'      => sprintf(
                 __('Only the first %s are shown. Type more of the name to narrow it.'),
                 \mindstellar\location\LocationAdminView::HITS_PER_LEVEL
             ),
             'hitsLimit'     => \mindstellar\location\LocationAdminView::HITS_PER_LEVEL,
             'dataLoading'   => __('Reading the location catalog…'),
             'showing'       => __('Showing %1$s of %2$s countries'),
             'noMatch'       => __('No country matches “%s”'),
             'noFilterMatch' => __('No country matches this filter'),
             'serverTimeout' => __('The server stopped waiting before the work finished. Try again; if it keeps happening, ask your host to allow requests of up to five minutes.'),
             'previewBusy'   => __('Checking…'),
             'installBusy'   => __('Installing…'),
             'updateBusy'    => __('Updating…'),
             'longRun'       => __('Reading %s from the catalog. A large country can take a minute or two; keep this page open.'),
             'recalcBusy'    => __('Counting…'),
             'recalcDone'    => __('Listing counts are up to date.'),
             'recalcError'   => __('Counting stopped. Your connection may have dropped; continue to pick up where it stopped.'),
             'recalcAgain'   => __('Continue counting'),
             'recalcStalled' => __('Counting stopped because it was not moving forward. Try again in a few minutes.'),
             'recalcStep'    => __('%1$s%% counted: %2$s of %3$s locations'),
             'offer'         => __('The catalog has %1$s. Regions: %2$s. Cities: %3$s.'),
             'offerPlain'    => __('The catalog has %s, with its regions and cities.'),
             'offerButton'   => __('Import %s instead'),
             'offerInstalled' => __('%s is already installed. Update it from the Data tab.'),
         ), JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
        <nav class="loc-tabs" aria-label="<?php echo osc_esc_html(__('Location views')); ?>">
            <ul class="osc-tabnav">
                <?php foreach (array('browse' => __('Browse'), 'data' => __('Data')) as $tabKey => $tabLabel) {
                    $current = $locationTab === $tabKey; ?>
                    <li>
                        <a href="<?php echo osc_esc_html($locations['base'] . ($tabKey === 'data' ? '&tab=data' : '')); ?>"
                           data-loc-tab="<?php echo $tabKey; ?>"<?php echo $current ? ' class="is-active" aria-current="page"' : ''; ?>>
                            <?php echo osc_esc_html($tabLabel); ?>
                        </a>
                    </li>
                <?php } ?>
            </ul>
        </nav>
        <?php if (is_array($locationForm) && !$inDrawer) { ?>
            <section class="loc-inline-form" aria-label="<?php echo osc_esc_html(__('Location form')); ?>">
                <?php osc_current_admin_theme_path('settings/locations/form.php'); ?>
            </section>
        <?php } ?>
        <div id="loc-list" class="loc-list-region"<?php echo $locationTab === 'data' ? ' hidden' : ''; ?>>
            <?php osc_current_admin_theme_path('settings/locations/list.php'); ?>
        </div>
        <div id="loc-data" class="loc-data-region"<?php echo $locationTab === 'data' ? '' : ' hidden'; ?>>
            <?php if (is_array($locationData)) {
                osc_current_admin_theme_path('settings/locations/data.php');
            } ?>
        </div>
        <p id="loc-announce" class="visually-hidden" aria-live="polite"></p>
        <div class="osc-drawer-backdrop<?php echo $inDrawer ? ' is-open' : ''; ?>" id="loc-drawer-backdrop"<?php echo $inDrawer ? '' : ' hidden'; ?>></div>
        <div class="osc-drawer loc-drawer<?php echo $inDrawer ? ' is-open' : ''; ?>" id="loc-drawer" role="dialog" aria-modal="true"
             aria-labelledby="loc-drawer-title"<?php echo $inDrawer ? '' : ' hidden'; ?>>
            <?php if ($inDrawer) {
                osc_current_admin_theme_path('settings/locations/form.php');
            } ?>
        </div>
        <dialog id="locationModal" class="osc-dialog loc-dialog"
                aria-label="<?php echo osc_esc_html(__('Location form')); ?>"></dialog>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
