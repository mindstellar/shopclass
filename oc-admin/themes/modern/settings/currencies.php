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
    'section' => __('Listings'),
    'title'   => __('Currencies'),
    'help'    => __("Add new currencies or edit existing currencies so users can publish listings in their country's currency."),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=settings&action=currencies&type=add',
            'title' => __('Add'),
        ),
    ),
));

$aCurrencies = __get('aCurrencies');

$aData = array();
foreach ($aCurrencies as $currency) {
    $row   = array();
    $row[] = '<input type="checkbox" name="code[]" value="' . osc_esc_html($currency['pk_c_code']) . '" />';

    $options   = array();
    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=settings&amp;action=currencies&amp;type=edit&amp;code='
                 . $currency['pk_c_code'] . '">' . __('Edit') . '</a>';
    $options[] =
        '<a onclick="return delete_dialog(\'' . $currency['pk_c_code'] . '\');" href="' . osc_admin_base_url(true)
        . '?page=settings&amp;action=currencies&amp;type=delete&amp;code=' . $currency['pk_c_code'] . '">'
        . __('Delete') . '</a>';

    $actions = '<div class="actions"><ul><li>' . implode('</li><li>', $options) . '</li></ul></div>';
    $row[]   = $currency['pk_c_code'] . $actions;
    $row[]   = $currency['s_name'];
    $row[]   = $currency['s_description'];
    $aData[] = $row;
}

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Currencies')); ?>
    <div class="relative">
        <div id="currencies-toolbar" class="table-toolbar">
        </div>
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="currencies"/>
            <input type="hidden" name="type" value="delete"/>
            <?php osc_admin_bulk_actions(array('options_html' => static function () { ?>
                <select id="bulk_actions" name="bulk_actions" class="select-box-extra form-select">
                    <option value=""><?php _e('Bulk actions'); ?></option>
                    <option value="delete_all"
                            data-dialog-content="<?php printf(
                                __('Are you sure you want to %s the selected currencies?'),
                                strtolower(__('Delete'))
                            ); ?>"><?php _e('Delete'); ?>
                    </option>
                </select>
            <?php })); ?>
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                <tr>
                    <th class="col-bulkactions"><input id="check_all" type="checkbox"/></th>
                    <th><?php _e('Code'); ?></th>
                    <th><?php _e('Name'); ?></th>
                    <th><?php _e('Description'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($aData as $array) { ?>
                    <tr>
                        <?php foreach ($array as $key => $value) { ?>
                            <td <?php if ($key == 0) {
                                echo 'class="col-bulkactions"';
                            } elseif ($key === 1) {
                                echo 'data-col-name ='. __('Code');
                            } elseif ($key === 2) {
                                echo 'data-col-name ='. __('Name');
                            } elseif ($key === 3) {
                                echo 'data-col-name ='. __('Description');
                            } else {
                                echo 'data-col-name="'.ucfirst($key).'"';
                            } ?>>
                            <?php echo $value; ?>
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </form>
    </div>
    <?php osc_admin_confirm_dialog(array(
        'id'         => 'deleteModal',
        'method'     => 'get',
        'fields'     => array('page' => 'settings', 'action' => 'currencies', 'type' => 'delete', 'code' => ''),
        'title'      => __('Delete currency'),
        'text'       => __('Listings priced in this currency keep their stored amount but lose a way to display it consistently.'),
        'confirm'    => __('Delete'),
        'confirm_id' => 'deleteSubmit',
    )); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
    <script>

        function delete_dialog(id) {
            var deleteModal = document.getElementById("deleteModal");
            var input = deleteModal.querySelector("input[name='code']");
            if (input) { input.value = id; }
            deleteModal.showModal();
            return false;
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>