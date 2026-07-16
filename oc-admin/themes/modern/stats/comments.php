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


function addHelp()
{
    echo '<p>' . __('See how many comments the listings published on your site have received.') . '</p>';
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
    return sprintf(__('Comment Statistics &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

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
    <div class="grid-system" id="stats-page">
        <div class="grid-row grid-50 mb-0">
            <div class="row-wrapper">
                <h2 class="render-title"><?php _e('Comment Statistics'); ?></h2>
            </div>
        </div>
        <div class="grid-row grid-50 mb-0">
            <div class="row-wrapper">
                <div class="btn-group btn-group-sm float-end">
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
                    echo '" href="' . osc_admin_base_url(true) . '?page=stats&amp;action=comments&amp;type_stat=' . $v . '">';
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
        <div class="grid-row grid-50 clear">
            <div class="row-wrapper">
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
        </div>
        <div class="grid-row grid-50">
            <div class="row-wrapper">
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
                            <p><?php _e("There're no statistics yet"); ?></p>
                        <?php } ?>


                    </div>
                </div>
            </div>
        </div>
        <div class="clear"></div>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>