<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_admin_page(array(
    'section' => __('Users'),
    'heading' => __('Manage admins'),
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
?>
<div class="relative">
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="admins" />
        <?php osc_admin_bulk_actions(array('options' => __get('bulk_options'))); ?>
        <?php
        $keys = array('bulkactions', 'username', 'name', 'mail');
        osc_admin_data_table(array(
            'aColumns' => array_combine($keys, array(
                \mindstellar\admin\ui\Form::bulkHeader(),
                __('Username'),
                __('Name'),
                __('E-mail'),
            )),
            'aRows'    => array_map(static fn ($row) => array_combine($keys, $row), $aData['aaData']),
        ), array(
            'empty' => array(
                'title'  => __('No admins yet'),
                'text'   => __('Admins manage the whole panel; moderators only handle listings and stats.'),
                'action' => array(
                    'label'   => __('Add admin'),
                    'url'     => osc_admin_base_url(true) . '?page=admins&amp;action=add',
                    'variant' => 'primary',
                ),
            ),
        ));
        ?>
    </form>
</div>
<?php
osc_admin_pagination($aData);

osc_admin_confirm_dialog(array(
    'id'      => 'deleteModal',
    'method'  => 'post',
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