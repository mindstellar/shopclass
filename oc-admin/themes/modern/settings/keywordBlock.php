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
    echo '<p>'
         . __('Listings containing a blocked keyword are quarantined (flagged spam and hidden) as soon as they '
              . 'are posted or edited, and the matched keyword is recorded so you can see why. Enable the filter '
              . 'below before it does anything.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
        <a href="<?php echo osc_admin_base_url(true) . '?page=settings&action=keyword_block_add'; ?>"
           class="text-success ms-1 float-end" title="<?php _e('Add new'); ?>">
            <i class="bi bi-plus-circle-fill"></i>
        </a>
    </h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Keyword blocklist &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('datatablesForm');
            var bulkDialog = document.getElementById('dialog-bulk-actions');
            var keywordDelete = document.getElementById('dialog-keyword-delete');

            // Select-all toggles every row checkbox.
            var checkAll = document.getElementById('check_all');
            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    document.querySelectorAll('.col-bulkactions input').forEach(function (cb) {
                        cb.checked = checkAll.checked;
                    });
                });
            }

            // Cancel buttons and a backdrop click close their <dialog>.
            document.querySelectorAll('[data-osc-dialog-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var d = btn.closest('dialog');
                    if (d) { d.close(); }
                });
            });
            [keywordDelete, bulkDialog].forEach(function (d) {
                if (d) {
                    d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
                }
            });

            // Bulk actions: confirm in a dialog before the form is submitted.
            var bulkSubmit = document.getElementById('bulk-actions-submit');
            var bulkCancel = document.getElementById('bulk-actions-cancel');
            if (bulkCancel) { bulkCancel.addEventListener('click', function () { bulkDialog.close(); }); }
            // form.submit() is the native call, which does NOT re-fire the submit
            // handler below — so confirming submits straight through.
            if (bulkSubmit) { bulkSubmit.addEventListener('click', function () { form.submit(); }); }
            if (form) {
                form.addEventListener('submit', function (e) {
                    var sel = document.getElementById('bulk_actions');
                    if (!sel || sel.value === '') { e.preventDefault(); return; }
                    e.preventDefault();
                    var opt = sel.options[sel.selectedIndex];
                    bulkDialog.querySelector('.form-row').textContent = opt.getAttribute('data-dialog-content') || '';
                    bulkSubmit.textContent = opt.text;
                    bulkDialog.showModal();
                });
            }
        });

        // Called by the keyword row action links.
        function delete_dialog(item_id) {
            var d = document.getElementById('dialog-keyword-delete');
            var input = d.querySelector("input[name='id[]']");
            if (input) { input.value = item_id; }
            d.showModal();
            return false;
        }
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');
$prefs     = __get('moderation_prefs');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

$scopeOptions = array(
    'all'         => __('Title and description'),
    'title'       => __('Title only'),
    'description' => __('Description only'),
    'meta'        => __('Custom fields'),
);

?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Keyword blocklist'); ?></h2>

    <div id="keyword-block-settings">
        <h3 class="render-title"><?php _e('Moderation'); ?></h3>
        <form name="keyword_block_prefs_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="keyword_block_prefs_post"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Keyword filter'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <input type="checkbox" id="keyword_spam_enabled" name="keyword_spam_enabled" value="1"
                                <?php echo(!empty($prefs['keyword_spam_enabled']) ? 'checked="checked"' : ''); ?> />
                            <label for="keyword_spam_enabled"><?php _e('Check new and edited listings against the keyword blocklist below'); ?></label>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('On a match'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <input type="checkbox" id="keyword_spam_hard_block" name="keyword_spam_hard_block" value="1"
                                <?php echo(!empty($prefs['keyword_spam_hard_block']) ? 'checked="checked"' : ''); ?> />
                            <label for="keyword_spam_hard_block"><?php _e('Reject the listing outright instead of quarantining it for review'); ?></label>
                        </div>
                        <div class="help-box">
                            <?php _e('Off by default: a match is quarantined (flagged spam and hidden) so it can be reviewed and reversed. Turn this on to reject the post before it is ever saved.'); ?>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Report auto-block'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <input type="checkbox" id="report_autoblock" name="report_autoblock" value="1"
                                <?php echo(!empty($prefs['report_autoblock']) ? 'checked="checked"' : ''); ?> />
                            <label for="report_autoblock"><?php _e('Automatically hide a listing once enough distinct visitors have reported it'); ?></label>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Report threshold'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-medium" id="report_threshold" name="report_threshold"
                               value="<?php echo osc_esc_html($prefs['report_threshold']); ?>"/>
                        <div class="help-box">
                            <?php _e('Number of distinct reporters (one vote per person) that auto-hides a listing.'); ?>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" value="<?php echo osc_esc_html(__('Save changes')); ?>" class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
    </div>

    <div id="keyword-block-import" class="separate-top">
        <h3 class="render-title"><?php _e('Import keyword list'); ?></h3>
        <p><?php _e('Paste a comma-separated keyword list — useful for migrating an existing list from a theme or plugin. Wrap a keyword in asterisks (e.g. *viagra*) to import it as a substring match. Keywords already on file are skipped.'); ?></p>
        <form name="keyword_block_import_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="keyword_block_import_post"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Keywords'); ?></div>
                    <div class="form-controls">
                        <textarea id="import_list" name="import_list" rows="4"
                                  style="width:100%;max-width:640px;"
                                  placeholder="viagra, *casino*, call girl"></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Where to match'); ?></div>
                    <div class="form-controls">
                        <select class="form-select form-select-sm" name="import_scope">
                            <?php foreach ($scopeOptions as $value => $label) { ?>
                                <option value="<?php echo osc_esc_html($value); ?>"><?php echo osc_esc_html($label); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" value="<?php echo osc_esc_html(__('Import')); ?>" class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
    </div>

    <h3 class="render-title separate-top"><?php _e('Blocked keywords'); ?></h3>
    <div class="relative">
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>

            <div id="bulk-actions">
                <div class="input-group input-group-sm">
                    <?php osc_print_bulk_actions('bulk_actions', 'action', __get('bulk_options'), 'select-box-extra'); ?>
                    <input type="submit" id="bulk_apply" class="btn btn-primary" value="<?php echo osc_esc_html(__('Apply')); ?>"/>
                </div>
            </div>
            <div class="table-contains-actions">
                <table class="table" cellpadding="0" cellspacing="0">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $k => $v) {
                            if ($direction === 'desc') {
                                echo '<th class="col-' . $k . ' ' . ($sort === $k ? ('sorting_desc') : '') . '">' . $v . '</th>';
                            } else {
                                echo '<th class="col-' . $k . ' ' . ($sort === $k ? ('sorting_asc') : '') . '">' . $v . '</th>';
                            }
                        } ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) > 0) { ?>
                        <?php foreach ($rows as $key => $row) { ?>
                            <tr>
                                <?php foreach ($row as $k => $v) { ?>
                                    <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php echo $v; ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="4" class="text-center">
                                <p><?php _e('No data available in table'); ?></p>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <div id="table-row-actions"></div> <!-- used for table actions -->
            </div>
        </form>
    </div>
<?php
function showingResults()
{
    $aData = __get('aData');
    echo '<ul class="showing-results"><li><span>'
         . osc_pagination_showing((Params::getParam('iPage') - 1)
                                  * $aData['iDisplayLength'] + 1,
                                  ((Params::getParam('iPage') - 1) * $aData['iDisplayLength'])
                                  + count($aData['aRows']),
                                  $aData['iTotalDisplayRecords'], $aData['iTotalRecords'])
         . '</span></li></ul>';
}


osc_add_hook('before_show_pagination_admin', 'showingResults');
osc_show_pagination_admin($aData);
?>
    <dialog id="dialog-keyword-delete" class="osc-dialog osc-dialog-danger">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="keyword_block_delete"/>
            <input type="hidden" name="id[]" value=""/>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php _e('Delete keyword'); ?>
                </p>
                <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this keyword?'); ?></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button id="keyword-delete-submit" type="submit" class="btn btn-danger btn-sm"><?php _e('Delete'); ?></button>
            </div>
        </form>
    </dialog>
    <dialog id="dialog-bulk-actions" class="osc-dialog">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><?php _e('Bulk actions'); ?></p>
            <p class="osc-dialog-text form-row"></p>
        </div>
        <div class="osc-dialog-actions">
            <button id="bulk-actions-cancel" type="button" class="btn btn-dim btn-sm"><?php _e('Cancel'); ?></button>
            <button id="bulk-actions-submit" type="button" class="btn btn-danger btn-sm"><?php echo osc_esc_html(__('Delete')); ?></button>
        </div>
    </dialog>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
