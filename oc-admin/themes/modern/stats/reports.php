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

$reports = __get('reports');
$type    = Params::getParam('type_stat');
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

osc_admin_page(array(
    'section' => __('Statistics'),
    'title'   => __('Report Statistics'),
    'help'    => __('See how many listings from your site have been reported as spam, expired, duplicate, etc.'),
));

function customHead()
{
    $reports = __get('reports');
    ?>
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript" src="<?php echo osc_current_admin_theme_js_url('admin-charts.js'); ?>"></script>
    <?php if (count($reports) > 0) { ?>
    <script type="text/javascript">
        // Load the Visualization API and the piechart package.
        google.load('visualization', '1', {'packages': ['corechart']});

        // Set a callback to run when the Google Visualization API is loaded.
        google.setOnLoadCallback(drawChart);
        oscChartAutoRedraw(drawChart);

        // Callback that creates and populates a data table,
        // instantiates the pie chart, passes in the data and
        // draws it.
        function drawChart() {
            var data = new google.visualization.DataTable();
            data.addColumn('string', '<?php echo osc_esc_js(__('Date')); ?>');
            data.addColumn('number', '<?php echo osc_esc_js(__('Spam')); ?>');
            data.addColumn('number', '<?php echo osc_esc_js(__('Duplicated')); ?>');
            data.addColumn('number', '<?php echo osc_esc_js(__('Bad category')); ?>');
            data.addColumn('number', '<?php echo osc_esc_js(__('Offensive')); ?>');
            data.addColumn('number', '<?php echo osc_esc_js(__('Expired')); ?>');
            <?php $k = 0;
        echo 'data.addRows(' . count($reports) . ');';
        foreach ($reports as $date => $data) {
            echo 'data.setValue(' . $k . ', 0, "' . $date . '");';
            echo 'data.setValue(' . $k . ', 1, ' . $data['spam'] . ');';
            echo 'data.setValue(' . $k . ', 2, ' . $data['repeated'] . ');';
            echo 'data.setValue(' . $k . ', 3, ' . $data['bad_classified'] . ');';
            echo 'data.setValue(' . $k . ', 4, ' . $data['offensive'] . ');';
            echo 'data.setValue(' . $k . ', 5, ' . $data['expired'] . ');';
            $k++;
        }
        ?>

            // Instantiate and draw our chart, passing in some options.
            var chart = new google.visualization.ColumnChart(document.getElementById('placeholder'));
            chart.draw(data, oscChartOpts({
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
<?php
$stats_ranges = array(
    'month' => __('Last 10 months'),
    'week'  => __('Last 10 weeks'),
    'day'   => __('Last 10 days'),
);
$active_range = $type ?: 'day';
osc_admin_page_head(__('Report Statistics'), array(), array(
    'actions_html' => static function () use ($stats_ranges, $active_range) {
        $links = array();
        foreach ($stats_ranges as $key => $label) {
            $links[] = array(
                'label'  => $label,
                'url'    => osc_admin_base_url(true) . '?page=stats&amp;action=reports&amp;type_stat=' . $key,
                'active' => $active_range === $key,
            );
        }
        osc_admin_link_group($links);
    },
)); ?>
    <div class="row g-3" id="stats-page">
        <div class="col-12">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('Total number of reports'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"></b>
                    <div id="placeholder" class="graph-placeholder" style="height:150px">
                        <?php if (count($reports) == 0) {
                            _e('There are no statistics yet');
                        } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>