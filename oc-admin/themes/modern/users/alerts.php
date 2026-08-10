<?php
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

osc_admin_page(array(
    'section' => __('Users'),
    'title'   => __('Manage alerts'),
    'help'    => __('Add, edit or delete information associated to alerts.'),
));

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Manage alerts')); ?>
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

            <?php osc_admin_bulk_actions(array(
                'name'         => 'alert_action',
                'options_html' => static function () { ?>
                    <select name="alert_action" id="bulk_actions" class="form-select select-box-extra">
                        <option value=""><?php _e('Bulk actions'); ?></option>
                        <option value="activate"
                                data-dialog-content="<?php printf(
                                    __('Are you sure you want to %s the selected alerts?'),
                                    strtolower(__('Activate'))
                                ); ?>"><?php _e('Activate'); ?></option>
                        <option value="deactivate"
                                data-dialog-content="<?php printf(
                                    __('Are you sure you want to %s the selected alerts?'),
                                    strtolower(__('Deactivate'))
                                ); ?>"><?php _e('Deactivate'); ?></option>
                        <option value="delete"
                                data-dialog-content="<?php printf(
                                    __('Are you sure you want to %s the selected alerts?'),
                                    strtolower(__('Delete'))
                                ); ?>"><?php _e('Delete'); ?></option>
                    </select>
                <?php },
            )); ?>
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
                    <?php } elseif (Params::getParam('sSearch') !== '') { ?>
                        <?php osc_admin_table_empty(count($columns), array(
                            'icon'  => 'bi-bell',
                            'title' => __('No results for this filter'),
                        )); ?>
                    <?php } else { ?>
                        <?php osc_admin_table_empty(count($columns), array(
                            'icon'  => 'bi-bell',
                            'title' => __('No alerts yet'),
                            'text'  => __('Alerts notify a user by email when a new listing matches their saved search.'),
                        )); ?>
                    <?php } ?>
                    </tbody>
                </table>
                <div id="table-row-actions"></div> <!-- used for table actions -->
            </div>
        </form>
    </div>
<?php
osc_admin_pagination($aData);
?>
    <?php osc_admin_confirm_dialog(array(
        'id'         => 'deleteModal',
        'method'     => 'get',
        'fields'     => array('page' => 'users', 'action' => 'delete_alerts', 'alert_id[]' => '', 'alert_user_id' => ''),
        'title'      => __('Delete alert'),
        'text'       => __('This removes the saved alert; the user will no longer be notified when a new listing matches it.'),
        'confirm'    => __('Delete'),
        'confirm_id' => 'deleteSubmit',
    )); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
    <div id="more-tooltip"></div>
    <script>

        function delete_alert(id) {
            var deleteModal = document.getElementById("deleteModal");
            var input = deleteModal.querySelector("input[name='alert_id[]']");
            if (input) { input.value = id; }
            deleteModal.showModal();
            return false;
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>