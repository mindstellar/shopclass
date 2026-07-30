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
        . __('From here, you can edit or delete the listings reported by users (spam, misclassified, duplicate, expired, offensive). You can also delete the report if you consider it mistaken.')
        . '</p>';
}

osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Listings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
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
    return sprintf(__('Reported listings &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

$aData = __get('aData');

$columns   = $aData['aColumns'];
$rows      = $aData['aRows'];
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');
function showingResults()
{
    $aData = __get('aData');
    echo '<ul class="showing-results"><li><span>' . osc_pagination_showing((Params::getParam('iPage') - 1)
                                                                           * $aData['iDisplayLength'] + 1,
                                                                           ((Params::getParam('iPage') - 1) * $aData['iDisplayLength'])
                                                                           + count($aData['aRows']),
                                                                           $aData['iTotalDisplayRecords'],
                                                                           $aData['iTotalRecords']) . '</span></li></ul>';
}


osc_add_hook('before_show_pagination_admin', 'showingResults');
osc_current_admin_theme_path('parts/header.php'); ?>
<h2 class="render-title"><?php _e('Reported listings'); ?></h2>
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
        <div id="bulk-actions">
            <div class="input-group input-group-sm">
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
                <input type="submit" id="bulk_apply" class="btn btn-primary" value="<?php echo osc_esc_html(__('Apply')); ?>" />
            </div>
        </div>
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
                                          // `items_processing_row` filter still hands plugins the plain word. ?>
                                    <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php
                                        echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                                    ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="<?php echo count($columns); ?>" class="text-center">
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
osc_show_pagination_admin($aData);
?>
<dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="items" />
        <input type="hidden" name="action" value="delete" />
        <input type="hidden" name="id[]" value="" />
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php _e('Delete listing'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this listing?'); ?></p>
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
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>