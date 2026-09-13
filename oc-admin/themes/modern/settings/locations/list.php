<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * One level of the location tree: path, add action, the rows of one page and the pager.
 * Served inside the page and alone as ?partial=list, so every link is a plain GET URL.
 */
$loc   = __get('locations');
$level = $loc['level'];
$base  = $loc['base'];
$url   = static fn (array $params = array()): string => $base . ($params === array() ? '' : '&' . http_build_query(
    array_filter($params, static fn ($v): bool => $v !== '' && $v !== null && $v !== 0)
));

$countryCode = $loc['country']['code'] ?? '';
$regionId    = $loc['region']['id'] ?? 0;
$view        = array(
    'country' => $countryCode,
    'region'  => $regionId,
    'pageNum' => $loc['page'] > 1 ? $loc['page'] : null,
);

$nouns = array(
    'country' => static fn (int $n): string => sprintf(_n('%s country', '%s countries', $n), number_format($n)),
    'region'  => static fn (int $n): string => sprintf(_n('%s region', '%s regions', $n), number_format($n)),
    'city'    => static fn (int $n): string => sprintf(_n('%s city', '%s cities', $n), number_format($n)),
);
$addLabels = array('country' => __('Add country'), 'region' => __('Add region'), 'city' => __('Add city'));

if ($loc['found']) {
    $first   = $loc['total'] === 0 ? 0 : (($loc['page'] - 1) * $loc['per']) + 1;
    $last    = min($loc['total'], $loc['page'] * $loc['per']);
    $summary = $loc['total'] > $loc['per']
        ? sprintf(__('Showing %1$s–%2$s of %3$s'), number_format($first), number_format($last), $nouns[$level]($loc['total']))
        : $nouns[$level]($loc['total']);
} else {
    $summary = __('Location not found');
}
?>
<div class="loc-list" data-loc-summary="<?php echo osc_esc_html($summary); ?>">
    <div class="loc-head">
        <nav class="loc-path" aria-label="<?php echo osc_esc_html(__('Location path')); ?>">
            <ol>
                <?php if ($level === 'country') { ?>
                    <li><span aria-current="page"><?php _e('All countries'); ?></span></li>
                <?php } else { ?>
                    <li><a href="<?php echo osc_esc_html($url()); ?>" data-loc-nav><?php _e('All countries'); ?></a></li>
                <?php } ?>
                <?php if ($level === 'region' && $loc['found']) { ?>
                    <li><span aria-current="page"><?php echo osc_esc_html($loc['country']['name']); ?></span></li>
                <?php } elseif ($level === 'city' && $loc['found']) { ?>
                    <?php if ($loc['country'] !== null) { ?>
                        <li><a href="<?php echo osc_esc_html($url(array('country' => $countryCode))); ?>"
                               data-loc-nav><?php echo osc_esc_html($loc['country']['name']); ?></a></li>
                    <?php } ?>
                    <li><span aria-current="page"><?php echo osc_esc_html($loc['region']['name']); ?></span></li>
                <?php } ?>
            </ol>
        </nav>
        <?php if ($loc['found']) { ?>
            <div class="loc-head-actions">
                <?php osc_admin_action_button(array(
                    'label'   => $addLabels[$level],
                    'url'     => $url($view + array('form' => 'add')),
                    'icon'    => 'bi-plus-lg',
                    'variant' => $level === 'country' && $loc['total'] === 0 ? 'secondary' : 'primary',
                    'attrs'   => array('data-loc-form' => ''),
                )); ?>
            </div>
        <?php } ?>
    </div>

    <?php if (!$loc['found']) {
        osc_admin_empty(array(
            'icon'   => 'bi-geo-alt',
            'title'  => $loc['missing']['level'] === 'region' ? __('Region not found') : __('Country not found'),
            'text'   => $loc['missing']['level'] === 'region'
                ? sprintf(__('No region has the id %s. It may have been deleted.'), $loc['missing']['id'])
                : sprintf(__('No country has the code %s. It may have been deleted.'), $loc['missing']['id']),
            'action' => array(
                'label' => __('Back to all countries'),
                'url'   => $url(),
                'attrs' => array('data-loc-nav' => ''),
            ),
        ));
    } elseif ($level === 'country' && $loc['total'] === 0) {
        osc_admin_empty(array(
            'icon'   => 'bi-globe2',
            'title'  => __('No locations yet'),
            'text'   => __('Install a country from the catalog, or add one by hand.'),
            'action' => array(
                'label'   => __('Install a country'),
                'url'     => $url(array('form' => 'import')),
                'icon'    => 'bi-download',
                'variant' => 'primary',
                'attrs'   => array('data-loc-form' => ''),
            ),
        ));
    } else {
        $hasRows = $loc['rows'] !== array();
        $colspan = $level === 'country' ? 5 : ($level === 'region' ? 6 : 5);
        osc_admin_form_open(array(
            'method'     => 'get',
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $view,
            'class'      => 'loc-bulk-form',
            'horizontal' => false,
            'csrf'       => false,
        )); ?>
            <?php if ($hasRows) { ?>
                <div class="osc-toolbar osc-toolbar-between loc-toolbar">
                    <?php osc_admin_bulk_actions(array('options_html' => static function () { ?>
                        <select id="bulk_actions" name="form" class="select-box-extra form-select">
                            <option value=""><?php _e('Bulk actions'); ?></option>
                            <option value="delete"><?php _e('Delete'); ?></option>
                        </select>
                    <?php })); ?>
                    <p class="loc-count"><?php echo osc_esc_html($summary); ?></p>
                </div>
            <?php } ?>
            <div class="loc-table-wrap">
                <table class="table loc-table loc-table-<?php echo osc_esc_html($level); ?>">
                    <thead>
                    <tr>
                        <th class="col-bulkactions">
                            <?php if ($hasRows) { ?>
                                <input id="check_all" type="checkbox"
                                       aria-label="<?php echo osc_esc_html(__('Select all on this page')); ?>"/>
                            <?php } ?>
                        </th>
                        <th scope="col"><?php _e('Name'); ?></th>
                        <?php if ($level === 'country') { ?>
                            <th scope="col" class="col-numeric"><?php _e('Regions'); ?></th>
                        <?php } elseif ($level === 'region') { ?>
                            <th scope="col" class="col-numeric"><?php _e('Cities'); ?></th>
                        <?php } ?>
                        <th scope="col" class="col-numeric"><?php _e('Listings'); ?></th>
                        <?php if ($level !== 'country') { ?>
                            <th scope="col"><?php _e('Status'); ?></th>
                        <?php } ?>
                        <th scope="col"><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$hasRows) {
                        $parentName = $level === 'city' ? $loc['region']['name'] : $loc['country']['name'];
                        osc_admin_table_empty($colspan, array(
                            'icon'   => 'bi-geo-alt',
                            'title'  => $level === 'city'
                                ? sprintf(__('No cities in %s yet'), $parentName)
                                : sprintf(__('No regions in %s yet'), $parentName),
                            'action' => array(
                                'label' => $addLabels[$level],
                                'url'   => $url($view + array('form' => 'add')),
                                'icon'  => 'bi-plus-lg',
                                'attrs' => array('data-loc-form' => ''),
                            ),
                        ));
                    } ?>
                    <?php foreach ($loc['rows'] as $row) {
                        $id   = $level === 'country' ? $row['code'] : $row['id'];
                        $name = $row['name'];
                        $edit = $url($view + array('form' => 'edit', 'id' => $id));
                        if ($level === 'country') {
                            $drill = $url(array('country' => $row['code']));
                            $meta  = $row['code'] . ($row['slug'] !== '' ? ' · ' . $row['slug'] : '');
                        } elseif ($level === 'region') {
                            $drill = $url(array('country' => $countryCode, 'region' => $row['id']));
                            $meta  = $row['slug'];
                        } else {
                            $drill = null;
                            $meta  = $row['slug'];
                        } ?>
                        <tr>
                            <td class="col-bulkactions">
                                <input type="checkbox" name="id[]" value="<?php echo osc_esc_html($id); ?>"
                                       aria-label="<?php echo osc_esc_html(sprintf(__('Select %s'), $name)); ?>"/>
                            </td>
                            <td class="loc-col-name">
                                <?php if ($drill !== null) { ?>
                                    <a class="loc-name" href="<?php echo osc_esc_html($drill); ?>" data-loc-nav>
                                        <?php echo osc_esc_html($name); ?><i class="bi bi-chevron-right loc-drill" aria-hidden="true"></i>
                                    </a>
                                <?php } else { ?>
                                    <span class="loc-name"><?php echo osc_esc_html($name); ?></span>
                                <?php } ?>
                                <?php if ($meta !== '') { ?>
                                    <span class="loc-slug osc-mono"><?php echo osc_esc_html($meta); ?></span>
                                <?php } ?>
                            </td>
                            <?php if ($level === 'country') { ?>
                                <td class="col-numeric loc-col-count" data-col-name="<?php echo osc_esc_html(__('Regions')); ?>">
                                    <?php echo number_format((int) $row['regions']); ?>
                                </td>
                            <?php } elseif ($level === 'region') { ?>
                                <td class="col-numeric loc-col-count" data-col-name="<?php echo osc_esc_html(__('Cities')); ?>">
                                    <?php echo number_format((int) $row['cities']); ?>
                                </td>
                            <?php } ?>
                            <td class="col-numeric loc-col-count" data-col-name="<?php echo osc_esc_html(__('Listings')); ?>">
                                <?php echo number_format((int) $row['listings']); ?>
                            </td>
                            <?php if ($level !== 'country') { ?>
                                <td class="loc-col-status">
                                    <?php $row['active']
                                        ? osc_admin_status('active', __('Active'))
                                        : osc_admin_status('inactive', __('Hidden')); ?>
                                </td>
                            <?php } ?>
                            <td class="loc-col-actions">
                                <a class="loc-edit" href="<?php echo osc_esc_html($edit); ?>" data-loc-form
                                   aria-label="<?php echo osc_esc_html(sprintf(__('Edit %s'), $name)); ?>">
                                    <i class="bi bi-pencil" aria-hidden="true"></i><?php _e('Edit'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php osc_admin_form_close(null, array('horizontal' => false));

        osc_admin_pager(array(
            'total'    => $loc['total'],
            'per_page' => $loc['per'],
            'page'     => $loc['page'],
            'base_url' => $base,
            'params'   => array_filter(array('country' => $countryCode, 'region' => $regionId)),
        ));
    } ?>
</div>
