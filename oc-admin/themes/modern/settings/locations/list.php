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

use mindstellar\location\LocationAdminView;

/*
 * One level of the location tree: path, search, the rows of one page and the pager, or the
 * everywhere-search hits. Served inside the page and alone as ?partial=list, so every link
 * is a plain GET URL.
 */
$loc   = __get('locations');
$level = $loc['level'];
$base  = $loc['base'];
$url   = static fn (array $params = array()): string => $base . ($params === array() ? '' : '&' . http_build_query(
    array_filter($params, static fn ($v): bool => $v !== '' && $v !== null && $v !== 0)
));

$q           = $loc['q'];
$scope       = $loc['scope'];
$countryCode = $loc['country']['code'] ?? '';
$regionId    = $loc['region']['id'] ?? 0;
$parentName  = $level === 'city' ? ($loc['region']['name'] ?? '') : ($loc['country']['name'] ?? '');
$here        = array('country' => $countryCode, 'region' => $regionId);
$view        = $here + array(
    'q'       => $scope === 'level' ? $q : '',
    'pageNum' => $loc['page'] > 1 ? $loc['page'] : null,
);

$nouns = array(
    'country' => static fn (int $n): string => sprintf(_n('%s country', '%s countries', $n), number_format($n)),
    'region'  => static fn (int $n): string => sprintf(_n('%s region', '%s regions', $n), number_format($n)),
    'city'    => static fn (int $n): string => sprintf(_n('%s city', '%s cities', $n), number_format($n)),
);
$addLabels = array('country' => __('Add country'), 'region' => __('Add region'), 'city' => __('Add city'));

if (!$loc['found']) {
    $summary = __('Location not found');
} elseif ($scope === 'all') {
    $hitCount = count($loc['hits']['countries']) + count($loc['hits']['regions']) + count($loc['hits']['cities']);
    $summary  = sprintf(__('Matches: %s'), number_format($hitCount));
} else {
    $total   = (int) $loc['total'];
    $counted = $q === '' ? $nouns[$level]($total) : sprintf(array(
        'country' => _n('%1$s country starting with “%2$s”', '%1$s countries starting with “%2$s”', $total),
        'region'  => _n('%1$s region starting with “%2$s”', '%1$s regions starting with “%2$s”', $total),
        'city'    => _n('%1$s city starting with “%2$s”', '%1$s cities starting with “%2$s”', $total),
    )[$level], number_format($total), $q);
    $first   = $loc['total'] === 0 ? 0 : (($loc['page'] - 1) * $loc['per']) + 1;
    $last    = min($loc['total'], $loc['page'] * $loc['per']);
    $summary = $loc['total'] > $loc['per']
        ? sprintf(__('Showing %1$s–%2$s of %3$s'), number_format($first), number_format($last), $counted)
        : $counted;
}

$placeholders = array(
    'country' => __('Search countries'),
    'region'  => sprintf(__('Search regions in %s'), $parentName),
    'city'    => sprintf(__('Search cities in %s'), $parentName),
);
$showSearch = $loc['found'] && ($loc['levelTotal'] > 0 || $scope === 'all');
?>
<div class="loc-list" data-loc-summary="<?php echo osc_esc_html($summary); ?>" data-loc-level="<?php echo osc_esc_html($level); ?>">
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
                    'variant' => $level === 'country' && $loc['levelTotal'] === 0 ? 'secondary' : 'primary',
                    'attrs'   => array('data-loc-form' => ''),
                )); ?>
            </div>
        <?php } ?>
    </div>

    <?php if ($showSearch) { ?>
        <div class="loc-toolbar">
            <?php osc_admin_form_open(array(
                'method'     => 'get',
                'page'       => 'settings',
                'action'     => 'locations',
                'fields'     => $here,
                'class'      => 'loc-search',
                'horizontal' => false,
                'csrf'       => false,
            )); ?>
                <div class="loc-search-box">
                    <label class="visually-hidden" for="loc-q"><?php echo osc_esc_html($placeholders[$level]); ?></label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input class="form-control" id="loc-q" name="q" type="search" autocomplete="off" spellcheck="false"
                           maxlength="<?php echo LocationAdminView::MAX_QUERY; ?>" aria-keyshortcuts="/"
                           value="<?php echo osc_esc_html($q); ?>"
                           placeholder="<?php echo osc_esc_html($scope === 'all' ? __('Search all locations') : $placeholders[$level]); ?>"
                           data-loc-placeholder-level="<?php echo osc_esc_html($placeholders[$level]); ?>"
                           data-loc-placeholder-all="<?php echo osc_esc_html(__('Search all locations')); ?>"/>
                    <kbd class="loc-search-key" aria-hidden="true">/</kbd>
                </div>
                <fieldset class="loc-scope">
                    <legend class="visually-hidden"><?php _e('Search in'); ?></legend>
                    <input type="radio" name="scope" id="loc-scope-level" value="" <?php echo $scope === 'level' ? 'checked' : ''; ?>/>
                    <label for="loc-scope-level"><?php _e('This level'); ?></label>
                    <input type="radio" name="scope" id="loc-scope-all" value="all" <?php echo $scope === 'all' ? 'checked' : ''; ?>/>
                    <label for="loc-scope-all"><?php _e('Everywhere'); ?></label>
                </fieldset>
                <button type="submit" class="btn btn-secondary btn-sm loc-search-submit"><?php _e('Search'); ?></button>
            <?php osc_admin_form_close(null, array('horizontal' => false)); ?>

            <?php if ($loc['initials'] !== null) {
                $letter = mb_strlen($q) === 1 ? mb_strtoupper($q) : ''; ?>
                <nav class="loc-az" aria-label="<?php echo osc_esc_html(__('Names starting with')); ?>">
                    <ol>
                        <li><a href="<?php echo osc_esc_html($url($here)); ?>" data-loc-nav
                               <?php echo $q === '' ? 'aria-current="true"' : ''; ?>><?php _e('All'); ?></a></li>
                        <?php foreach ($loc['initials'] as $char) { ?>
                            <li><a href="<?php echo osc_esc_html($url($here + array('q' => $char))); ?>" data-loc-nav
                                   <?php echo $letter === $char ? 'aria-current="true"' : ''; ?>><?php echo osc_esc_html($char); ?></a></li>
                        <?php } ?>
                    </ol>
                </nav>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="loc-body">
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
    } elseif ($scope === 'all') {
        $groups = array(
            'city'    => array(__('Cities'), $loc['hits']['cities'], $loc['hitsMore']['cities']),
            'region'  => array(__('Regions'), $loc['hits']['regions'], $loc['hitsMore']['regions']),
            'country' => array(__('Countries'), $loc['hits']['countries'], $loc['hitsMore']['countries']),
        ); ?>
        <div class="loc-results">
            <?php if ($hitCount === 0) {
                osc_admin_empty(array(
                    'icon'   => 'bi-search',
                    'title'  => sprintf(__('Nothing named “%s” anywhere'), $q),
                    'text'   => __('Names are matched from their first letters.'),
                    'action' => array(
                        'label' => __('Clear search'),
                        'url'   => $url($here),
                        'attrs' => array('data-loc-nav' => ''),
                    ),
                ));
            }
            foreach ($groups as $hitLevel => [$heading, $hits, $more]) {
                if ($hits === array()) {
                    continue;
                } ?>
                <section class="loc-hits" aria-labelledby="loc-hits-<?php echo $hitLevel; ?>">
                    <h3 class="loc-hits-title" id="loc-hits-<?php echo $hitLevel; ?>"><?php echo osc_esc_html($heading); ?></h3>
                    <ul class="loc-hits-list">
                        <?php foreach ($hits as $hit) {
                            $links = LocationAdminView::hitLinks($hitLevel, $hit); ?>
                            <li class="loc-hit">
                                <span class="loc-hit-main">
                                    <?php if ($links['open'] !== null) { ?>
                                        <a class="loc-hit-name" href="<?php echo osc_esc_html($url($links['open'])); ?>" data-loc-nav>
                                            <?php echo osc_esc_html($hit['name']); ?>
                                        </a>
                                    <?php } else { ?>
                                        <span class="loc-hit-name"><?php echo osc_esc_html($hit['name']); ?></span>
                                    <?php } ?>
                                    <span class="loc-hit-path<?php echo $hitLevel === 'country' ? ' osc-mono' : ''; ?>">
                                        <?php echo osc_esc_html($hitLevel === 'country' ? $hit['code'] : implode(' › ', $links['path'])); ?>
                                    </span>
                                    <?php if (isset($hit['active']) && !$hit['active']) {
                                        osc_admin_status('inactive', __('Hidden'));
                                    } ?>
                                </span>
                                <?php if ($links['edit'] !== null) { ?>
                                    <a class="loc-edit" href="<?php echo osc_esc_html($url($links['edit'])); ?>" data-loc-form
                                       aria-label="<?php echo osc_esc_html(sprintf(__('Edit %s'), $hit['name'])); ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i><?php _e('Edit'); ?>
                                    </a>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>
                    <?php if ($more) { ?>
                        <p class="loc-hits-more">
                            <?php echo osc_esc_html(sprintf(__('Only the first %s are shown. Type more of the name to narrow it.'), LocationAdminView::HITS_PER_LEVEL)); ?>
                        </p>
                    <?php } ?>
                </section>
            <?php } ?>
        </div>
    <?php } elseif ($level === 'country' && $loc['levelTotal'] === 0) {
        osc_admin_empty(array(
            'icon'   => 'bi-globe2',
            'title'  => __('No locations yet'),
            'text'   => __('Install a country from the catalog, or add one by hand.'),
            'action' => array(
                'label'   => __('Install a country'),
                'url'     => $url(array('tab' => 'data')),
                'icon'    => 'bi-download',
                'variant' => 'primary',
                'attrs'   => array('data-loc-tab' => 'data'),
            ),
        ));
    } elseif ($loc['rows'] === array() && $q !== '') {
        osc_admin_empty(array(
            'icon'   => 'bi-search',
            'title'  => $level === 'country'
                ? sprintf(__('No country named “%s”'), $q)
                : sprintf(__('Nothing named “%1$s” in %2$s'), $q, $parentName),
            'text'   => __('Names are matched from their first letters.'),
            'action' => array(
                'label' => __('Search everywhere'),
                'url'   => $url($here + array('q' => $q, 'scope' => 'all')),
                'icon'  => 'bi-search',
                'attrs' => array('data-loc-nav' => '', 'data-loc-scope-all' => ''),
            ),
        ));
    } else {
        $hasRows = $loc['rows'] !== array();
        $colspan = $level === 'region' ? 6 : 5;
        osc_admin_form_open(array(
            'method'     => 'get',
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $view,
            'class'      => 'loc-bulk-form',
            'horizontal' => false,
            'csrf'       => false,
        )); ?>
            <div class="loc-tablebar">
                <p class="loc-count"><?php echo osc_esc_html($summary); ?></p>
                <?php if ($hasRows) { ?>
                    <div class="loc-bulk" data-loc-bulk>
                        <span class="loc-selected" data-loc-selected></span>
                        <?php osc_admin_bulk_actions(array('options_html' => static function () { ?>
                            <select id="bulk_actions" name="form" class="select-box-extra form-select">
                                <option value=""><?php _e('Bulk actions'); ?></option>
                                <option value="delete"><?php _e('Delete'); ?></option>
                            </select>
                        <?php })); ?>
                    </div>
                <?php } ?>
            </div>
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
                        if ($level === 'country') {
                            $drill = $url(array('country' => $row['code']));
                            $meta  = $row['code'] . ($row['slug'] !== '' ? ' · ' . $row['slug'] : '');
                        } elseif ($level === 'region') {
                            $drill = $url(array('country' => $countryCode, 'region' => $row['id']));
                            $meta  = $row['slug'];
                        } else {
                            $drill = null;
                            $meta  = $row['slug'];
                        }
                        $edit    = '<a class="loc-edit" href="' . osc_esc_html($url($view + array('form' => 'edit', 'id' => $id)))
                            . '" data-loc-form aria-label="' . osc_esc_html(sprintf(__('Edit %s'), $name)) . '">'
                            . '<i class="bi bi-pencil" aria-hidden="true"></i>' . osc_esc_html(__('Edit')) . '</a>';
                        $actions = osc_apply_filter('admin_locations_row_actions', array('edit' => $edit), $level, $row); ?>
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
                                <?php echo is_array($actions) ? implode('', $actions) : $edit; ?>
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
            'params'   => array_filter($here + array('q' => $q)),
        ));
    } ?>
    </div>
</div>
