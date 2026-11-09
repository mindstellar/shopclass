<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$numItemsPerCategory = __get('numItemsPerCategory');
$numItems            = __get('numItems');
$numUsers            = __get('numUsers');
$numPendingComments  = (int) __get('numPendingComments');
$numReportedItems    = (int) __get('numReportedItems');
$aFeatured           = __get('aFeatured');
$toShip              = __get('toShip');
osc_add_filter('render-wrapper', 'render_offset');
/**
 * Filter callback for `render-wrapper`: the CSS class the page wrapper renders with.
 *
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

osc_add_filter('admin_body_class', 'addBodyClass');
if (!function_exists('addBodyClass')) {
    /**
     * Filter callback for `admin_body_class`: mark the dashboard on <body>.
     *
     * @param array<int,string> $array
     *
     * @return array<int,string>
     */
    function addBodyClass($array)
    {
        $array[] = 'dashboard';

        return $array;
    }
}

osc_admin_page(array(
    'section' => __('Dashboard'),
    'heading' => __('Dashboard'),
    'title'   => __('Dashboard'),
));

/**
 * Emit the dashboard's listings/users chart and the Google Charts loader it needs.
 *
 * @return void
 */
function chartJs()
{
    $items = __get('item_stats');
    $users = __get('user_stats');
    ?>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
        google.charts.load('1', {
            'packages': ['corechart']
        });
        google.charts.setOnLoadCallback(drawChartListing);

        var oscListingChart, oscListingData;

        // Pull the live value of a theme token so the chart paints with the same
        // palette as the rest of the admin — and, redrawn on toggle, follows it.
        function oscCssVar(name, fallback) {
            var v = getComputedStyle(document.documentElement).getPropertyValue(name);
            return (v && v.trim()) || fallback;
        }

        function oscChartOptions() {
            var muted  = oscCssVar('--osc-ink-muted', '#67635d');
            var rule   = oscCssVar('--osc-rule', '#e0dcd4');
            var bronze = oscCssVar('--osc-bronze', '#a9703f');
            var info   = oscCssVar('--osc-info', '#3a8fbf');
            return {
                title: '<?php echo osc_esc_js(osc_mode_text(__('New listings/users'), __('New products/users'))); ?>',
                titleTextStyle: { color: muted, fontSize: 14, bold: false },
                // Transparent so the chart sits on the widget's surface in either
                // theme instead of baking in a white fill.
                backgroundColor: { fill: 'transparent' },
                hAxis: {
                    textStyle: { color: muted },
                    gridlines: { color: rule },
                    baselineColor: rule,
                    slantedText: false
                },
                vAxis: {
                    minValue: 0,
                    textStyle: { color: muted },
                    gridlines: { color: rule },
                    baselineColor: rule
                },
                legend: {
                    position: 'bottom',
                    alignment: 'center',
                    textStyle: { color: muted }
                },
                colors: [bronze, info],
                areaOpacity: 0.16,
                lineWidth: 2,
                chartArea: { top: 24, left: 32, width: '88%', height: '70%' },
                animation: { startup: true, duration: 250, easing: 'out' }
            };
        }

        function drawChartListing() {
            oscListingData = google.visualization.arrayToDataTable([
                ['<?php echo osc_esc_js(__('Date')); ?>', '<?php echo osc_esc_js(osc_mode_text(__('Listings'), __('Products'))); ?>', '<?php echo osc_esc_js(__('Users')); ?>'],
                <?php foreach ($items as $k => $v) {
                    echo "['" . osc_esc_js($k) . "', " . (int)$v . ', ' . (int)$users[$k] . '],';
                } ?>
            ]);
            oscListingChart = new google.visualization.AreaChart(document.getElementById('placeholder-listing'));
            oscListingChart.draw(oscListingData, oscChartOptions());
        }

        function oscRedrawListingChart() {
            if (oscListingChart && oscListingData) {
                oscListingChart.draw(oscListingData, oscChartOptions());
            }
        }

        var oscChartResizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(oscChartResizeTimer);
            oscChartResizeTimer = setTimeout(oscRedrawListingChart, 300);
        });

        // Redraw on theme flip so the text/grid/series colours re-resolve from the
        // tokens instead of freezing at their load-time (light) values.
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                if (muts[i].attributeName === 'data-bs-theme') {
                    oscRedrawListingChart();
                    break;
                }
            }
        }).observe(document.documentElement, { attributes: true });
    </script>
    <?php
}

osc_add_hook('admin_footer', 'chartJs', 10);

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(array('id' => 'dashboard')); ?>
    <div class="dash-stats">
        <a class="dash-stat" href="<?php echo osc_admin_base_url(true); ?>?page=items">
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format((int) $numItems); ?></span>
                <span class="dash-stat-label"><?php echo osc_mode_text(__('Listings'), __('Products')); ?></span>
            </span>
        </a>
        <a class="dash-stat" href="<?php echo osc_admin_base_url(true); ?>?page=users">
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format((int) $numUsers); ?></span>
                <span class="dash-stat-label"><?php _e('Users'); ?></span>
            </span>
        </a>
        <a class="dash-stat<?php echo $numPendingComments > 0 ? ' dash-stat-flag' : ''; ?>" href="<?php echo osc_admin_base_url(true); ?>?page=comments">
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format($numPendingComments); ?></span>
                <span class="dash-stat-label"><?php _e('Comments to moderate'); ?></span>
            </span>
        </a>
        <a class="dash-stat<?php echo $numReportedItems > 0 ? ' dash-stat-flag' : ''; ?>" href="<?php echo osc_admin_base_url(true); ?>?page=items&amp;action=items_reported&amp;sort=spam">
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format($numReportedItems); ?></span>
                <span class="dash-stat-label"><?php echo osc_mode_text(__('Reported listings'), __('Reported products')); ?></span>
            </span>
        </a>
    </div>
    <?php if (is_array($toShip)) {
        $toShipUrl  = osc_admin_base_url(true) . '?page=billing&amp;toShip=1';
        $toShipOpts = array('id' => 'dash-to-ship', 'stack' => true);
        if ($toShip['count'] > 0) {
            $toShipOpts['count'] = (int) $toShip['count'];
        }
        osc_admin_panel_open(__('To ship'), $toShipOpts); ?>
                    <?php if ($toShip['orders'] === array()) { ?>
                        <?php osc_admin_empty(array('title' => __('Nothing to ship.'))); ?>
                    <?php } else { ?>
                        <ul class="dash-system">
                            <?php foreach ($toShip['orders'] as ['order' => $order, 'buyer' => $buyer]) { ?>
                                <li class="dash-system-row dash-ship-row">
                                    <a href="<?php echo osc_esc_html(osc_admin_billing_order_url($order->getId())); ?>"><?php echo osc_esc_html(\mindstellar\billing\Receipts::number($order)); ?></a>
                                    <span class="dash-system-label"><?php echo osc_esc_html($buyer); ?></span>
                                    <span class="dash-system-value"><?php echo osc_esc_html(osc_admin_money($order->getAmount(), $order->getCurrency())); ?></span>
                                    <span class="dash-ship-age"><?php echo osc_esc_html(osc_admin_duration(time() - (int) strtotime($order->getDate()))); ?></span>
                                </li>
                            <?php } ?>
                        </ul>
                        <a href="<?php echo $toShipUrl; ?>" class="btn btn-sm btn-dim"><?php _e('See all'); ?></a>
                    <?php } ?>
    <?php osc_admin_panel_close();
    } ?>
    <?php osc_admin_body_open(array('layout' => 'grid', 'class' => 'dash-cards')); ?>
        <?php osc_admin_panel_open(osc_mode_text(__('Listings by category'), __('Products by category')), array('class' => 'dash-category-card')); ?>
                    <?php
if (!empty($numItemsPerCategory)) {
    $categoryRow = static function (array $category, string $class = ''): array {
        $url = osc_admin_base_url(true) . '?page=items&amp;catId=' . (int)$category['pk_i_id'];

        return array(
            'name'  => array('html' => '<a href="' . $url . '">' . osc_esc_html($category['s_name']) . '</a>', 'class' => $class),
            'count' => $category['i_num_items'],
        );
    };
    $categoryRows = array();
    foreach ($numItemsPerCategory as $c) {
        $categoryRows[] = $categoryRow($c);
        foreach ($c['categories'] as $subc) {
            $categoryRows[] = $categoryRow($subc, 'children-cat');
        }
    }
    osc_admin_table(array(
        'name'  => __('Category'),
        'count' => array('label' => osc_mode_text(__('Listings'), __('Products')), 'numeric' => true),
    ), $categoryRows, array('wrap' => false));
    ?>
                    <?php } else { ?>
                        <?php echo osc_mode_text(__("There aren't any uploaded listing yet"), __('There are no products yet')); ?>
                    <?php } ?>
        <?php osc_admin_panel_close(); ?>
        <?php osc_admin_panel_open(__('Statistics'), array('class' => 'dash-stats-card')); ?>
                    <div id="widget-box-stats-listings" class="widget-box-stats">
                        <div id="placeholder-listing" class="graph-placeholder" height="120"></div>
                        <a href="<?php echo osc_admin_base_url(true); ?>?page=stats&amp;action=items" class="btn btn-sm btn-dim"><?php echo osc_mode_text(__('Listing statistics'), __('Product statistics')); ?></a>
                        <a href="<?php echo osc_admin_base_url(true); ?>?page=stats&amp;action=users" class="btn btn-sm btn-dim"><?php _e('User statistics'); ?></a>
                    </div>
        <?php osc_admin_panel_close(); ?>
        <?php osc_admin_panel_open(__('System status'), array('class' => 'dash-system-card')); ?>
                    <?php
                    $freeDisk = function_exists('disk_free_space') ? @disk_free_space(osc_uploads_path()) : false;
$rows = array(
    array(__('Shopclass version'), OSCLASS_VERSION),
    array(__('PHP version'), PHP_VERSION),
    array(__('Free disk space'), is_numeric($freeDisk) && $freeDisk > 0 ? \mindstellar\utility\Formatting::bytes((int) $freeDisk) : '—'),
    array(__('Max upload size'), ini_get('upload_max_filesize') ?: '—'),
    array(__('Memory limit'), ini_get('memory_limit') ?: '—'),
);
?>
                    <ul class="dash-system">
                        <?php foreach ($rows as $r) { ?>
                            <li class="dash-system-row">
                                <span class="dash-system-label"><?php echo osc_esc_html($r[0]); ?></span>
                                <span class="dash-system-value"><?php echo osc_esc_html($r[1]); ?></span>
                            </li>
                        <?php } ?>
                    </ul>
        <?php osc_admin_panel_close(); ?>
    <?php osc_admin_body_close(); ?>
<?php osc_admin_body_close(); ?>

<?php osc_current_admin_theme_path('parts/footer.php'); ?>
