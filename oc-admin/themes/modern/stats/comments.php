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

$comments        = __get('comments');
$max             = __get('max');
$latest_comments = __get('latest_comments');
$type            = Params::getParam('type_stat');

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
    'title'   => __('Comment Statistics'),
    'help'    => __('See how many comments the listings published on your site have received.'),
));

function customHead()
{
    $comments        = __get('comments');
    $max             = __get('max');
    $latest_comments = __get('latest_comments');
    ?>
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript" src="<?php echo osc_current_admin_theme_js_url('admin-charts.js'); ?>"></script>
    <?php if (count($comments) > 0) { ?>
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
            data.addColumn('number', '<?php echo osc_esc_js(__('Comments')); ?>');
            <?php $k = 0;
        echo 'data.addRows(' . count($comments) . ');';
        foreach ($comments as $date => $num) {
            echo 'data.setValue(' . $k . ', 0, "' . $date . '");';
            echo 'data.setValue(' . $k . ', 1, ' . $num . ');';
            $k++;
        }
        ?>

            // Instantiate and draw our chart, passing in some options.
            var chart = new google.visualization.LineChart(document.getElementById('placeholder'));
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
osc_admin_page_head(__('Comment Statistics'), array(), array(
    'actions_html' => static function () use ($stats_ranges, $active_range) {
        $links = array();
        foreach ($stats_ranges as $key => $label) {
            $links[] = array(
                'label'  => $label,
                'url'    => osc_admin_base_url(true) . '?page=stats&amp;action=comments&amp;type_stat=' . $key,
                'active' => $active_range === $key,
            );
        }
        osc_admin_link_group($links);
    },
)); ?>
    <div class="row g-3" id="stats-page">
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title">
                    <h3><?php _e('Comments'); ?></h3>
                </div>
                <div class="widget-box-content">
                    <b class="stats-title"></b>
                    <div id="placeholder" class="graph-placeholder" style="height:150px">
                        <?php if (count($comments) == 0) {
                            _e('There are no statistics yet');
                        } ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="widget-box">
                <div class="widget-box-title"><h3><?php _e('Latest comments on the web'); ?></h3></div>
                <div class="widget-box-content">
                    <?php if (count($latest_comments) > 0) { ?>
                        <table class="table" cellpadding="0" cellspacing="0">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th class="col-title"><?php _e('Title'); ?></th>
                                <th><?php _e('Author'); ?></th>
                                <th><?php _e('Comment'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($latest_comments as $c) { ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo osc_admin_base_url(true); ?>?page=comments&amp;action=comment_edit&amp;id=<?php echo $c['pk_i_id']; ?>"><?php echo $c['pk_i_id']; ?></a>
                                    </td>
                                    <td>
                                        <a href="<?php echo osc_admin_base_url(true); ?>?page=comments&amp;action=comment_edit&amp;id=<?php echo $c['pk_i_id']; ?>"><?php echo osc_esc_html($c['s_title']); ?></a>
                                    </td>
                                    <td>
                                        <a href="<?php echo osc_admin_base_url(true); ?>?page=comments&amp;action=comment_edit&amp;id=<?php echo $c['pk_i_id']; ?>"><?php echo osc_esc_html($c['s_author_name']
                                                                                                                                                                               . ' - '
                                                                                                                                                                               . $c['s_author_email']); ?></a>
                                    </td>
                                    <td>
                                        <a href="<?php echo osc_admin_base_url(true); ?>?page=comments&amp;action=comment_edit&amp;id=<?php echo $c['pk_i_id']; ?>"><?php echo osc_esc_html($c['s_body']); ?></a>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php _e("There are no statistics yet"); ?></p>
                    <?php } ?>


                </div>
            </div>
        </div>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>