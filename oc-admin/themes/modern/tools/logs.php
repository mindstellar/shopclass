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

function addHelp()
{
    echo '<p>' . __('A record of admin and listing activity — who did what, and when. '
                    . 'Use the settings below to keep it pruned automatically or turn it off entirely.') . '</p>';
}
osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Tools'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}

function customPageTitle($string)
{
    return sprintf(__('Activity log &raquo; %s'), $string);
}
osc_add_filter('admin_title', 'customPageTitle');

$aData      = __get('aData');
$sections   = __get('sections');
$enabled    = __get('log_enabled');
$retention  = (int) __get('log_retention_days');
$curSection = (string) Params::getParam('section');
$curQuery   = (string) Params::getParam('q');
$sort       = Params::getParam('sort');
$direction  = Params::getParam('direction');

$columns   = $aData['aColumns'];
$rows      = $aData['aRows'];
$total     = (int) $aData['iTotalRecords'];
$retainStr = $retention > 0
    ? sprintf(__('pruned after %d days'), $retention)
    : __('kept forever');

osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Activity log'); ?></h2>

    <section class="log-panel">
        <header class="log-panel-head"><?php _e('Logging'); ?></header>
        <form class="log-panel-body" method="post" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="logs_settings_post"/>
            <?php osc_csrf_token_form(); ?>

            <div class="form-check form-switch log-panel-toggle">
                <input class="form-check-input" type="checkbox" role="switch" id="admin_log_enabled"
                       name="admin_log_enabled" value="1" <?php echo($enabled ? 'checked' : ''); ?>>
                <label class="form-check-label" for="admin_log_enabled"><?php _e('Record activity'); ?></label>
            </div>

            <div class="log-panel-retention">
                <label for="admin_log_retention_days"><?php _e('Keep entries for'); ?></label>
                <div class="input-group input-group-sm">
                    <input type="number" min="0" class="form-control" id="admin_log_retention_days"
                           name="admin_log_retention_days" value="<?php echo $retention; ?>">
                    <span class="input-group-text"><?php _e('days'); ?></span>
                </div>
            </div>

            <button type="submit" class="btn btn-sm btn-submit"><?php _e('Save'); ?></button>

            <p class="log-panel-note">
                <?php _e('The daily task deletes entries older than this — set to 0 to keep them forever. Turning logging off stops new entries immediately.'); ?>
            </p>
        </form>
    </section>

    <div class="log-toolbar">
        <form class="log-filters" method="get" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="logs"/>
            <div class="log-field">
                <label class="form-label" for="section"><?php _e('Section'); ?></label>
                <select class="form-select form-select-sm" id="section" name="section">
                    <option value=""><?php _e('All sections'); ?></option>
                    <?php foreach ($sections as $s) { ?>
                        <option value="<?php echo osc_esc_html($s); ?>" <?php echo($curSection === $s ? 'selected' : ''); ?>>
                            <?php echo osc_esc_html($s); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="log-field">
                <label class="form-label" for="q"><?php _e('Search'); ?></label>
                <input type="text" class="form-control form-control-sm" id="q" name="q"
                       value="<?php echo osc_esc_html($curQuery); ?>"
                       placeholder="<?php echo osc_esc_html(__('details, action or IP')); ?>">
            </div>
            <div class="log-field log-field-actions">
                <button type="submit" class="btn btn-sm btn-primary"><?php _e('Filter'); ?></button>
                <?php if ($curSection !== '' || $curQuery !== '') { ?>
                    <a class="btn btn-sm btn-dim"
                       href="<?php echo osc_admin_base_url(true); ?>?page=tools&amp;action=logs"><?php _e('Reset'); ?></a>
                <?php } ?>
            </div>
        </form>

        <div class="log-summary">
            <span class="log-summary-count"><?php echo number_format($total); ?></span>
            <?php _e('entries'); ?> &middot; <?php echo osc_esc_html($retainStr); ?>
        </div>
    </div>

    <div class="relative">
        <div class="table-contains-actions">
            <table class="table log-table" cellpadding="0" cellspacing="0">
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
                <?php } else { ?>
                    <tr>
                        <td colspan="6">
                            <div class="log-empty">
                                <i class="bi bi-clipboard-x"></i>
                                <p><?php echo($total > 0 ? osc_esc_html(__('No entries match your filter')) : osc_esc_html(__('No activity has been logged yet'))); ?></p>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
function showingResults()
{
    $aData = __get('aData');
    echo '<ul class="showing-results"><li><span>'
         . osc_pagination_showing(
             (Params::getParam('iPage') - 1) * $aData['iDisplayLength'] + 1,
             ((Params::getParam('iPage') - 1) * $aData['iDisplayLength']) + count($aData['aRows']),
             $aData['iTotalDisplayRecords'],
             $aData['iTotalRecords']
         )
         . '</span></li></ul>';
}
osc_add_hook('before_show_pagination_admin', 'showingResults');
osc_show_pagination_admin($aData);
?>
    <div class="log-footer">
        <button type="button" class="btn btn-sm btn-danger" data-osc-dialog-open="#logs-clear-dialog"><?php _e('Clear log'); ?></button>
    </div>

    <dialog id="logs-clear-dialog" class="osc-dialog osc-dialog-danger">
        <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="logs_clear"/>
            <?php osc_csrf_token_form(); ?>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title"><i class="bi bi-exclamation-triangle-fill"></i> <?php _e('Clear the activity log?'); ?></p>
                <p class="osc-dialog-text"><?php _e("This permanently deletes every log entry. This can't be undone."); ?></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button type="submit" class="btn btn-danger btn-sm"><?php _e('Delete all entries'); ?></button>
            </div>
        </form>
    </dialog>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
