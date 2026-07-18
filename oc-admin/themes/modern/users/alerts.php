<?php
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
    <dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="users"/>
            <input type="hidden" name="action" value="delete_alerts"/>
            <input type="hidden" name="alert_id[]" id="alert_id" value=""/>
            <input type="hidden" name="alert_user_id" value=""/>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo osc_esc_html(__('Delete alert')); ?>
                </p>
                <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this alert?'); ?></p>
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
            var deleteModal = document.getElementById("deleteModal");
            var input = deleteModal.querySelector("input[name='alert_id[]']");
            if (input) { input.value = id; }
            deleteModal.showModal();
            return false;
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>