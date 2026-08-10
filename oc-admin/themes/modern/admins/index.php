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

osc_admin_page(array(
    'section' => __('Users'),
    'title'   => __('Admins'),
    'help'    => __('Add users who can manage your page. You can add admins or moderators: '
                    . 'admins have access to the whole admin panel while moderators can only modify listings and see stats.'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=admins&amp;action=add',
            'title' => __('Add admin'),
        ),
    ),
));

$iDisplayLength = __get('iDisplayLength');
$aData          = __get('aAdmins');

osc_current_admin_theme_path('parts/header.php');
osc_admin_page_head(__('Manage admins')); ?>
<div class="relative">
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="admins" />
        <?php osc_admin_bulk_actions(array('options' => __get('bulk_options'))); ?>
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
                    <?php } else {
                        osc_admin_table_empty(4, array(
                            'icon'   => 'bi-person-badge',
                            'title'  => __('No admins yet'),
                            'text'   => __('Admins manage the whole panel; moderators only handle listings and stats.'),
                            'action' => array(
                                'label'   => __('Add admin'),
                                'url'     => osc_admin_base_url(true) . '?page=admins&amp;action=add',
                                'variant' => 'primary',
                            ),
                        ));
                    } ?>
                </tbody>
            </table>
            <div id="table-row-actions"></div><!-- used for table actions -->
        </div>
    </form>
</div>
<?php
osc_admin_pagination($aData);

osc_admin_confirm_dialog(array(
    'id'      => 'deleteModal',
    'method'  => 'get',
    'fields'  => array('page' => 'admins', 'action' => 'delete', 'id[]' => ''),
    'title'   => __('Delete admin'),
    'text'    => __('This removes the account from the admin panel. Their listings and the site itself are untouched.'),
    'confirm' => __('Delete'),
)); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
<script>
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal');
        var input = deleteModal.querySelector("input[name='id[]'], input[name='id']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>