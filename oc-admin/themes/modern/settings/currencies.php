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
         . __("Add new currencies or edit existing currencies so users can publish listings in their country's currency.")
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
        <a href="<?php echo osc_admin_base_url(true) . '?page=settings&action=currencies&type=add'; ?>"
           class="ms-1 text-success float-end" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php _e('Add'); ?>"><i
                    class="bi bi-plus-circle-fill"></i></a>
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
    return sprintf(__('Currencies &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

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
    <h2 class="render-title"><?php _e('Currencies'); ?></h2>
    <div class="relative">
        <div id="currencies-toolbar" class="table-toolbar">
        </div>
        <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="currencies"/>
            <input type="hidden" name="type" value="delete"/>
            <div id="bulk-actions">
                <div class="input-group input-group-sm">
                    <select id="bulk_actions" name="bulk_actions" class="select-box-extra form-select">
                        <option value=""><?php _e('Bulk actions'); ?></option>
                        <option value="delete_all"
                                data-dialog-content="<?php printf(
                                    __('Are you sure you want to %s the selected currencies?'),
                                    strtolower(__('Delete'))
                                ); ?>"><?php _e('Delete'); ?>
                        </option>
                    </select> <input type="submit" id="bulk_apply" class="btn btn-primary"
                                     value="<?php echo osc_esc_html(__('Apply')); ?>"/>
                </div>
            </div>
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
    <dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="currencies"/>
            <input type="hidden" name="type" value="delete"/>
            <input type="hidden" name="code" value=""/>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php _e('Delete currency'); ?>
                </p>
                <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this currency?'); ?></p>
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

        function delete_dialog(id) {
            var deleteModal = document.getElementById("deleteModal");
            var input = deleteModal.querySelector("input[name='code']");
            if (input) { input.value = id; }
            deleteModal.showModal();
            return false;
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>