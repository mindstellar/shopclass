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

function addHelp()
{
    echo '<p>'
        . __('Add users who can manage your page. You can add admins or moderators: '
            . 'admins have access to the whole admin panel while moderators can only modify listings and see stats.')
        . '</p>';
}


osc_add_hook('help_box', 'addHelp');

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Admins &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

function customPageHeader()
{
    ?>
    <h1><?php _e('Users'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
        <a href="<?php echo osc_admin_base_url(true); ?>?page=admins&amp;action=add" class="ms-1 text-success float-end" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php _e('Add admin'); ?>"><i class="bi bi-plus-circle-fill"></i></a>
    </h1>
    <?php
}

osc_add_hook('admin_page_header', 'customPageHeader');

$iDisplayLength = __get('iDisplayLength');
$aData          = __get('aAdmins');

osc_current_admin_theme_path('parts/header.php'); ?>
<h2 class="render-title"><?php _e('Manage admins'); ?></h2>
<div class="relative">
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="admins" />
        <div id="bulk-actions">
            <div class="input-group input-group-sm">
                <?php osc_print_bulk_actions(
                    'bulk_actions',
                    'action',
                    __get('bulk_options'),
                    'select-box-extra form-select'
                ); ?>
                <input type="submit" id="bulk_apply" class="btn btn-primary" value="<?php echo osc_esc_html(__('Apply')); ?>" />
            </div>
        </div>
        <div class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                    <tr>
                        <th class="col-bulkactions"><input id="check_all" type="checkbox" /></th>
                        <th><?php _e('Username'); ?></th>
                        <th><?php _e('Name'); ?></th>
                        <th><?php _e('E-mail'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($aData['aaData']) > 0) { ?>
                        <?php foreach ($aData['aaData'] as $array) { ?>
                            <tr>
                                <?php foreach ($array as $key => $value) { ?>
                                    <td <?php if ($key == 0) {
                                            echo 'class="col-bulkactions"';
                                        } elseif ($key == 1) {
                                            echo ' echo data-col-name="' . __('Username') . '"';
                                        } elseif ($key == 2) {
                                            echo ' data-col-name="' . __('Name') . '"';
                                        } elseif ($key == 3) {
                                            echo ' data-col-name="' . __('E-mail') . '"';
                                        } else {
                                            echo  'data-col-name="' . ucfirst($key) . '"';
                                        } ?>>
                                        <?php echo $value; ?>
                                    </td>
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
            <div id="table-row-actions"></div><!-- used for table actions -->
        </div>
    </form>
</div>
<?php
function showingResults()
{
    $aData = __get('aAdmins');
    echo '<ul class="showing-results"><li><span>'
        . osc_pagination_showing((Params::getParam('iPage') - 1)
                * $aData['iDisplayLength'] + 1,
            ((Params::getParam('iPage') - 1) * $aData['iDisplayLength'])
                + count($aData['aaData']),
            $aData['iTotalDisplayRecords']
        ) . '</span></li></ul>';
}


osc_add_hook('before_show_pagination_admin', 'showingResults');
osc_show_pagination_admin($aData);
?>
<dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="admins" />
        <input type="hidden" name="action" value="delete" />
        <input type="hidden" name="id[]" value="" />
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php _e('Delete admin'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this admin?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" class="btn btn-danger btn-sm" type="submit"><?php _e('Delete'); ?></button>
        </div>
    </form>
</dialog>
<dialog id="bulkActionsModal" class="osc-dialog osc-dialog-danger">
    <div class="osc-dialog-body">
        <p class="osc-dialog-title"><?php _e('Bulk actions'); ?></p>
        <p class="osc-dialog-text"></p>
    </div>
    <div class="osc-dialog-actions">
        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
        <button id="bulkActionsSubmit" onclick="bulkActionsSubmit()" type="button" class="btn btn-danger btn-sm"><?php echo osc_esc_html(__('Delete')); ?></button>
    </div>
</dialog>
<script>
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal');
        var input = deleteModal.querySelector("input[name='id[]'], input[name='id']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        var checkAll = document.getElementById('check_all');
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                document.querySelectorAll('.col-bulkactions input').forEach(function (cb) {
                    cb.checked = checkAll.checked;
                });
            });
        }
    });
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>