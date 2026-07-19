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

$items        = __get('items');
$max          = __get('max');
$reports      = __get('reports');
$max_views    = __get('max_views');
$latest_items = __get('latest_items');

$alerts      = __get('alerts');
$max_alerts  = __get('max_alerts');
$subscribers = __get('subscribers');
$max_subs    = __get('max_subs');

$type = Params::getParam('type_stat');

switch ($type) {
    case 'week':
        $type_stat = __('Last 10 weeks');
        break;
    case 'month':
        $type_stat = __('Last 10 months');
        break;
    default:
        $type_stat = __('Last 10 days');
}

osc_add_filter('render-wrapper', 'render_offset');
/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


function addHelp()
{
    echo '<p>'
         . __('Quickly find out how many new listings have been published on your site and how many visits each of the listings gets.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Statistics'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Listing Statistics &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

function customHead()
{
    $items        = __get('items');
    $max          = __get('max');
    $reports      = __get('reports');
    $max_views    = __get('max_views');
    $latest_items = __get('latest_items');

    $alerts      = __get('alerts');
    $max_alerts  = __get('max_alerts');
    $subscribers = __get('subscribers');
    $max_subs    = __get('max_subs');

    ?>
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript" src="<?php echo osc_current_admin_theme_js_url('admin-charts.js'); ?>"></script>
    <?php if (count($items) > 0) { ?>
    <script type="text/javascript">
        // Load the Visualization API and the piechart package.
        google.load('visualization', '1', {'packages': ['corechart']});

        // Set a callback to run when the Google Visualization API is loaded, and
        // redraw on theme flip so the charts follow light/dark.
        google.setOnLoadCallback(drawChart);
        oscChartAutoRedraw(drawChart);

        // Callback that creates and populates a data table,
        // instantiates the pie chart, passes in the data and
        // draws it.
        function drawChart() {
            /* ITEMS */
            var data = new google.visualization.DataTable();
            var data2 = new google.visualization.DataTable();
            data.addColumn('string', '<?php echo osc_esc_js(__('Date')); ?>');
            data.addColumn('number', '<?php echo osc_esc_js(__('Items')); ?>');
            data2.addColumn('string', '<?php echo osc_esc_js(__('Date')); ?>');
            data2.addColumn('number', '<?php echo osc_esc_js(__('Views')); ?>');

            /* ALERTS */
            var data3 = new google.visualization.DataTable();
            var data4 = new google.visualization.DataTable();
            data3.addColumn('string', '<?php echo osc_esc_js(__('Date')); ?>');
            data3.addColumn('number', '<?php echo osc_esc_js(__('Alerts')); ?>');
            data4.addColumn('string', '<?php echo osc_esc_js(__('Date')); ?>');
            data4.addColumn('number', '<?php echo osc_esc_js(__('Subscribers')); ?>');

            <?php /*ITEMS */
            $k = 0;
            echo 'data.addRows(' . count($items) . ');';
            foreach ($items as $date => $num) {
                echo 'data.setValue(' . $k . ', 0, "' . $date . '");';
                echo 'data.setValue(' . $k . ', 1, ' . $num . ');';
                $k++;
            }
            $k = 0;
            echo 'data2.addRows(' . count($reports) . ');';
            foreach ($reports as $date => $data) {
                echo 'data2.setValue(' . $k . ', 0, "' . $date . '");';
                echo 'data2.setValue(' . $k . ', 1, ' . $data['views'] . ');';
                $k++;
            }

            /* ALERTS */
            $k = 0;
            echo 'data3.addRows(' . count($alerts) . ');';
            foreach ($alerts as $date => $num) {
                echo 'data3.setValue(' . $k . ', 0, "' . $date . '");';
                echo 'data3.setValue(' . $k . ', 1, ' . $num . ');';
                $k++;
            }
            $k = 0;
            echo 'data4.addRows(' . count($subscribers) . ');';
            foreach ($subscribers as $date => $num) {
                echo 'data4.setValue(' . $k . ', 0, "' . $date . '");';
                echo 'data4.setValue(' . $k . ', 1, ' . $num . ');';
                $k++;
            }
            ?>

            // Instantiate and draw our chart, passing in some options.
            var chart = new google.visualization.AreaChart(document.getElementById('placeholder'));
            chart.draw(data, oscChartOpts({
                pointSize: 6,
                legend: 'none'
            }));

            var chart = new google.visualization.AreaChart(document.getElementById('placeholder_total'));
            chart.draw(data2, oscChartOpts({
                pointSize: 6,
                legend: 'none'
            }));

            var chart = new google.visualization.AreaChart(document.getElementById('placeholder_alerts'));
            chart.draw(data3, oscChartOpts({
                pointSize: 6,
                legend: 'none'
            }));

            var chart = new google.visualization.AreaChart(document.getElementById('placeholder_subscribers'));
            chart.draw(data4, oscChartOpts({
                pointSize: 6,
                legend: 'none'
            }));

        }
    </script>
    <?php }
}


osc_add_hook('admin_header', 'customHead', 10);
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <div class="row g-3 align-items-center mb-1">
        <div class="col-md-6">
            <h2 class="render-title mb-0"><?php _e('Listing Statistics'); ?></h2>
        </div>
        <div class="col-md-6 text-md-end">
            <div class="btn-group btn-group-sm">
                <?php
                $stats_intervals = ['month', 'week', 'day'];
                if (!$type) {
                    $type = 'day';
                }
                foreach ($stats_intervals as $k => $v) {
                    echo '<a id="' . $v . '" class="btn btn-outline-primary';
                    if ($type === $v) {
                        echo ' active';
                    }
                    echo '" href="' . osc_admin_base_url(true) . '?page=stats&amp;action=items&amp;type_stat=' . $v . '">';
                    if ($v === 'month') {
                        echo __('Last 10 months');
                    } elseif ($v === 'week') {
                        echo __('Last 10 weeks');
                    } elseif ($v === 'day') {
                        echo __('Last 10 days');
                    }
                    echo '</a>';
                } ?>
            </div>
        </div>
    </div>
    <div class="row g-3" id="stats-page">
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('New listing'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"><?php _e('Number of new listings'); ?></b>
                    <div id="placeholder" class="graph-placeholder" style="height:150px">
                        <?php if (count($items) == 0) {
                            _e("There're no statistics yet");
                        } ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('Listings\' views'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"><?php _e("Total number of listings' views"); ?></b>
                    <div id="placeholder_total" class="graph-placeholder" style="height:150px">
                        <?php if (count($reports) == 0) {
                            _e("There're no statistics yet");
                        } ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('New alerts'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"><?php _e('Number of new alerts'); ?></b>
                    <div id="placeholder_alerts" class="graph-placeholder" style="height:150px">
                        <?php if (count($alerts) == 0) {
                            _e("There're no statistics yet");
                        } ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('New subscribers'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"><?php _e('Number of new subscribers'); ?></b>
                    <div id="placeholder_subscribers" class="graph-placeholder" style="height:150px">
                        <?php if (count($subscribers) == 0) {
                            _e("There're no statistics yet");
                        } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>