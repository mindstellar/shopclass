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
    'title'   => __('Manage users'),
    'help'    => __('Add, edit or delete information associated to registered users. Keep in mind that deleting a user also '
                    . 'deletes all the listings the user published.'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=users&action=create',
            'title' => __('Add'),
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
 * Emit the user list's script: autocomplete on the user filter fields.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            // users autocomplete
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

$aData          = __get('aData');
$aRawRows       = __get('aRawRows');
$iDisplayLength = __get('iDisplayLength');
$sort           = Params::getParam('sort');
$direction      = Params::getParam('direction');

$columns     = $aData['aColumns'];
$rows        = $aData['aRows'];
$withFilters = __get('withFilters');
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Manage users')); ?>
    <div class="relative">
        <?php osc_admin_list_filter(array(
                'page'     => 'users',
                'id'       => 'shortcut-filters',
                'active'   => $withFilters,
                'advanced' => '#display-filters',
                'reset'    => osc_admin_base_url(true) . '?page=users',
                'fields'   => array(
                    array(
                        'type'        => 'search',
                        'name'        => 'user',
                        'id'          => 'fUser',
                        'label'       => __('User'),
                        'placeholder' => __('User Email'),
                        'value'       => Params::getParam('user'),
                    ),
                    array(
                        'type'  => 'hidden',
                        'name'  => 'userId',
                        'id'    => 'fUserId',
                        'value' => Params::getParam('userId'),
                    ),
                ),
                'bulk'     => array(
                    'options' => __get('bulk_options'),
                    'form'    => 'datatablesForm',
                ),
                'per_page' => array('label' => __('%d Users'), 'current' => $iDisplayLength),
            )); ?>
        <form id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="users"/>
            <div class="table-contains-actions">
                <table class="table" cellpadding="0" cellspacing="0">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $k => $v) {
                            if ($direction === 'desc') {
                                echo '<th class="col-' . $k . ' ' . ($sort == $k ? ('sorting_desc') : '') . '">' . $v . '</th>';
                            } else {
                                echo '<th class="col-' . $k . ' ' . ($sort == $k ? ('sorting_asc') : '') . '">' . $v . '</th>';
                            }
                        } ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) > 0) { ?>
                        <?php foreach ($rows as $key => $row) { ?>
                            <tr class="<?php echo implode(
                                ' ',
                                osc_apply_filter('datatable_user_class', array(), $aRawRows[$key], $row)
                            ); ?>">
                                <?php foreach ($row as $k => $v) { ?>
                                    <?php // Status becomes a badge. Wrapped here in the theme, not in the DataTable, so the
                                              // `users_processing_row` filter still hands plugins the plain word.?>
                                    <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php
                                            echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                                    ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } elseif ($withFilters) { ?>
                        <?php osc_admin_table_empty(count($columns), array(
                            'icon'  => 'bi-people',
                            'title' => __('No results for this filter'),
                        )); ?>
                    <?php } else { ?>
                        <?php osc_admin_table_empty(count($columns), array(
                            'icon'   => 'bi-people',
                            'title'  => __('No users yet'),
                            'text'   => __('Registered users will appear here once they sign up or you add them.'),
                            'action' => array(
                                'label'   => __('Add'),
                                'url'     => osc_admin_base_url(true) . '?page=users&action=create',
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
    <dialog id="display-filters" class="osc-dialog osc-dialog-wide">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>" nocsrf>
                <div class="osc-dialog-body">
                    <p class="osc-dialog-title"><?php _e('Filters') ?></p>
                    <input type="hidden" name="page" value="users"/>
                    <input type="hidden" name="iDisplayLength" value="<?php echo $iDisplayLength; ?>"/>
                    <input type="hidden" name="sort" value="<?php echo $sort; ?>"/>
                    <input type="hidden" name="direction" value="<?php echo $direction; ?>"/>
                    <div class="form-horizontal">
                        <div class="row row-cols-lg-2">
                            <div class="col-lg-6">
                                <div class="row-wrapper">
                                    <?php osc_admin_form_row_open(__('Email')); ?>
                                            <input id="s_email" name="s_email" type="text"
                                                   placeholder="<?php echo osc_esc_html(__('Any e-mail')); ?>"
                                                   value="<?php echo osc_esc_html(Params::getParam('s_email')); ?>"/>
                                    <?php osc_admin_form_row_close(); ?>
                                    <?php osc_admin_form_row_open(__('Name')); ?>
                                            <input id="s_name" name="s_name" type="text"
                                                   placeholder="<?php echo osc_esc_html(__('Any name')); ?>"
                                                   value="<?php echo osc_esc_html(Params::getParam('s_name')); ?>"/>
                                    <?php osc_admin_form_row_close(); ?>
                                    <?php osc_admin_form_row_open(__('Username')); ?>
                                            <input id="s_username" name="s_username" type="text"
                                                   placeholder="<?php echo osc_esc_html(__('Any username')); ?>"
                                                   value="<?php echo osc_esc_html(Params::getParam('s_username')); ?>"/>
                                    <?php osc_admin_form_row_close(); ?>
                                    <?php osc_admin_form_row_open(__('Active')); ?>
                                            <select id="b_active" name="b_active">
                                                <option value="" <?php echo((Params::getParam('b_active') == '')
                                                    ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                                <option value="1" <?php echo((Params::getParam('b_active') == '1')
                                                    ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                                <option value="0" <?php echo((Params::getParam('b_active') == '0')
                                                    ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                            </select>
                                    <?php osc_admin_form_row_close(); ?>
                                </div>
                            </div>
                            <div class="col">
                                <div class="row-wrapper">
                                    <?php // Country picks from a closed list; region and city suggest
                                          // through the data-ac attributes ui-common.js reads, so this
                                          // screen no longer carries its own copy of that wiring.?>
                                    <?php osc_admin_form_row_open(__('Country')); ?>
                                            <select id="countryId" name="countryId" class="form-select"
                                                    data-osc-clears="#regionId,#region,#cityId,#city">
                                                <option value=""><?php _e('Any country'); ?></option>
                                                <?php foreach ((__get('countries') ?: array()) as $c) { ?>
                                                    <option value="<?php echo osc_esc_html($c['pk_c_code']); ?>"
                                                        <?php echo Params::getParam('countryId') === $c['pk_c_code']
                                                            ? ' selected' : ''; ?>>
                                                        <?php echo osc_esc_html($c['s_name']); ?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                    <?php osc_admin_form_row_close(); ?>
                                    <?php osc_admin_form_row_open(__('Region')); ?>
                                            <input id="region" name="region" type="text" class="form-control"
                                                   placeholder="<?php echo osc_esc_html(__('Any region')); ?>"
                                                   value="<?php echo osc_esc_html(Params::getParam('region')); ?>"
                                                   autocomplete="off"
                                                   data-ac="location_regions"
                                                   data-ac-url="<?php echo osc_esc_html(osc_base_url(true)); ?>"
                                                   data-ac-target="#regionId"
                                                   data-ac-scope="#countryId" data-ac-scope-param="country"
                                                   data-ac-clears="#cityId,#city"/>
                                            <input id="regionId" name="regionId" type="hidden"
                                                   value="<?php echo osc_esc_html(Params::getParam('regionId')); ?>"/>
                                    <?php osc_admin_form_row_close(); ?>
                                    <?php osc_admin_form_row_open(__('City')); ?>
                                            <input id="city" name="city" type="text" class="form-control"
                                                   placeholder="<?php echo osc_esc_html(__('Any city')); ?>"
                                                   value="<?php echo osc_esc_html(Params::getParam('city')); ?>"
                                                   autocomplete="off"
                                                   data-ac="location_cities"
                                                   data-ac-url="<?php echo osc_esc_html(osc_base_url(true)); ?>"
                                                   data-ac-target="#cityId"
                                                   data-ac-scope="#regionId" data-ac-scope-param="region"/>
                                            <input id="cityId" name="cityId" type="hidden"
                                                   value="<?php echo osc_esc_html(Params::getParam('cityId')); ?>"/>
                                    <?php osc_admin_form_row_close(); ?>
                                    <?php osc_admin_form_row_open(__('Block')); ?>
                                            <select id="b_enabled" name="b_enabled">
                                                <option value="" <?php echo((Params::getParam('b_enabled') == '')
                                                    ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                                <option value="0" <?php echo((Params::getParam('b_enabled') == '0')
                                                    ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                                <option value="1" <?php echo((Params::getParam('b_enabled') == '1')
                                                    ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                            </select>
                                    <?php osc_admin_form_row_close(); ?>
                                </div>
                            </div>
                            <div class="clear"></div>
                        </div>
                    </div>
                </div>
                <div class="osc-dialog-actions">
                    <a class="btn btn-dim btn-sm"
                       href="<?php echo osc_admin_base_url(true) . '?page=users'; ?>"><?php _e('Reset filters'); ?></a>
                    <input id="show-filters" type="submit" value="<?php echo osc_esc_html(__('Apply filters')); ?>"
                           class="btn btn-primary btn-sm"/>
                </div>
        </form>
    </dialog>
    <?php osc_admin_confirm_dialog(array(
        'id'         => 'deleteModal',
        'method'     => 'post',
        'fields'     => array('page' => 'users', 'action' => 'delete', 'id[]' => ''),
        'title'      => __('Delete user'),
        'text'       => __('This permanently deletes the account and every listing the user published.'),
        'confirm'    => __('Delete'),
        'confirm_id' => 'deleteSubmit',
    )); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
    <script>

        function delete_dialog(id) {
            var deleteModal = document.getElementById("deleteModal");
            var input = deleteModal.querySelector("input[name='id[]'], input[name='id']");
            if (input) { input.value = id; }
            deleteModal.showModal();
            return false;
        }
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>