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
/**
 * Emit the listing list's scripts: the location filter fields and user autocomplete.
 *
 * @return void
 */
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
$countries   = __get('countries');
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
    <?php osc_admin_list_filter(array(
            'page'     => 'items',
            'id'       => 'shortcut-filters',
            'hidden'   => array('iDisplayLength' => $iDisplayLength),
            'active'   => $withFilters,
            'advanced' => '#display-filters',
            'reset'    => osc_admin_base_url(true) . '?page=items',
            'fields'   => array(
                array(
                    'type'    => 'switch',
                    'name'    => 'shortcut-filter',
                    'id'      => 'filter-select',
                    'value'   => Params::getParamString('shortcut-filter'),
                    'options' => array(
                        'oPattern' => array(
                            'label'       => __('Pattern'),
                            'name'        => 'sSearch',
                            'id'          => 'fPattern',
                            'placeholder' => __('Keywords'),
                            'value'       => Params::getParam('sSearch'),
                        ),
                        'oUser' => array(
                            'label'       => __('Email'),
                            'name'        => 'user',
                            'id'          => 'fUser',
                            'placeholder' => __('User Email'),
                            'value'       => Params::getParam('user'),
                        ),
                        'oItemId' => array(
                            'label'       => __('Item ID'),
                            'name'        => 'itemId',
                            'id'          => 'fItemId',
                            'placeholder' => __('Item ID'),
                            'value'       => Params::getParam('itemId'),
                        ),
                    ),
                ),
                array(
                    'type'  => 'hidden',
                    'name'  => 'userId',
                    'id'    => 'fUserId',
                    'value' => Params::getParam('userId'),
                ),
            ),
            'bulk'     => array(
                'name'    => 'bulk_actions',
                'options' => __get('bulk_options'),
                'form'    => 'datatablesForm',
            ),
            'per_page' => array('label' => __('%d Listings'), 'current' => $iDisplayLength),
        )); ?>
    <form class="" id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post" data-dialog-open="false">
        <input type="hidden" name="page" value="items" />
        <input type="hidden" name="action" value="bulk_actions" />
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
                            <?php osc_admin_form_row_open(__('Pattern')); ?>
                                    <input class="form-control" type="text" name="sSearch" id="sSearch"
                                           placeholder="<?php echo osc_esc_html(__('Title or description')); ?>"
                                           value="<?php echo osc_esc_html(Params::getParam('sSearch')); ?>" />
                            <?php osc_admin_form_row_close(); ?>
                            <?php osc_admin_form_row_open(__('Category')); ?>
                                    <?php ManageItemsForm::category_select($categories, null, null, true); ?>
                            <?php osc_admin_form_row_close(); ?>
                            <?php // Written out rather than through ManageItemsForm so the fields can
                                  // carry the data-ac attributes ui-common.js reads: each one suggests
                                  // from the location endpoints and clears whatever sits below it.?>
                            <?php // A country is a closed list, so it picks rather than suggests: typing
                                  // a name and not choosing a suggestion used to leave countryId empty,
                                  // and the filter then did nothing at all.?>
                            <?php osc_admin_form_row_open(__('Country')); ?>
                                    <select class="form-select" id="countryId" name="countryId"
                                            data-osc-clears="#regionId,#region,#cityId,#city">
                                        <option value=""><?php _e('Any country'); ?></option>
                                        <?php foreach (($countries ?: array()) as $c) { ?>
                                            <option value="<?php echo osc_esc_html($c['pk_c_code']); ?>"
                                                <?php echo Params::getParam('countryId') === $c['pk_c_code']
                                                    ? ' selected' : ''; ?>>
                                                <?php echo osc_esc_html($c['s_name']); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                            <?php osc_admin_form_row_close(); ?>
                            <?php osc_admin_form_row_open(__('Region')); ?>
                                    <input class="form-control" type="text" id="region" name="region"
                                           placeholder="<?php echo osc_esc_html(__('Any region')); ?>"
                                           value="<?php echo osc_esc_html(Params::getParam('region')); ?>"
                                           autocomplete="off"
                                           data-ac="location_regions"
                                           data-ac-url="<?php echo osc_esc_html(osc_base_url(true)); ?>"
                                           data-ac-target="#regionId"
                                           data-ac-scope="#countryId" data-ac-scope-param="country"
                                           data-ac-clears="#cityId,#city"/>
                                    <input type="hidden" id="regionId" name="regionId"
                                           value="<?php echo osc_esc_html(Params::getParam('regionId')); ?>"/>
                            <?php osc_admin_form_row_close(); ?>
                            <?php osc_admin_form_row_open(__('City')); ?>
                                    <input class="form-control" type="text" id="city" name="city"
                                           placeholder="<?php echo osc_esc_html(__('Any city')); ?>"
                                           value="<?php echo osc_esc_html(Params::getParam('city')); ?>"
                                           autocomplete="off"
                                           data-ac="location_cities"
                                           data-ac-url="<?php echo osc_esc_html(osc_base_url(true)); ?>"
                                           data-ac-target="#cityId"
                                           data-ac-scope="#regionId" data-ac-scope-param="region"/>
                                    <input type="hidden" id="cityId" name="cityId"
                                           value="<?php echo osc_esc_html(Params::getParam('cityId')); ?>"/>
                            <?php osc_admin_form_row_close(); ?>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="row-wrapper">
                            <?php osc_admin_form_row_open(__('Email')); ?>
                                    <input class="form-control" id="user" name="user" type="text"
                                           placeholder="<?php echo osc_esc_html(__('Any seller')); ?>"
                                           value="<?php echo osc_esc_html(Params::getParam('user')); ?>" />
                                    <input id="userId" name="userId" type="hidden" value="<?php echo osc_esc_html(Params::getParam('userId')); ?>" />
                            <?php osc_admin_form_row_close(); ?>
                            <?php osc_admin_form_row_open(__('Premium')); ?>
                                    <select class="form-select" id="b_premium" name="b_premium">
                                        <option value="" <?php echo ((Params::getParam('b_premium') == '')
                                                                ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_premium') == '1')
                                                                ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_premium') == '0')
                                                                ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                            <?php osc_admin_form_row_close(); ?>
                            <?php osc_admin_form_row_open(__('Active')); ?>
                                    <select class="form-select" id="b_active" name="b_active">
                                        <option value="" <?php echo ((Params::getParam('b_active') == '') ? 'selected="selected"'
                                                                : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_active') == '1')
                                                                ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_active') == '0')
                                                                ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                            <?php osc_admin_form_row_close(); ?>
                            <?php osc_admin_form_row_open(__('Block')); ?>
                                    <select class="form-select" id="b_enabled" name="b_enabled">
                                        <option value="" <?php echo ((Params::getParam('b_enabled') == '')
                                                                ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_enabled') == '0')
                                                                ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_enabled') == '1')
                                                                ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                            <?php osc_admin_form_row_close(); ?>
                            <?php osc_admin_form_row_open(__('Spam')); ?>
                                    <select class="form-select" id="b_spam" name="b_spam">
                                        <option value="" <?php echo ((Params::getParam('b_spam') == '') ? 'selected="selected"'
                                                                : '') ?>><?php _e('Choose an option'); ?></option>
                                        <option value="1" <?php echo ((Params::getParam('b_spam') == '1') ? 'selected="selected"'
                                                                : '') ?>><?php _e('ON'); ?></option>
                                        <option value="0" <?php echo ((Params::getParam('b_spam') == '0') ? 'selected="selected"'
                                                                : '') ?>><?php _e('OFF'); ?></option>
                                    </select>
                            <?php osc_admin_form_row_close(); ?>
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
    'method'  => 'post',
    'fields'  => array('page' => 'items', 'action' => 'delete', 'id[]' => ''),
    'title'   => __('Delete listing'),
    'text'    => __('This permanently deletes the listing and its photos. This cannot be undone.'),
    'confirm' => __('Delete'),
)); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
<script>
    function delete_dialog(item_id) {
        var deleteModal = document.getElementById("itemDeleteModal");
        var input = deleteModal.querySelector("input[name='id[]']");
        if (input) { input.value = item_id; }
        deleteModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>