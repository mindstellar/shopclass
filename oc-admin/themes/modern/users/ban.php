<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function addHelp()
{
    echo '<p>'
         . __('Add, edit or delete ban rules. Keep in mind that ban rules prevent users to register, publish or comment on listings.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Users'); ?>
        <a href="<?php echo osc_admin_base_url(true) . '?page=users&action=settings'; ?>"
           class="ms-1 text-dark float-end" title="<?php _e('Settings'); ?>"><i class="bi bi-gear-fill"></i></a>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
        <a href="<?php echo osc_admin_base_url(true) . '?page=users&action=create_ban_rule'; ?>"
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
    return sprintf(__('Manage ban rules &raquo; %s'), $string);
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
            var banDelete = document.getElementById('dialog-ban-delete');

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
            [banDelete, bulkDialog].forEach(function (d) {
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

        // Called by the ban-rule row action links.
        function delete_dialog(item_id) {
            var d = document.getElementById('dialog-ban-delete');
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

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];


?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Manage ban rules'); ?></h2>
    <div class="relative">
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="users"/>

            <div id="bulk-actions">
                <div class="input-group input-group-sm">
                    <?php osc_print_bulk_actions('bulk_actions', 'action', __get('bulk_options'),
                                                 'select-box-extra'); ?>
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
                            <td colspan="5" class="text-center">
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
    <dialog id="dialog-ban-delete" class="osc-dialog osc-dialog-danger">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="users"/>
            <input type="hidden" name="action" value="delete_ban_rule"/>
            <input type="hidden" name="id[]" value=""/>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php _e('Delete rule'); ?>
                </p>
                <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this ban rule?'); ?></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button id="ban-delete-submit" type="submit" class="btn btn-danger btn-sm"><?php _e('Delete'); ?></button>
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