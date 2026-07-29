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

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Activity log'); ?></h2>

    <div id="log-settings">
        <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="logs_settings_post"/>
            <?php osc_csrf_token_form(); ?>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Logging'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <input type="checkbox" id="admin_log_enabled" name="admin_log_enabled" value="1"
                                <?php echo($enabled ? 'checked="checked"' : ''); ?> />
                            <label for="admin_log_enabled"><?php _e('Record admin and listing activity'); ?></label>
                        </div>
                        <div class="help-box">
                            <?php _e('Turn this off to stop writing new log entries entirely. Existing entries are kept until pruned.'); ?>
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
                <div class="form-actions">
                    <input type="submit" value="<?php echo osc_esc_html(__('Save settings')); ?>" class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
    </div>

    <h3 class="render-title separate-top"><?php _e('Recent activity'); ?></h3>

    <form method="get" action="<?php echo osc_admin_base_url(true); ?>" class="mb-3">
        <input type="hidden" name="page" value="tools"/>
        <input type="hidden" name="action" value="logs"/>
        <div class="d-flex flex-wrap gap-2 align-items-end">
            <div>
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
            <div>
                <label class="form-label" for="q"><?php _e('Search'); ?></label>
                <input type="text" class="form-control form-control-sm" id="q" name="q"
                       value="<?php echo osc_esc_html($curQuery); ?>"
                       placeholder="<?php echo osc_esc_html(__('details, action or IP')); ?>">
            </div>
            <div>
                <button type="submit" class="btn btn-sm btn-primary"><?php _e('Filter'); ?></button>
                <?php if ($curSection !== '' || $curQuery !== '') { ?>
                    <a class="btn btn-sm btn-dim"
                       href="<?php echo osc_admin_base_url(true); ?>?page=tools&amp;action=logs"><?php _e('Reset'); ?></a>
                <?php } ?>
            </div>
        </div>
    </form>

    <div class="relative">
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
                <?php } else { ?>
                    <tr>
                        <td colspan="6" class="text-center">
                            <p><?php _e('No log entries found'); ?></p>
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
    <div class="form-actions separate-top">
        <button type="button" class="btn btn-danger" data-osc-dialog-open="#logs-clear-dialog"><?php _e('Clear log'); ?></button>
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
