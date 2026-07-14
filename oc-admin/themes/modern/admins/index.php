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
    <h1><?php _e('Admins'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
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
                    <tr class="table-secondary">
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
<form id="deleteModal" method="get" action="<?php echo osc_admin_base_url(true); ?>" class="modal fade static">
    <input type="hidden" name="page" value="admins" />
    <input type="hidden" name="action" value="delete" />
    <input type="hidden" name="id[]" value="" />
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <?php _e('Delete admin'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php _e('Are you sure you want to delete this admin?'); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                <button id="deleteSubmit" class="btn btn-sm btn-red" type="submit">
                    <?php echo __('Delete'); ?>
                </button>
            </div>
        </div>
    </div>
</form>
<div id="bulkActionsModal" class="modal fade static" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkActionsModalLabel"><?php _e('Bulk actions'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                <button id="bulkActionsSubmit" onclick="bulkActionsSubmit()" class="btn btn-sm btn-red"><?php echo osc_esc_html(__('Delete')); ?></button>
            </div>
        </div>
    </div>
</div>
<script>
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal')
        deleteModal.querySelector('input[name=\'id[]\']').value = id;
        (new bootstrap.Modal(document.getElementById('deleteModal'))).toggle()
        return false;
    }
</script>
<script type="text/javascript">
    $(document).ready(function() {
        // check_all bulkactions
        $("#check_all").change(function() {
            var isChecked = $(this).prop("checked");
            $('.col-bulkactions input').each(function() {
                if (isChecked == 1) {
                    this.checked = true;
                } else {
                    this.checked = false;
                }
            });
        });
    });
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>