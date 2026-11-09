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

    <?php osc_admin_section(__('Countries from the catalog'), array(
        'class' => 'loc-section loc-catalog',
        'intro' => __('Install a country with its regions and cities, or bring one you have up to date. Importing never deletes a location that holds listings.'),
        'attrs' => array('aria-label' => __('Countries from the catalog')),
    )); ?>
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
                            <?php echo osc_admin_count((int)$count); ?>
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
                <?php
                $catalogActions = static function (array $row) use ($cities): void {
                    if ($row['state'] === 'update') { ?>
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
                    <?php }
                };

                $catalogRows = array();
                foreach ($rows as $row) {
                    $catalogRows[] = array(
                        'attrs' => array(
                            'data-code'  => $row['code'],
                            'data-name'  => mb_strtolower($row['name']),
                            'data-state' => $row['state'],
                            'hidden'     => !LocationAdminView::catalogMatches($row, $data['find'], $data['show']),
                        ),
                        'cells' => array(
                            'status'  => array('status' => $states[$row['state']], 'class' => 'loc-col-status'),
                            'code'    => $row['code'],
                            'country' => $row['name'],
                            'cities'  => number_format($row['cities']),
                            'actions' => array('render' => static fn () => $catalogActions($row)),
                        ),
                    );
                }
                $catalogRows[] = array(
                    'class' => 'loc-catalog-empty',
                    'attrs' => array('data-loc-catalog-empty' => true, 'hidden' => $visible !== 0),
                    'cells' => array('status' => array('colspan' => 5, 'render' => static function () use ($data): void { ?>
                        <span data-loc-catalog-empty-text><?php echo osc_esc_html($data['find'] !== ''
                            ? sprintf(__('No country matches “%s”'), $data['find'])
                            : __('No country matches this filter')); ?></span>
                        <a href="<?php echo osc_esc_html($data['url'] . '&show=all' . ($data['find'] !== '' ? '&find=' . rawurlencode($data['find']) : '')); ?>"
                           data-loc-show-all><?php _e('Search all countries'); ?></a>
                    <?php })),
                );

                osc_admin_table(array(
                    'status'  => array('label' => __('Status'), 'col_name' => false),
                    'code'    => array('label' => __('Code'), 'class' => 'loc-col-code', 'td_class' => 'osc-mono', 'col_name' => false),
                    'country' => array('label' => __('Country'), 'td_class' => 'loc-col-country', 'col_name' => false),
                    'cities'  => array('label' => __('Cities'), 'numeric' => true, 'class' => 'loc-col-cities', 'col_name' => false),
                    'actions' => array('label' => __('Actions'), 'hide_label' => true, 'td_class' => 'loc-col-actions', 'col_name' => false),
                ), $catalogRows, array('class' => 'loc-catalog-table', 'wrap' => false));
                ?>
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
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('Listing counts'), array(
        'class' => 'loc-section loc-recalc',
        'intro' => __('The number of listings shown for each location can drift after an import or a bulk change. Recalculating counts them again; the site stays online meanwhile.'),
        'attrs' => array('aria-label' => __('Listing counts')),
    )); ?>
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
    <?php osc_admin_section_close(); ?>
</div>
