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
    'section' => __('Settings'),
    'title'   => __('Keyword blocklist'),
    'help'    => __('Listings containing a blocked keyword are quarantined (flagged spam and hidden) as soon as they '
                    . 'are posted or edited, and the matched keyword is recorded so you can see why. Enable the filter '
                    . 'below before it does anything.'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=settings&action=keyword_block_add',
            'title' => __('Add new'),
        ),
    ),
));

//customize Head
/**
 * Emit the blocked-keywords list's script: row selection, bulk actions and the delete dialogs.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('datatablesForm');
            var bulkDialog = document.getElementById('dialog-bulk-actions');
            var keywordDelete = document.getElementById('dialog-keyword-delete');

            // Select-all toggles every row checkbox.

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
                    bulkDialog.querySelector('.osc-dialog-text').textContent = opt.getAttribute('data-dialog-content') || '';
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
$prefsForm = __get('moderation_form');

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
    <?php osc_admin_page_head(__('Keyword blocklist')); ?>

    <div id="keyword-block-settings">
        <?php osc_admin_form_section(__('Moderation')); ?>
        <?php osc_admin_settings_form($prefsForm['id'], $prefsForm); ?>
    </div>

    <div id="keyword-block-import" class="separate-top">
        <?php osc_admin_form_section(__('Import keyword list')); ?>
        <p><?php _e('Paste a comma-separated keyword list — useful for migrating an existing list from a theme or plugin. Wrap a keyword in asterisks (e.g. *viagra*) to import it as a substring match. Keywords already on file are skipped.'); ?></p>
        <?php osc_admin_form_open(array(
            'name'   => 'keyword_block_import_form',
            'page'   => 'settings',
            'action' => 'keyword_block_import_post',
        )); ?>
                <?php
                osc_admin_textarea(array(
                    'id'          => 'import_list',
                    'name'        => 'import_list',
                    'label'       => __('Keywords'),
                    'rows'        => 4,
                    'width'       => 'key',
                    'placeholder' => 'viagra, *casino*, call girl',
                ));
                osc_admin_select(array(
                    'name'    => 'import_scope',
                    'label'   => __('Where to match'),
                    'options' => $scopeOptions,
                )); ?>
                <?php osc_admin_form_close(array(
                    array('label' => __('Import'), 'type' => 'submit'),
                )); ?>
    </div>

    <?php osc_admin_form_section(__('Blocked keywords'), array('spaced' => true)); ?>
    <div class="relative">
        <?php osc_admin_form_open(array(
            'id'         => 'datatablesForm',
            'page'       => 'settings',
            'horizontal' => false,
        )); ?>

            <?php osc_admin_bulk_actions(array('options' => __get('bulk_options'))); ?>
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
                    <?php } else {
                        osc_admin_table_empty(count($columns), array(
                            'icon'  => 'bi-chat-left-text',
                            'title' => __('No blocked keywords yet'),
                            'text'  => __('Add a keyword above, or import a list, to start quarantining matching listings.'),
                        ));
                    } ?>
                    </tbody>
                </table>
                <div id="table-row-actions"></div> <!-- used for table actions -->
            </div>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
    </div>
<?php
osc_admin_pagination($aData);
?>
    <?php osc_admin_confirm_dialog(array(
        'id'         => 'dialog-keyword-delete',
        'method'     => 'get',
        'fields'     => array('page' => 'settings', 'action' => 'keyword_block_delete', 'id[]' => ''),
        'title'      => __('Delete keyword'),
        'text'       => __('Listings already flagged by this keyword stay as they are; only future matching stops.'),
        'confirm'    => __('Delete'),
        'confirm_id' => 'keyword-delete-submit',
    )); ?>
    <dialog id="dialog-bulk-actions" class="osc-dialog">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><?php _e('Bulk actions'); ?></p>
            <p class="osc-dialog-text"></p>
        </div>
        <div class="osc-dialog-actions">
            <button id="bulk-actions-cancel" type="button" class="btn btn-dim btn-sm"><?php _e('Cancel'); ?></button>
            <button id="bulk-actions-submit" type="button" class="btn btn-danger btn-sm"><?php echo osc_esc_html(__('Delete')); ?></button>
        </div>
    </dialog>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
