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
    'title'   => __('Manage ban rules'),
    'help'    => __('Add, edit or delete ban rules. Keep in mind that ban rules prevent users to register, publish or comment on listings.'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=users&action=create_ban_rule',
            'title' => __('Add new'),
        ),
        array(
            'icon'  => 'bi-gear-fill',
            'url'   => osc_admin_base_url(true) . '?page=users&action=settings',
            'title' => __('Settings'),
        ),
    ),
));

//customize Head
/**
 * Emit the ban-rule list's script: row selection, bulk actions and the delete dialogs.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
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
    <?php osc_admin_page_head(__('Manage ban rules')); ?>
    <div class="relative">
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="users"/>

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
                    <?php } else { ?>
                        <?php osc_admin_table_empty(count($columns), array(
                            'icon'   => 'bi-shield-slash',
                            'title'  => __('No ban rules yet'),
                            'text'   => __('Ban rules block registration, listings or comments matching an IP or e-mail pattern.'),
                            'action' => array(
                                'label'   => __('Add new'),
                                'url'     => osc_admin_base_url(true) . '?page=users&action=create_ban_rule',
                                'variant' => 'primary',
                            ),
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
        'id'         => 'dialog-ban-delete',
        'method'     => 'get',
        'fields'     => array('page' => 'users', 'action' => 'delete_ban_rule', 'id[]' => ''),
        'title'      => __('Delete rule'),
        'text'       => __('Users, listings and comments matching this rule will no longer be blocked.'),
        'confirm'    => __('Delete'),
        'confirm_id' => 'ban-delete-submit',
    )); ?>
    <?php osc_admin_bulk_confirm_dialog(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>