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
    'title'   => __('Reported listings'),
    'help'    => __('From here, you can edit or delete the listings reported by users (spam, misclassified, duplicate, expired, offensive). You can also delete the report if you consider it mistaken.'),
));

$aData = __get('aData');

$columns   = $aData['aColumns'];
$rows      = $aData['aRows'];
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');
osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_page_head(__('Reported listings')); ?>
<div class="relative">
    <div id="listing-toolbar">
        <div class="input-group input-group-sm">
            <form method="get" action="<?php echo osc_admin_base_url(true); ?>" class="inline" nocsrf>
                <?php foreach (Params::getParamsAsArray('get') as $key => $value) { ?>
                    <?php if ($key !== 'iDisplayLength') { ?>
                        <input type="hidden" name="<?php echo osc_esc_html($key); ?>" value="<?php echo osc_esc_html($value); ?>" />
                    <?php }
                    } ?>
                <select name="iDisplayLength" class="form-select form-select-sm " onchange="this.form.submit();">
                    <option value="10"><?php printf(__('%d Listings'), 10); ?></option>
                    <option value="25" <?php if (Params::getParam('iDisplayLength') == 25) {
                        echo 'selected';
                    } ?>><?php printf(__('%d Listings'), 25); ?></option>
                    <option value="50" <?php if (Params::getParam('iDisplayLength') == 50) {
                        echo 'selected';
                    } ?>><?php printf(__('%d Listings'), 50); ?></option>
                    <option value="100" <?php if (Params::getParam('iDisplayLength') == 100) {
                        echo 'selected';
                    } ?>><?php printf(__('%d Listings'), 100); ?></option>
                </select>
            </form>
            <?php if ($sort !== 'date') { ?>
                <a id="btn-reset-filters" class="btn btn-dim" href="<?php
                   echo osc_admin_base_url(true); ?>?page=items&action=items_reported"><?php _e('Reset filters'); ?></a>
            <?php } ?>
        </div>
    </div>
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="items" />
        <input type="hidden" name="action" value="bulk_actions" />
        <?php osc_admin_bulk_actions(array('name' => 'bulk_actions', 'options_html' => static function () { ?>
            <select id="bulk_actions" name="bulk_actions" class="select-box-extra form-select">
                <option value=""><?php _e('Bulk actions'); ?></option>
                <option value="delete_all" data-dialog-content="<?php printf(
                    __('Are you sure you want to %s the selected items?'),
                    strtolower(__('Delete'))
                ); ?>"><?php _e('Delete'); ?></option>
                <option value="clear_reports_all" data-dialog-content="<?php _e('Are you sure you want to clear the deduplicated reports (and all reportings) of the selected items? This also resets their report-threshold auto-block state.'); ?>"><?php _e('Clear reports'); ?></option>
                <option value="clear_all" data-dialog-content="<?php _e('Are you sure you want to clear all the reportings of the selected items?'); ?>"><?php _e('Clear All'); ?></option>
                <option value="clear_spam_all" data-dialog-content="<?php _e('Are you sure you want to clear the spam reportings of the selected items?'); ?>"><?php _e('Clear Spam'); ?></option>
                <option value="clear_bad_all" data-dialog-content="<?php _e('Are you sure you want to clear the misclassified reportings of the selected items?'); ?>"><?php _e('Clear Missclassified'); ?></option>
                <option value="clear_dupl_all" data-dialog-content="<?php _e('Are you sure you want to clear the duplicated reportings of the selected items?'); ?>"><?php _e('Clear Duplicated'); ?></option>
                <option value="clear_expi_all" data-dialog-content="<?php _e('Are you sure you want to clear the expired reportings of the selected items?'); ?>"><?php _e('Clear Expired'); ?></option>
                <option value="clear_offe_all" data-dialog-content="<?php _e('Are you sure you want to clear the offensive reportings of the selected items?'); ?>"><?php _e('Clear Offensive'); ?></option>
            </select>
        <?php })); ?>
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
                                    <?php // Status becomes a badge. Wrapped here in the theme, not in the DataTable, so the
                                          // `items_processing_row` filter still hands plugins the plain word.?>
                                    <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php
                                        echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                                    ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } else {
                        osc_admin_table_empty(count($columns), array(
                            'icon'  => 'bi-flag',
                            'title' => __('No reported listings'),
                            'text'  => __('Listings reported by visitors for spam, being misclassified, duplicate, expired, or offensive content show up here.'),
                        ));
                    } ?>
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
    'id'      => 'deleteModal',
    'method'  => 'get',
    'fields'  => array('page' => 'items', 'action' => 'delete', 'id[]' => ''),
    'title'   => __('Delete listing'),
    'text'    => __('This permanently deletes the listing and its photos. This cannot be undone.'),
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
<script type="text/javascript">
    // autocomplete users
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>