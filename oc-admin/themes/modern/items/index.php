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
    'title'   => __('Manage listings'),
    'help'    => __('Manage all the listings on your site: edit, delete or block the latest listings published. You can also filter by several parameters: user, region, city, etc.'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=items&amp;action=post',
            'title' => __('Add listing'),
        ),
        array(
            'icon'  => 'bi-gear-fill',
            'url'   => osc_admin_base_url(true) . '?page=items&amp;action=settings',
            'title' => __('Settings'),
        ),
    ),
));

//customize Head
function customHead()
{
    ItemForm::location_javascript_new('admin'); ?>
    <script type="text/javascript">
        // autocomplete users
        document.addEventListener('DOMContentLoaded', function () {

            document.querySelectorAll('#user, #fUser').forEach(function (el) {
                oscAutocomplete(el, {
                    source: "<?php echo osc_admin_base_url(true); ?>?page=ajax&action=userajax",
                    minLength: 0,
                    onSearch: function () {
                        ['userId', 'fUserId'].forEach(function (id) {
                            var f = document.getElementById(id);
                            if (f) { f.value = ''; }
                        });
                    },
                    onSelect: function (item) {
                        if (item.id === '') { return false; }
                        ['userId', 'fUserId'].forEach(function (id) {
                            var f = document.getElementById(id);
                            if (f) { f.value = item.id; }
                        });
                    }
                });
            });

        });
    </script>
<?php
}

osc_add_hook('admin_header', 'customHead', 10);

$categories  = __get('categories');
$withFilters = __get('withFilters');

$iDisplayLength = __get('iDisplayLength');

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_page_head(__('Manage listings')); ?>
<div class="relative">
    <div id="listing-toolbar">
        <div class="d-flex justify-content-end gap-1">
            <form method="get" action="<?php echo osc_admin_base_url(true); ?>" id="shortcut-filters">
                <input type="hidden" name="page" value="items" />
                <input type="hidden" name="iDisplayLength" value="<?php echo $iDisplayLength; ?>" />
                <div class="input-group-sm input-group">
                    <?php if ($withFilters) { ?>
                        <a id="btn-hide-filters" class="btn btn-dim" href="<?php echo osc_admin_base_url(true) . '?page=items'; ?>"><?php _e('Reset filters'); ?></a>
                    <?php } ?>
                    <?php $opt = 'oPattern';
if (Params::getParam('shortcut-filter') != '') {
    $opt = Params::getParam('shortcut-filter');
} ?>
                    <?php $classPattern = 'hide';
$classUser          = 'hide';
$classItemId        = 'hide'; ?>
                    <?php if ($opt === 'oUser') {
                        $classUser = '';
                    } ?>
                    <?php if ($opt === 'oPattern') {
                        $classPattern = '';
                    } ?>
                    <?php if ($opt === 'oItemId') {
                        $classItemId = '';
                    } ?>
                    <select id="filter-select" name="shortcut-filter" class="form-select form-select-sm">
                        <option value="oPattern" <?php if ($opt === 'oPattern') {
                            echo 'selected="selected"';
                        } ?>><?php _e('Pattern'); ?></option>
                        <option value="oUser" <?php if ($opt === 'oUser') {
                            echo 'selected="selected"';
                        } ?>><?php _e('Email'); ?></option>
                        <option value="oItemId" <?php if ($opt === 'oItemId') {
                            echo 'selected="selected"';
                        } ?>><?php _e('Item ID'); ?></option>
                    </select>
                    <input id="fPattern" type="text" name="sSearch" placeholder="<?php _e('Keywords') ?>" value="<?php echo osc_esc_html(Params::getParam('sSearch')); ?>" class="form-control w-25 <?php echo $classPattern; ?>" />
                    <input id="fUser" name="user" type="text" placeholder="<?php _e('User Email') ?>" class="fUser form-control w-25 <?php echo $classUser; ?>" value="<?php echo osc_esc_html(Params::getParam('user')); ?>" />
                    <input id="fUserId" name="userId" type="hidden" placeholder="<?php _e('User ID') ?>" class="form-control w-25" value="<?php echo osc_esc_html(Params::getParam('userId')); ?>" />
                    <input id="fItemId" type="text" name="itemId" placeholder="<?php _e('Item ID') ?>" value="<?php echo osc_esc_html(Params::getParam('itemId')); ?>" class="form-control w-25 <?php echo $classItemId; ?>" />
                    <a id="btn-display-filters" data-osc-dialog-open="#display-filters" href="#" class="btn <?php
                                                                                                        echo $withFilters ? 'btn-primary' : 'btn-dim'; ?>" title="<?php _e('Show filters'); ?>"><i class="bi bi-filter"></i>
                    </a>
                    <button type="submit" class="btn btn-primary" title="<?php echo osc_esc_html(__('Find')); ?>">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>
            <?php osc_admin_per_page(array('label' => __('%d Listings'), 'current' => $iDisplayLength)); ?>
        </div>
    </div>
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post" data-dialog-open="false">
        <input type="hidden" name="page" value="items" />
        <input type="hidden" name="action" value="bulk_actions" />
        <?php osc_admin_bulk_actions(array('name' => 'bulk_actions', 'options' => __get('bulk_options'))); ?>
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
                            <tr class="<?php echo implode(
                                ' ',
                                osc_apply_filter('datatable_listing_class', array(), $aRawRows[$key], $row)
                            ); ?>">
                                <?php foreach ($row as $k => $v) { ?>
                                    <?php // Status is the one value that gets presentational markup — it becomes a badge
                                        // (tint + icon + word). This wrap lives in the THEME, not in
                                        // ItemsDataTable::get_row_status(), so $row['status'] stays the plain translated
                                        // word for any plugin hooked on the `items_processing_row` filter.
                                    ?>
                                    <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php
                                                                                                                    echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                                    ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } elseif ($withFilters) {
                        osc_admin_table_empty(count($columns), array(
                            'icon'   => 'bi-filter',
                            'title'  => __('No listings match these filters'),
                            'action' => array(
                                'label' => __('Reset filters'),
                                'url'   => osc_admin_base_url(true) . '?page=items',
                            ),
                        ));
                    } else {
                        osc_admin_table_empty(count($columns), array(
                            'icon'  => 'bi-card-list',
                            'title' => __('No listings found'),
                            'text'  => __('Listings published by your users appear here, and you can post one yourself.'),
                            'action' => array(
                                'label'   => __('Add listing'),
                                'url'     => osc_admin_base_url(true) . '?page=items&amp;action=post',
                                'variant' => 'primary',
                            ),
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
<dialog id="display-filters" class="osc-dialog osc-dialog-wide">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>" nocsrf>
            <div class="osc-dialog-body">
            <p class="osc-dialog-title"><?php _e('Filters') ?></p>
            <input type="hidden" name="page" value="items" />
            <input type="hidden" name="iDisplayLength" value="<?php echo $iDisplayLength; ?>" />
            <input type="hidden" name="sort" value="<?php echo $sort; ?>" />
            <input type="hidden" name="direction" value="<?php echo $direction; ?>" />
            <div class="form-horizontal">
                <div class="row">
                    <div class="col-lg-6">
                        <div class="row-wrapper">
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Pattern'); ?>
                                </div>
                                <div class="form-controls">
                                    <input class="form-control" type="text" name="sSearch" id="sSearch" value="<?php echo osc_esc_html(Params::getParam('sSearch')); ?>" />
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Category'); ?>
                                </div>
                                <div class="form-controls">
                                    <?php ManageItemsForm::category_select($categories, null, null, true); ?>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Country'); ?>
                                </div>
                                <div class="form-controls">
                                    <?php ManageItemsForm::country_text(); ?>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Region'); ?>
                                </div>
                                <div class="form-controls">
                                    <?php ManageItemsForm::region_text(); ?>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('City'); ?>
                                </div>
                                <div class="form-controls">
                                    <?php ManageItemsForm::city_text(); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="row-wrapper">
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Email'); ?>
                                </div>
                                <div class="form-controls">
                                    <input class="form-control" id="user" name="user" type="text" value="<?php echo osc_esc_html(Params::getParam('user')); ?>" />
                                    <input id="userId" name="userId" type="hidden" value="<?php echo osc_esc_html(Params::getParam('userId')); ?>" />
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Premium'); ?>
                                </div>
                                <div class="form-controls">
                                    <select class="form-select" id="b_premium" name="b_premium">
                                        <option value="" <?php echo ((Params::getParam('b_premium') == '')
                                                                ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_premium') == '1')
                                                                ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_premium') == '0')
                                                                ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Active'); ?>
                                </div>
                                <div class="form-controls">
                                    <select class="form-select" id="b_active" name="b_active">
                                        <option value="" <?php echo ((Params::getParam('b_active') == '') ? 'selected="selected"'
                                                                : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_active') == '1')
                                                                ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_active') == '0')
                                                                ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Block'); ?>
                                </div>
                                <div class="form-controls">
                                    <select class="form-select" id="b_enabled" name="b_enabled">
                                        <option value="" <?php echo ((Params::getParam('b_enabled') == '')
                                                                ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_enabled') == '0')
                                                                ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_enabled') == '1')
                                                                ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label">
                                    <?php _e('Spam'); ?>
                                </div>
                                <div class="form-controls">
                                    <select class="form-select" id="b_spam" name="b_spam">
                                        <option value="" <?php echo ((Params::getParam('b_spam') == '') ? 'selected="selected"'
                                                                : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_spam') == '1') ? 'selected="selected"'
                                                                : '') ?>><?php _e('ON'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_spam') == '0') ? 'selected="selected"'
                                                                : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php osc_run_hook('filters_manage_item_search'); ?>
                </div>
            </div>
            </div>
            <div class="osc-dialog-actions">
                <a class="btn btn-dim btn-sm" href="<?php echo osc_admin_base_url(true) . '?page=items'; ?>"><?php _e('Reset filters'); ?></a>
                <input id="show-filters" type="submit" value="<?php echo osc_esc_html(__('Apply filters')); ?>" class="btn btn-primary btn-sm" />
            </div>
    </form>
</dialog>
<?php osc_admin_confirm_dialog(array(
    'id'      => 'itemDeleteModal',
    'method'  => 'get',
    'fields'  => array('page' => 'items', 'action' => 'delete', 'id[]' => ''),
    'title'   => __('Delete listing'),
    'text'    => __('This permanently deletes the listing and its photos. This cannot be undone.'),
    'confirm' => __('Delete'),
)); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
<script>
    var filterSelect = document.getElementById("filter-select")
    filterSelect.onchange = function() {
        let selectedOption = this.options[this.selectedIndex].value
        let inputIdsArr = ['ItemId', 'User', 'Pattern']
        for (let i = 0; i < inputIdsArr.length; i++) {
            if ('o' + inputIdsArr[i] === selectedOption) {
                document.getElementById("f" + inputIdsArr[i]).classList.remove("hide");
            } else {
                document.getElementById("f" + inputIdsArr[i]).classList.add("hide");
            }
        }
    }

    function delete_dialog(item_id) {
        var deleteModal = document.getElementById("itemDeleteModal");
        var input = deleteModal.querySelector("input[name='id[]']");
        if (input) { input.value = item_id; }
        deleteModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>