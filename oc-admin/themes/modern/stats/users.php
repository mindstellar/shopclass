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

$users            = __get('users');
$max              = __get('max');
$item             = __get('item');
$users_by_country = __get('users_by_country');
$users_by_region  = __get('users_by_region');
$latest_users     = __get('latest_users');
$type             = Params::getParam('type_stat');

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
         . __('Stay up-to-date on the number of users registered on your site. You can also see a breakdown of the '
              . 'countries and regions where users live among those available on your site.')
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
    return sprintf(__('User Statistics &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

function customHead()
{
    $users            = __get('users');
    $max              = __get('max');
    $item             = __get('item');
    $users_by_country = __get('users_by_country');
    $users_by_region  = __get('users_by_region');
    $latest_users     = __get('latest_users');
    ?>
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript" src="<?php echo osc_current_admin_theme_js_url('admin-charts.js'); ?>"></script>
    <?php if (count($users) > 0) { ?>
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

            data.addColumn('string', '<?php echo osc_esc_js(__('Date')); ?>', 0, 1);
            data.addColumn('number', '<?php echo osc_esc_js(__('New users')); ?>');
            <?php $k = 0;
            echo 'data.addRows(' . count($users) . ');';
            foreach ($users as $date => $num) {
                echo 'data.setValue(' . $k . ', 0, "' . $date . '");';
                echo 'data.setValue(' . $k . ', 1, ' . $num . ');';
                $k++;
            }
            ?>

            // Instantiate and draw our chart, passing in some options.
            var chart = new google.visualization.AreaChart(document.getElementById('placeholder'));
            chart.draw(data, oscChartOpts({
                pointSize: 6,
                legend: 'none'
            }));

            var data_country = new google.visualization.DataTable();
            data_country.addColumn('string', '<?php _e('Country'); ?>');
            data_country.addColumn('number', '<?php _e('Users per country'); ?>');
            data_country.addRows(<?php echo count($users_by_country); ?>);
            <?php foreach ($users_by_country as $k => $v) {
                echo "data_country.setValue(" . $k . ", 0, '" . (($v['s_country'] == null) ? __('Unknown')
                    : osc_esc_js($v['s_country'])) . "');";
                echo "data_country.setValue(" . $k . ", 1, " . $v['num'] . ");";
            } ?>

            // Create and draw the visualization.
            new google.visualization.PieChart(document.getElementById('by_country')).draw(data_country, {
                title: null,
                height: 200
            });

            var data_region = new google.visualization.DataTable();
            data_region.addColumn('string', '<?php _e('Region'); ?>');
            data_region.addColumn('number', '<?php _e('Users per region'); ?>');
            data_region.addRows(<?php echo count($users_by_region); ?>);
            <?php foreach ($users_by_region as $k => $v) {
                echo "data_region.setValue(" . $k . ", 0, '" . (($v['s_region'] == null) ? __('Unknown') : osc_esc_js($v['s_region']))
                 . "');";
                echo "data_region.setValue(" . $k . ", 1, " . $v['num'] . ");";
            } ?>

            // Create and draw the visualization.
            new google.visualization.PieChart(document.getElementById('by_region')).draw(data_region, {
                title: null,
                height: 200
            });
        }
    </script>
    <?php }
}


osc_add_hook('admin_header', 'customHead', 10);
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>

    <div class="row g-3 align-items-center mb-1">
        <div class="col-md-6">
            <h2 class="render-title mb-0"><?php _e('User Statistics'); ?></h2>
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
                    if ($type === $v) {
                        echo ' active';
                    }
                    echo '" href="' . osc_admin_base_url(true) . '?page=stats&amp;action=users&amp;type_stat=' . $v . '">';
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
                    <h3><?php _e('New users'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"></b>
                    <div id="placeholder" class="graph-placeholder" style="height:150px">
                        <?php if (count($users) == 0) {
                            _e("There're no statistics yet");
                        } ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('Users per country'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"></b>
                    <div id="by_country" class="graph-placeholder" style="height:150px">
                        <?php if (count($users_by_country) == 0) {
                            _e("There're no statistics yet");
                        } ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('Users per region'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"></b>
                    <div id="by_region" class="graph-placeholder" style="height:150px">
                        <?php if (count($users_by_region) == 0) {
                            _e("There're no statistics yet");
                        } ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title"><h3><?php _e('Latest users on the web'); ?></h3></div>
                <div class="widget-box-content">
                    <?php if (count($latest_users) > 0) { ?>
                        <table class="table" cellpadding="0" cellspacing="0">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th><?php _e('E-Mail'); ?></th>
                                <th><?php _e('Name'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($latest_users as $u) { ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo osc_admin_base_url(true); ?>?page=users&amp;action=edit&amp;id=<?php
                                        echo $u['pk_i_id']; ?>"><?php echo $u['pk_i_id']; ?></a>
                                    </td>
                                    <td>
                                        <a href="<?php echo osc_admin_base_url(true); ?>?page=users&amp;action=edit&amp;id=<?php
                                        echo $u['pk_i_id']; ?>"><?php echo osc_esc_html($u['s_email']); ?></a>
                                    </td>
                                    <td>
                                        <a href="<?php echo osc_admin_base_url(true); ?>?page=users&amp;action=edit&amp;id=<?php
                                        echo $u['pk_i_id']; ?>"><?php echo osc_esc_html($u['s_name']); ?></a>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php _e('There are no statistics yet'); ?></p>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title"><h3><?php _e('Avg. items per user'); ?></h3></div>
                <div class="widget-box-content">
                    <div class="stats-detail">
                        <?php printf(__('%s listings per user'), number_format($item, 2)); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php osc_current_admin_theme_path('parts/footer.php'); ?>