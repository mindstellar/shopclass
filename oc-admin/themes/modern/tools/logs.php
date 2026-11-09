<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_admin_page(array(
    'section' => __('Tools'),
    'heading' => __('Activity log'),
    'title'   => __('Activity log'),
    'help'    => __('A record of admin and listing activity — who did what, and when. '
                    . 'Use the settings below to keep it pruned automatically or turn it off entirely.'),
));

$aData      = __get('aData');
$sections   = __get('sections');
$curSection = (string) Params::getParam('section');
$curQuery   = (string) Params::getParam('q');
$hasFilter  = ($curSection !== '' || $curQuery !== '');
$sort       = Params::getParam('sort');
$direction  = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

osc_current_admin_theme_path('parts/header.php');
osc_admin_body_open(); ?>

    <?php osc_admin_section(__('Logging'), array('id' => 'log-settings')); ?>
        <?php $form = __get('log_form');
        osc_admin_settings_form($form['id'], $form); ?>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('Recent activity')); ?>
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
                    <select name="section" class="form-select form-select-sm" aria-label="<?php _e('Section'); ?>">
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
        <?php
        osc_admin_data_table($aData, array(
            'sort'      => $sort,
            'direction' => $direction,
            'empty'     => $hasFilter
                ? array(
                    'icon'   => 'bi-search',
                    'title'  => __('No entries match your filter.'),
                    'action' => array(
                        'label'   => __('Reset'),
                        'url'     => osc_admin_base_url(true) . '?page=tools&amp;action=logs',
                        'variant' => 'secondary',
                    ),
                )
                : array(
                    'icon'  => 'bi-clock-history',
                    'title' => __('No activity has been logged yet.'),
                ),
        ));
        ?>
    </div>
<?php
osc_admin_pagination($aData);
?>
    <div class="form-actions">
        <button type="button" class="btn btn-danger btn-sm" data-osc-dialog-open="#logs-clear-dialog"><?php _e('Clear log'); ?></button>
    </div>
    <?php osc_admin_section_close(); ?>
<?php osc_admin_body_close(); ?>

    <?php osc_admin_confirm_dialog(array(
        'id'      => 'logs-clear-dialog',
        'method'  => 'post',
        'fields'  => array('page' => 'tools', 'action' => 'logs_clear'),
        'title'   => __('Clear the activity log?'),
        'text'    => __("This permanently deletes every log entry. This can't be undone."),
        'confirm' => __('Delete all entries'),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
