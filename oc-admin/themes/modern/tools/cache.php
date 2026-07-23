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

// The object cache is the only cache Shopclass keeps: a driver chosen by the
// OSC_CACHE constant, defaulting to an in-request array. Each driver reports
// whether this server can run it, so the table below is the honest picture of
// what is available here, not a list of what exists in the code.
$drivers = array(
    'default'    => array(
        'label'     => __('In-request only (default)'),
        'persists'  => false,
        'note'      => __('Holds values for the length of a single request and is thrown away when it ends. Nothing carries over between page loads, so there is nothing to clear.'),
    ),
    'apcu'       => array(
        'label'     => 'APCu',
        'persists'  => true,
        'note'      => __('Keeps values in shared memory between requests. Fast, and local to this server.'),
    ),
    'apc'        => array(
        'label'     => 'APC',
        'persists'  => true,
        'note'      => __('The predecessor to APCu, on older PHP builds.'),
    ),
    'memcached'  => array(
        'label'     => 'Memcached',
        'persists'  => true,
        'note'      => __('Keeps values on a memcached server, which can be shared by several web servers.'),
    ),
    'memcache'   => array(
        'label'     => 'Memcache',
        'persists'  => true,
        'note'      => __('The older memcache extension.'),
    ),
);

$activeDriver = defined('OSC_CACHE') ? (string)OSC_CACHE : 'default';
if (!isset($drivers[$activeDriver])) {
    // A constant naming a driver we have no class for: report it rather than
    // silently pretending the default is running.
    $drivers[$activeDriver] = array(
        'label'    => $activeDriver,
        'persists' => true,
        'note'     => __('Configured through the OSC_CACHE constant.'),
    );
}
$active     = $drivers[$activeDriver];
$persistent = !empty($active['persists']);

function cacheDriverSupported($driver)
{
    $class = 'Object_Cache_' . $driver;

    return class_exists($class) && method_exists($class, 'is_supported') && $class::is_supported();
}

function customPageHeader()
{
    ?>
    <h1><?php _e('Tools'); ?></h1>
    <?php
}

osc_add_hook('admin_page_header', 'customPageHeader');

function customPageTitle($string)
{
    return sprintf(__('Cache &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php');
?>
    <h2 class="render-title"><?php _e('Cache'); ?></h2>

    <div class="relative">
        <div id="listing-toolbar">
            <div class="d-flex justify-content-end gap-1">
                <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
                    <input type="hidden" name="page" value="tools" />
                    <input type="hidden" name="action" value="cache_clear" />
                    <?php echo osc_csrf_token_form(); ?>
                    <button type="submit" class="btn btn-sm btn-dim"<?php echo $persistent ? '' : ' disabled'; ?>
                        <?php echo $persistent ? '' : 'title="' . osc_esc_html(__('The current cache holds nothing between requests, so there is nothing to clear.')) . '"'; ?>><?php
                        _e('Clear cache'); ?></button>
                </form>
            </div>
        </div>

        <p class="col-hint"><?php printf(
            __('Shopclass caches with %s. A cache only ever holds copies — clearing it is safe, and the site rebuilds what it needs on the next request.'),
            '<strong>' . osc_esc_html($active['label']) . '</strong>'
        ); ?></p>

        <?php
        $stats = osc_cache_stats();
        if (is_array($stats)) {
            $hits   = $stats['hits'];
            $misses = $stats['misses'];
            $total  = (int)$hits + (int)$misses;
            $rate   = ($hits !== null && $misses !== null && $total > 0)
                ? round(($hits / $total) * 100, 1) . '%'
                : null;
            $fmtBytes = static function ($b) {
                if ($b === null) {
                    return null;
                }
                $units = array('B', 'KB', 'MB', 'GB');
                $i     = 0;
                $b     = (float)$b;
                while ($b >= 1024 && $i < count($units) - 1) {
                    $b /= 1024;
                    $i++;
                }

                return round($b, $i === 0 ? 0 : 1) . ' ' . $units[$i];
            };
            $fmtUptime = static function ($s) {
                if ($s === null) {
                    return null;
                }
                $d = (int)floor($s / 86400);
                $h = (int)floor(($s % 86400) / 3600);
                $m = (int)floor(($s % 3600) / 60);

                return $d > 0 ? sprintf('%dd %dh', $d, $h) : ($h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m));
            };
            $memory = $fmtBytes($stats['memory_used']);
            if ($memory !== null && $stats['memory_total'] !== null) {
                $memory .= ' / ' . $fmtBytes($stats['memory_total']);
            }
            $cells = array(
                __('Entries')   => $stats['entries'] !== null ? number_format((int)$stats['entries']) : null,
                __('Hit rate')  => $rate,
                __('Hits')      => $hits !== null ? number_format((int)$hits) : null,
                __('Misses')    => $misses !== null ? number_format((int)$misses) : null,
                __('Memory')    => $memory,
                __('Evictions') => $stats['evictions'] !== null ? number_format((int)$stats['evictions']) : null,
                __('Uptime')    => $fmtUptime($stats['uptime']),
                __('Server')    => $stats['server'],
            );
            $cells = array_filter($cells, static function ($v) {
                return $v !== null && $v !== '';
            }); ?>
            <div class="cache-stats">
                <?php foreach ($cells as $label => $value) { ?>
                    <div class="cache-stat">
                        <span class="cache-stat-label"><?php echo osc_esc_html($label); ?></span>
                        <span class="cache-stat-value"><?php echo osc_esc_html($value); ?></span>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <div class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                <tr>
                    <th class="col-status"><?php _e('Status'); ?></th>
                    <th class="col-driver"><?php _e('Driver'); ?></th>
                    <th class="col-persists"><?php _e('Keeps data between requests'); ?></th>
                    <th class="col-note"><?php _e('Notes'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($drivers as $id => $driver) {
                    $isActive  = ($id === $activeDriver);
                    $supported = cacheDriverSupported($id);
                    // Active is the state worth seeing first; otherwise the row says
                    // whether this server could run it at all.
                    if ($isActive) {
                        $state = 'active';
                        $word  = __('In use');
                    } elseif ($supported) {
                        $state = 'inactive';
                        $word  = __('Available');
                    } else {
                        $state = 'expired';
                        $word  = __('Not installed');
                    } ?>
                    <tr class="status-<?php echo $state; ?>">
                        <td class="col-status" data-col-name="<?php echo osc_esc_html(__('Status')); ?>">
                            <span class="osc-status"><?php echo osc_esc_html($word); ?></span>
                        </td>
                        <td class="col-driver" data-col-name="<?php echo osc_esc_html(__('Driver')); ?>">
                            <strong><?php echo osc_esc_html($driver['label']); ?></strong>
                        </td>
                        <td class="col-persists" data-col-name="<?php echo osc_esc_html(__('Keeps data between requests')); ?>">
                            <?php echo empty($driver['persists']) ? osc_esc_html(__('No')) : osc_esc_html(__('Yes')); ?>
                        </td>
                        <td class="col-note" data-col-name="<?php echo osc_esc_html(__('Notes')); ?>">
                            <?php echo osc_esc_html($driver['note']); ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <?php if ($activeDriver === 'memcached' || $activeDriver === 'memcache') { ?>
            <p class="col-hint"><?php _e('Keys are namespaced to this install, so several sites can share one memcached server safely. Clearing, however, flushes the whole server — including any other site using it.'); ?></p>
        <?php } ?>

        <?php if (!$persistent) { ?>
            <p class="col-hint"><?php printf(
                __('To cache between requests, install one of the extensions above and set %s in your config file.'),
                '<code>define(\'OSC_CACHE\', \'apcu\');</code>'
            ); ?></p>
        <?php } ?>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
