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

use mindstellar\currency\CurrencyCode;

osc_admin_page(array(
    'section' => osc_mode_text(__('Listings'), __('Products')),
    'heading' => __('Currencies'),
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
    $row[] = \mindstellar\admin\ui\Form::bulkCell('code[]', $currency['pk_c_code'], $currency['pk_c_code']);

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
    $row[]   = (string) ($currency['i_decimals'] ?? CurrencyCode::decimals((string) $currency['pk_c_code']));
    $aData[] = $row;
}

osc_current_admin_theme_path('parts/header.php'); ?>
    <div class="relative">
        <div id="currencies-toolbar" class="table-toolbar">
        </div>
        <?php osc_admin_form_open(array(
            'id'         => 'datatablesForm',
            'page'   => 'settings',
            'action'     => 'currencies',
            'fields'     => array('type' => 'delete'),
            'horizontal' => false,
        )); ?>
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
            <?php
            $keys = array('bulkactions', 'code', 'name', 'symbol', 'decimals');
            osc_admin_table(array(
                'bulkactions' => array(
                    'label_html' => \mindstellar\admin\ui\Form::bulkHeader(),
                    'class'      => 'col-bulkactions',
                    'col_name'   => false,
                ),
                'code'        => __('Code'),
                'name'        => __('Name'),
                'symbol'      => array('label' => _x('Symbol', 'currency symbol'), 'col_name' => __('Currency symbol')),
                'decimals'    => __('Decimals'),
            ), array_map(static function ($row) use ($keys) {
                return array_map(static fn ($html) => array('html' => $html), array_combine($keys, $row));
            }, $aData), array('wrap' => 'actions'));
            ?>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
    </div>
    <?php osc_admin_confirm_dialog(array(
        'id'         => 'deleteModal',
        'method'     => 'post',
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