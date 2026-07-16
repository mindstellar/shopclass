<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 *  Osclass
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

$numItemsPerCategory = __get('numItemsPerCategory');
$numItems            = __get('numItems');
$numUsers            = __get('numUsers');
$numPendingComments  = (int) __get('numPendingComments');
$numReportedItems    = (int) __get('numReportedItems');
$aFeatured           = __get('aFeatured');
osc_add_filter('render-wrapper', 'render_offset');
/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


osc_add_filter('admin_body_class', 'addBodyClass');
if (!function_exists('addBodyClass')) {
    /**
     * @param $array
     *
     * @return array
     */
    function addBodyClass($array)
    {
        $array[] = 'dashboard';

        return $array;
    }
}

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Dashboard'); ?></h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Dashboard &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

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
                title: '<?php echo osc_esc_js(__('New listings/users')); ?>',
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
                ['<?php echo osc_esc_js(__('Date')); ?>', '<?php echo osc_esc_js(__('Listings')); ?>', '<?php echo osc_esc_js(__('Users')); ?>'],
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
<div id="dashboard">
    <div class="dash-stats">
        <a class="dash-stat" href="<?php echo osc_admin_base_url(true); ?>?page=items">
            <span class="dash-stat-icon dash-stat-icon-bronze"><i class="bi bi-card-list" aria-hidden="true"></i></span>
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format((int) $numItems); ?></span>
                <span class="dash-stat-label"><?php _e('Listings'); ?></span>
            </span>
        </a>
        <a class="dash-stat" href="<?php echo osc_admin_base_url(true); ?>?page=users">
            <span class="dash-stat-icon dash-stat-icon-info"><i class="bi bi-people" aria-hidden="true"></i></span>
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format((int) $numUsers); ?></span>
                <span class="dash-stat-label"><?php _e('Users'); ?></span>
            </span>
        </a>
        <a class="dash-stat<?php echo $numPendingComments > 0 ? ' dash-stat-flag' : ''; ?>" href="<?php echo osc_admin_base_url(true); ?>?page=comments">
            <span class="dash-stat-icon <?php echo $numPendingComments > 0 ? 'dash-stat-icon-warning' : 'dash-stat-icon-muted'; ?>"><i class="bi bi-chat-left-dots" aria-hidden="true"></i></span>
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format($numPendingComments); ?></span>
                <span class="dash-stat-label"><?php _e('Comments to moderate'); ?></span>
            </span>
        </a>
        <a class="dash-stat<?php echo $numReportedItems > 0 ? ' dash-stat-flag' : ''; ?>" href="<?php echo osc_admin_base_url(true); ?>?page=items&amp;action=items_reported&amp;sort=spam">
            <span class="dash-stat-icon <?php echo $numReportedItems > 0 ? 'dash-stat-icon-danger' : 'dash-stat-icon-muted'; ?>"><i class="bi bi-flag" aria-hidden="true"></i></span>
            <span class="dash-stat-body">
                <span class="dash-stat-value"><?php echo number_format($numReportedItems); ?></span>
                <span class="dash-stat-label"><?php _e('Reported listings'); ?></span>
            </span>
        </a>
    </div>
    <div class="row g-3">
        <div class="col-lg-4 col-md-6">
            <div class="widget-box h-100">
                <div class="widget-box-title">
                    <h3><?php _e('Listings by category'); ?></h3>
                </div>
                <div class="widget-box-content" style="max-height: 300px;overflow-x: auto">
                    <?php
                    $countEvent = 1;
                    if (!empty($numItemsPerCategory)) { ?>
                        <table class="table" cellpadding="0" cellspacing="0">
                            <tbody>
                                <?php
                                $even = false;
                                foreach ($numItemsPerCategory as $c) { ?>
                                    <tr<?php if ($even == true) {
                                            $even = false;
                                            echo ' class="even"';
                                       } else {
                                           $even = true;
                                       }
                                       if ($countEvent == 1) {
                                           echo ' class="table-first-row"';
                                       } ?>>
                                        <td><a href="<?php echo osc_admin_base_url(true); ?>?page=items&amp;catId=<?php echo
                                                                                                                    $c['pk_i_id']; ?>"><?php echo osc_esc_html($c['s_name']); ?></a>
                                        </td>
                                        <td><?php echo $c['i_num_items'] . '&nbsp;' . (($c['i_num_items'] == 1)
                                                ? __('Listing') : __('Listings')); ?></td>
                                        </tr>
                                        <?php foreach ($c['categories'] as $subc) { ?>
                                            <tr<?php if ($even == true) {
                                                    $even = false;
                                                    echo ' class="even"';
                                               } else {
                                                   $even = true;
                                               } ?>>
                                                <td class="children-cat"><a href="<?php echo osc_admin_base_url(true); ?>?page=items&amp;
                                            catId=<?php echo $subc['pk_i_id']; ?>"><?php echo osc_esc_html($subc['s_name']); ?></a>
                                                </td>
                                                <td><?php echo $subc['i_num_items'] . ' ' . (($subc['i_num_items'] == 1)
                                                        ? __('Listing') : __('Listings')); ?></td>
                                                </tr>
                                            <?php
                                            $countEvent++;
                                        }
                                        ?>
                                        <?php
                                        $countEvent++;
                                }
                                ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <?php _e("There aren't any uploaded listing yet"); ?>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('Statistics'); ?></h3>
                </div>
                <div class="widget-box-content widget-box-content-stats">
                    <div id="widget-box-stats-listings" class="widget-box-stats">
                        <div class="stats-detail"><?php printf(
                                                        __('Total number of listings: %s'),
                                                        $numItems
                                                    ); ?></div>
                        <div class="stats-detail"><?php printf(
                                                        __('Total number of users: %s'),
                                                        $numUsers
                                                    ); ?></div>
                        <div id="placeholder-listing" class="graph-placeholder" height="120"></div>
                        <a href="<?php echo osc_admin_base_url(true); ?>?page=stats&amp;action=items" class="btn btn-sm btn-dim"><?php _e('Listing statistics'); ?></a>
                        <a href="<?php echo osc_admin_base_url(true); ?>?page=stats&amp;action=users" class="btn btn-sm btn-dim"><?php _e('User statistics'); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12">
            <div class="widget-box h-100">
                <div class="widget-box-title">
                    <h3><?php _e('System status'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <?php
                    $freeDisk = function_exists('disk_free_space') ? @disk_free_space(osc_uploads_path()) : false;
                    $fmtBytes = static function ($bytes) {
                        if (!is_numeric($bytes) || $bytes <= 0) {
                            return '—';
                        }
                        $units = array('B', 'KB', 'MB', 'GB', 'TB');
                        $i     = (int) min(floor(log($bytes, 1024)), count($units) - 1);
                        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
                    };
                    $rows = array(
                        array('bi-box-seam', __('Osclass version'), OSCLASS_VERSION),
                        array('bi-filetype-php', __('PHP version'), PHP_VERSION),
                        array('bi-hdd', __('Free disk space'), $fmtBytes($freeDisk)),
                        array('bi-cloud-arrow-up', __('Max upload size'), ini_get('upload_max_filesize') ?: '—'),
                        array('bi-database', __('Memory limit'), ini_get('memory_limit') ?: '—'),
                    );
                    ?>
                    <ul class="dash-system">
                        <?php foreach ($rows as $r) { ?>
                            <li class="dash-system-row">
                                <i class="bi <?php echo $r[0]; ?>" aria-hidden="true"></i>
                                <span class="dash-system-label"><?php echo osc_esc_html($r[1]); ?></span>
                                <span class="dash-system-value"><?php echo osc_esc_html($r[2]); ?></span>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php osc_current_admin_theme_path('parts/footer.php'); ?>