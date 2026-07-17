<?php
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
    echo '<p>' . __('Add, edit or delete information associated to alerts.') . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Alerts'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
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
    return sprintf(__('Manage alerts &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Manage alerts'); ?></h2>
    <div class="relative">
        <div id="users-toolbar" class="table-toolbar">
            <div class="float-right">
                <form method="get" action="<?php echo osc_admin_base_url(true); ?>" id="shortcut-filters"
                      class="inline">
                    <input type="hidden" name="page" value="users"/>
                    <input type="hidden" name="action" value="alerts"/>
                    <div class="btn-group btn-group-sm">
                        <input
                                id="fPattern" type="text" name="sSearch"
                                value="<?php echo osc_esc_html(Params::getParam('sSearch')); ?>"
                                class="input-text input-actions"/>
                        <button type="submit" class="btn btn-primary" title="<?php echo osc_esc_html(__('Find')); ?>"><i
                                    class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </div>
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="users"/>
            <input type="hidden" name="action" id="action" value="status_alerts"/>
            <input type="hidden" name="status" id="status" value="0"/>

            <div id="bulk-actions">
                <div class="input-group input-group-sm">
                    <select name="alert_action" id="bulk_actions" class="form-select select-box-extra">
                        <option value=""><?php _e('Bulk Actions'); ?></option>
                        <option value="activate"
                                data-dialog-content="<?php printf(__('Are you sure you want to %s the selected alerts?'),
                                                                  strtolower(__('Activate'))); ?>"><?php _e('Activate'); ?></option>
                        <option value="deactivate"
                                data-dialog-content="<?php printf(__('Are you sure you want to %s the selected alerts?'),
                                                                  strtolower(__('Deactivate'))); ?>"><?php _e('Deactivate'); ?></option>
                        <option value="delete"
                                data-dialog-content="<?php printf(__('Are you sure you want to %s the selected alerts?'),
                                                                  strtolower(__('Delete'))); ?>"><?php _e('Delete'); ?></option>
                    </select> <input type="submit" id="bulk_apply" class="btn btn-primary"
                                     value="<?php echo osc_esc_html(__('Apply')); ?>"/>
                </div>
            </div>
            <div class="table-contains-actions">
                <table class="table" cellpadding="0" cellspacing="0">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $k => $v) {
                            if ($direction === 'desc') {
                                echo '<th class="col-' . $k . ' ' . ($sort === $k ? ('sorting_desc')
                                        : '') . '">' . $v . '</th>';
                            } else {
                                echo '<th class="col-' . $k . ' ' . ($sort === $k ? ('sorting_asc')
                                        : '') . '">' . $v . '</th>';
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
    <form id="deleteModal" method="get" action="<?php echo osc_admin_base_url(true); ?>"
          class="modal fade static">
        <input type="hidden" name="page" value="users"/>
        <input type="hidden" name="action" value="delete_alerts"/>
        <input type="hidden" name="alert_id[]" id="alert_id" value=""/>
        <input type="hidden" name="alert_user_id" value=""/>
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo osc_esc_html(__('Delete alert')); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php _e('Are you sure you want to delete this alert?'); ?>
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
                    <button id="bulkActionsSubmit" onclick="bulkActionsSubmit()"
                            class="btn btn-sm btn-red"><?php echo osc_esc_html(__('Delete')); ?></button>
                </div>
            </div>
        </div>
    </div>
    <div id="more-tooltip"></div>
    <script>
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

        function delete_alert(id) {
            var deleteModal = document.getElementById("deleteModal")
            deleteModal.querySelector("input[name='alert_id[]']").value = id;
            (new bootstrap.Modal(document.getElementById('deleteModal'))).toggle()
            return false;
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>