<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\location\LocationAdminView;

/*
 * The Data tab: install and update countries from the catalog, and recalculate listing
 * counts. Served inside the page and alone as ?partial=data; every action is a plain form.
 */
$data = __get('locationData');
if (!is_array($data)) {
    return;
}

$base   = $data['base'];
$rows   = $data['rows'];
$counts = $data['counts'];
$recalc = $data['recalc'];

$cities = static fn (int $n): string => sprintf(_n('%s city', '%s cities', $n), number_format($n));
$states = array(
    'update'    => array('update', __('Update available')),
    'current'   => array('active', __('Up to date')),
    'available' => array('uninstalled', __('Not installed')),
);
$filters = array(
    'all'       => array(__('All'), $counts['all']),
    'installed' => array(__('Installed'), $counts['installed']),
    'update'    => array(__('Updates'), $counts['update']),
    'available' => array(__('Not installed'), $counts['available']),
);

$visible = 0;
foreach ($rows as $row) {
    $visible += LocationAdminView::catalogMatches($row, $data['find'], $data['show']) ? 1 : 0;
}
$releaseText = '';
if ($data['release'] !== '') {
    $releaseText = sprintf(osc_esc_html(__('Catalog release %s')), osc_admin_date($data['release'], true));
}
?>
<div class="loc-data" data-loc-data-url="<?php echo osc_esc_html($data['url']); ?>">
    <?php if (is_array($data['preview'])) { ?>
        <section class="loc-inline-form loc-preview-inline" aria-label="<?php echo osc_esc_html(__('Preview')); ?>">
            <?php osc_current_admin_theme_path('settings/locations/preview.php'); ?>
        </section>
    <?php } ?>

    <section class="loc-section loc-catalog" aria-labelledby="loc-catalog-title">
        <header class="loc-section-head">
            <div class="loc-section-intro">
                <h3 class="loc-section-title" id="loc-catalog-title"><?php _e('Countries from the catalog'); ?></h3>
                <p class="loc-section-text">
                    <?php _e('Install a country with its regions and cities, or bring one you have up to date. Importing never deletes a location that holds listings.'); ?>
                </p>
            </div>
            <?php if ($data['reachable']) { ?>
                <p class="loc-release">
                    <?php if ($releaseText !== '') { ?>
                        <span><?php echo $releaseText; ?></span>
                    <?php } ?>
                    <a href="<?php echo osc_esc_html($data['url'] . '&refresh=1'); ?>" data-loc-data-nav>
                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i><?php _e('Check for updates'); ?>
                    </a>
                </p>
            <?php } ?>
        </header>

        <?php if (!$data['reachable']) { ?>
            <div class="loc-outage" role="status">
                <i class="bi bi-cloud-slash loc-outage-icon" aria-hidden="true"></i>
                <div class="loc-outage-body">
                    <p class="loc-outage-title"><?php _e('The location catalog could not be reached'); ?></p>
                    <p class="loc-outage-text">
                        <?php _e('Your installed locations are not affected. The catalog server may be down, or this site may not be allowed to connect to it. You can still add a country by hand.'); ?>
                    </p>
                    <div class="loc-outage-actions">
                        <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($data['url'] . '&refresh=1'); ?>" data-loc-data-nav>
                            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> <?php _e('Try again'); ?>
                        </a>
                        <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($base . '&form=add'); ?>" data-loc-form>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> <?php _e('Add country'); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php } elseif ($rows === array()) {
            osc_admin_empty(array(
                'icon'  => 'bi-globe2',
                'title' => __('The catalog lists no countries right now'),
                'text'  => __('Check again later, or add a country by hand.'),
            ));
        } else { ?>
            <?php osc_admin_form_open(array(
                'method'     => 'get',
                'page'       => 'settings',
                'action'     => 'locations',
                'fields'     => array('tab' => 'data'),
                'class'      => 'loc-catalog-filter',
                'horizontal' => false,
                'csrf'       => false,
            )); ?>
                <div class="loc-search-box">
                    <label class="visually-hidden" for="loc-find"><?php _e('Filter countries'); ?></label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input class="form-control" id="loc-find" name="find" type="search" autocomplete="off" spellcheck="false"
                           maxlength="<?php echo LocationAdminView::MAX_QUERY; ?>"
                           value="<?php echo osc_esc_html($data['find']); ?>"
                           placeholder="<?php echo osc_esc_html(__('Filter by name or code')); ?>"/>
                </div>
                <fieldset class="loc-segmented">
                    <legend class="visually-hidden"><?php _e('Show'); ?></legend>
                    <?php foreach ($filters as $key => [$label, $count]) {
                        $id = 'loc-show-' . $key; ?>
                        <input type="radio" name="show" id="<?php echo $id; ?>" value="<?php echo $key; ?>"
                            <?php echo $data['show'] === $key ? 'checked' : ''; ?>/>
                        <label for="<?php echo $id; ?>">
                            <?php echo osc_esc_html($label); ?>
                            <span class="loc-segmented-count"><?php echo number_format($count); ?></span>
                        </label>
                    <?php } ?>
                </fieldset>
                <button type="submit" class="btn btn-secondary btn-sm loc-search-submit"><?php _e('Filter'); ?></button>
            <?php osc_admin_form_close(null, array('horizontal' => false)); ?>

            <p class="loc-count" data-loc-catalog-count aria-live="polite">
                <?php echo osc_esc_html(sprintf(__('Showing %1$s of %2$s countries'), number_format($visible), number_format(count($rows)))); ?>
            </p>
            <p class="loc-busy-note" data-loc-busy-note role="status" hidden></p>

            <div class="loc-table-wrap">
                <table class="table loc-catalog-table">
                    <thead>
                    <tr>
                        <th scope="col" class="loc-col-code"><?php _e('Code'); ?></th>
                        <th scope="col"><?php _e('Country'); ?></th>
                        <th scope="col" class="col-numeric loc-col-cities"><?php _e('Cities'); ?></th>
                        <th scope="col"><?php _e('Status'); ?></th>
                        <th scope="col"><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) {
                        [$badge, $word] = $states[$row['state']];
                        $shown          = LocationAdminView::catalogMatches($row, $data['find'], $data['show']); ?>
                        <tr data-code="<?php echo osc_esc_html($row['code']); ?>"
                            data-name="<?php echo osc_esc_html(mb_strtolower($row['name'])); ?>"
                            data-state="<?php echo $row['state']; ?>"<?php echo $shown ? '' : ' hidden'; ?>>
                            <td class="loc-col-code osc-mono"><?php echo osc_esc_html($row['code']); ?></td>
                            <td class="loc-col-country"><?php echo osc_esc_html($row['name']); ?></td>
                            <td class="col-numeric loc-col-cities"><?php echo number_format($row['cities']); ?></td>
                            <td class="loc-col-status"><?php osc_admin_status($badge, $word); ?></td>
                            <td class="loc-col-actions">
                                <?php if ($row['state'] === 'update') { ?>
                                    <button type="submit" class="loc-row-action" form="loc-preview-form" name="location"
                                            value="<?php echo osc_esc_html($row['code']); ?>" data-loc-catalog-action
                                            aria-label="<?php echo osc_esc_html(sprintf(__('Preview the update of %s'), $row['name'])); ?>">
                                        <i class="bi bi-eye" aria-hidden="true"></i><?php _e('Preview'); ?>
                                    </button>
                                    <button type="submit" class="loc-row-action loc-row-action-main" form="loc-import-form" name="location"
                                            value="<?php echo osc_esc_html($row['code']); ?>" data-loc-catalog-action
                                            data-loc-busy="<?php echo osc_esc_html(__('Updating…')); ?>"
                                            aria-label="<?php echo osc_esc_html(sprintf(__('Update %s'), $row['name'])); ?>">
                                        <i class="bi bi-arrow-up-circle" aria-hidden="true"></i><?php _e('Update'); ?>
                                    </button>
                                <?php } elseif ($row['state'] === 'available') { ?>
                                    <button type="submit" class="loc-row-action" form="loc-import-form" name="location"
                                            value="<?php echo osc_esc_html($row['code']); ?>" data-loc-catalog-action
                                            data-loc-busy="<?php echo osc_esc_html(__('Installing…')); ?>"
                                            aria-label="<?php echo osc_esc_html(sprintf(__('Install %1$s (%2$s)'), $row['name'], $cities($row['cities']))); ?>">
                                        <i class="bi bi-download" aria-hidden="true"></i><?php _e('Install'); ?>
                                    </button>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    <tr class="loc-catalog-empty" data-loc-catalog-empty<?php echo $visible === 0 ? '' : ' hidden'; ?>>
                        <td colspan="5">
                            <span data-loc-catalog-empty-text><?php echo osc_esc_html($data['find'] !== ''
                                ? sprintf(__('No country matches “%s”'), $data['find'])
                                : __('No country matches this filter')); ?></span>
                            <a href="<?php echo osc_esc_html($data['url'] . '&show=all' . ($data['find'] !== '' ? '&find=' . rawurlencode($data['find']) : '')); ?>"
                               data-loc-show-all><?php _e('Search all countries'); ?></a>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php foreach (array('loc-import-form' => 'locations_import', 'loc-preview-form' => 'locations_preview') as $formId => $type) {
                osc_admin_form_open(array(
                    'page'       => 'settings',
                    'action'     => 'locations',
                    'id'         => $formId,
                    'fields'     => array('type' => $type, 'tab' => 'data'),
                    'class'      => 'loc-catalog-form',
                    'horizontal' => false,
                ));
                osc_admin_form_close(null, array('horizontal' => false));
            } ?>
        <?php } ?>
    </section>

    <section class="loc-section loc-recalc" aria-labelledby="loc-recalc-title">
        <div class="loc-section-intro">
            <h3 class="loc-section-title" id="loc-recalc-title"><?php _e('Listing counts'); ?></h3>
            <p class="loc-section-text">
                <?php _e('The number of listings shown for each location can drift after an import or a bulk change. Recalculating counts them again; the site stays online meanwhile.'); ?>
            </p>
            <div class="loc-recalc-progress" data-loc-recalc-progress<?php echo $recalc['pending'] > 0 ? '' : ' hidden'; ?>>
                <progress max="<?php echo max(1, $recalc['total']); ?>" value="<?php echo $recalc['done']; ?>"
                          aria-labelledby="loc-recalc-status"></progress>
                <p class="loc-recalc-status" id="loc-recalc-status" aria-live="polite">
                    <?php echo osc_esc_html(sprintf(
                        __('%1$s%% counted: %2$s of %3$s locations'),
                        $recalc['percent'],
                        number_format($recalc['done']),
                        number_format($recalc['total'])
                    )); ?>
                </p>
            </div>
        </div>
        <?php osc_admin_form_open(array(
            'page'       => 'tools',
            'action'     => 'locations_post',
            'fields'     => array('return' => 'locations'),
            'class'      => 'loc-recalc-form',
            'horizontal' => false,
        )); ?>
            <button type="submit" class="btn btn-secondary btn-sm" data-loc-busy="<?php echo osc_esc_html(__('Counting…')); ?>">
                <i class="bi bi-calculator" aria-hidden="true"></i>
                <span><?php echo osc_esc_html($recalc['pending'] > 0 ? __('Continue counting') : __('Recalculate counts')); ?></span>
            </button>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
    </section>
</div>
