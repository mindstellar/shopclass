<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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


function addHelp()
{
    echo '<p>' . __('See how many listings from your site have been reported as spam, expired, duplicate, etc.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Statistics'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
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
    return sprintf(__('Report Statistics &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

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
    <div class="row g-3 align-items-center mb-1">
        <div class="col-md-6">
            <h2 class="render-title mb-0"><?php _e('Report Statistics'); ?></h2>
        </div>
        <div class="col-md-6 text-md-end">
            <div class="btn-group btn-group-sm">
                <?php
                $comments_stats_intervals = ['month', 'week', 'day'];
                if (!$type) {
                    $type = 'day';
                }
                foreach ($comments_stats_intervals as $k => $v) {
                    echo '<a id="' . $v . '" class="btn btn-outline-primary';
                    if ($type === $v ) {
                        echo ' active';
                    }
                    echo '" href="' . osc_admin_base_url(true) . '?page=stats&amp;action=reports&amp;type_stat=' . $v . '">';
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