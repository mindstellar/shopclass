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

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Activity log'),
    'help'    => __('A record of admin and listing activity — who did what, and when. '
                    . 'Use the settings below to keep it pruned automatically or turn it off entirely.'),
));

$aData      = __get('aData');
$sections   = __get('sections');
$enabled    = __get('log_enabled');
$retention  = (int) __get('log_retention_days');
$curSection = (string) Params::getParam('section');
$curQuery   = (string) Params::getParam('q');
$hasFilter  = ($curSection !== '' || $curQuery !== '');
$sort       = Params::getParam('sort');
$direction  = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Activity log')); ?>

    <div id="log-settings">
        <h3 class="render-title"><?php _e('Logging'); ?></h3>
        <form name="log_settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="logs_settings_post"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Record activity'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <input type="checkbox" id="admin_log_enabled" name="admin_log_enabled" value="1"
                                <?php echo($enabled ? 'checked="checked"' : ''); ?> />
                            <label for="admin_log_enabled"><?php _e('Record admin and listing activity'); ?></label>
                        </div>
                        <div class="help-box">
                            <?php _e('Turn logging off to stop recording new entries. Existing entries are kept until pruned.'); ?>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Keep entries for'); ?></div>
                    <div class="form-controls">
                        <div class="input-group input-group-sm" style="max-width:12rem">
                            <input type="number" min="0" class="form-control" id="admin_log_retention_days"
                                   name="admin_log_retention_days" value="<?php echo $retention; ?>">
                            <span class="input-group-text"><?php _e('days'); ?></span>
                        </div>
                        <div class="help-box">
                            <?php _e('The daily task deletes entries older than this. Set to 0 to keep them forever.'); ?>
                        </div>
                    </div>
                </div>
                <?php osc_admin_form_actions(); ?>
            </fieldset>
        </form>
    </div>

    <h3 class="render-title separate-top"><?php _e('Recent activity'); ?></h3>
    <div class="relative">
        <div class="table-toolbar">
            <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
                <input type="hidden" name="page" value="tools"/>
                <input type="hidden" name="action" value="logs"/>
                <div class="input-group input-group-sm">
                    <?php if ($hasFilter) { ?>
                        <a class="btn btn-dim"
                           href="<?php echo osc_admin_base_url(true); ?>?page=tools&amp;action=logs"><?php _e('Reset'); ?></a>
                    <?php } ?>
                    <select name="section" class="form-select form-select-sm">
                        <option value=""><?php _e('All sections'); ?></option>
                        <?php foreach ($sections as $s) { ?>
                            <option value="<?php echo osc_esc_html($s); ?>" <?php echo($curSection === $s ? 'selected="selected"' : ''); ?>>
                                <?php echo osc_esc_html($s); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <input type="text" name="q" class="form-control"
                           placeholder="<?php echo osc_esc_html(__('details, action or IP')); ?>"
                           value="<?php echo osc_esc_html($curQuery); ?>"/>
                    <button type="submit" class="btn btn-primary" title="<?php echo osc_esc_html(__('Find')); ?>">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>
        </div>
        <div class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                <tr>
                    <?php foreach ($columns as $k => $v) {
                        $cls = $sort === $k ? ($direction === 'desc' ? 'sorting_desc' : 'sorting_asc') : '';
                        echo '<th class="col-' . osc_esc_html($k) . ' ' . $cls . '">' . $v . '</th>';
                    } ?>
                </tr>
                </thead>
                <tbody>
                <?php if (count($rows) > 0) { ?>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <?php foreach ($row as $k => $v) { ?>
                                <td class="col-<?php echo osc_esc_html($k); ?>" data-col-name="<?php echo osc_esc_html(ucfirst($k)); ?>"><?php echo $v; ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                <?php } elseif ($hasFilter) {
                    osc_admin_table_empty(count($columns), array(
                        'icon'   => 'bi-search',
                        'title'  => __('No entries match your filter.'),
                        'action' => array(
                            'label'   => __('Reset'),
                            'url'     => osc_admin_base_url(true) . '?page=tools&amp;action=logs',
                            'variant' => 'secondary',
                        ),
                    ));
                } else {
                    osc_admin_table_empty(count($columns), array(
                        'icon'  => 'bi-clock-history',
                        'title' => __('No activity has been logged yet.'),
                    ));
                } ?>
                </tbody>
            </table>
            <div id="table-row-actions"></div>
        </div>
    </div>
<?php
osc_admin_pagination($aData);
?>
    <div class="form-actions">
        <button type="button" class="btn btn-danger btn-sm" data-osc-dialog-open="#logs-clear-dialog"><?php _e('Clear log'); ?></button>
    </div>

    <?php osc_admin_confirm_dialog(array(
        'id'      => 'logs-clear-dialog',
        'method'  => 'post',
        'fields'  => array('page' => 'tools', 'action' => 'logs_clear'),
        'title'   => __('Clear the activity log?'),
        'text'    => __("This permanently deletes every log entry. This can't be undone."),
        'confirm' => __('Delete all entries'),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
