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
        <div id="users-toolbar" class="table-toolbar d-flex justify-content-end">
            <?php osc_admin_per_page(array('label' => __('%d Users'), 'current' => $iDisplayLength)); ?>
            <form method="get" action="<?php echo osc_admin_base_url(true); ?>" id="shortcut-filters"
                  class="inline nocsrf">
                <fieldset class="input-group input-group-sm">
                    <input type="hidden" name="page" value="users"/>
                    <input id="fUser" name="user" type="text" class="fUser input-text input-actions"
                           value="<?php echo osc_esc_html(Params::getParam('user')); ?>"/>
                    <input id="fUserId" name="userId" type="hidden"
                           value="<?php echo osc_esc_html(Params::getParam('userId')); ?>"/>
                    <?php if ($withFilters) { ?>
                        <a id="btn-hide-filters" href="<?php echo osc_admin_base_url(true) . '?page=users'; ?>"
                           class="btn btn-dim"><?php _e('Reset filters'); ?></a>
                    <?php } ?>
                    <?php // One class or the other, never both — see items/index.php. Red is for destructive
                          // actions; "a filter is applied" is a state.?>
                    <a data-osc-dialog-open="#display-filters" href="#"
                       class="btn <?php echo $withFilters ? 'btn-primary' : 'btn-dim'; ?>"
                       title="<?php _e('Show filters'); ?>"><i class="bi bi-filter"></i>
                    </a>
                    <button type="submit" class="btn btn-primary" title="<?php echo osc_esc_html(__('Find')); ?>">
                        <i class="bi bi-search"></i>
                    </button>
                </fieldset>
            </form>
        </div>
        <form id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="users"/>

            <?php osc_admin_bulk_actions(array('options' => __get('bulk_options'))); ?>
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
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('Email'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <input id="s_email" name="s_email" type="text"
                                                   value="<?php echo osc_esc_html(Params::getParam('s_email')); ?>"/>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('Name'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <input id="s_name" name="s_name" type="text"
                                                   value="<?php echo osc_esc_html(Params::getParam('s_name')); ?>"/>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('Username'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <input id="s_username" name="s_username" type="text"
                                                   value="<?php echo osc_esc_html(Params::getParam('s_username')); ?>"/>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('Active'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <select id="b_active" name="b_active">
                                                <option value="" <?php echo((Params::getParam('b_active') == '')
                                                    ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                                <option value="1" <?php echo((Params::getParam('b_active') == '1')
                                                    ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                                <option value="0" <?php echo((Params::getParam('b_active') == '0')
                                                    ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="row-wrapper">
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('Country'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <input id="countryName" name="countryName" type="text"
                                                   value="<?php echo osc_esc_html(Params::getParam('countryName')); ?>"/>
                                            <input id="countryId" name="countryId" type="hidden"
                                                   value="<?php echo osc_esc_html(Params::getParam('countryId')); ?>"/>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('Region'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <input id="region" name="region" type="text"
                                                   value="<?php echo osc_esc_html(Params::getParam('region')); ?>"/>
                                            <input id="regionId" name="regionId" type="hidden"
                                                   value="<?php echo osc_esc_html(Params::getParam('regionId')); ?>"/>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('City'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <input id="city" name="city" type="text"
                                                   value="<?php echo osc_esc_html(Params::getParam('city')); ?>"/>
                                            <input id="cityId" name="cityId" type="hidden"
                                                   value="<?php echo osc_esc_html(Params::getParam('cityId')); ?>"/>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-label">
                                            <?php _e('Block'); ?>
                                        </div>
                                        <div class="form-controls">
                                            <select id="b_enabled" name="b_enabled">
                                                <option value="" <?php echo((Params::getParam('b_enabled') == '')
                                                    ? 'selected="selected"' : '') ?>><?php _e('Choose an option'); ?></option>
                                                <option value="0" <?php echo((Params::getParam('b_enabled') == '0')
                                                    ? 'selected="selected"' : '') ?>><?php _e('ON'); ?></option>
                                                <option value="1" <?php echo((Params::getParam('b_enabled') == '1')
                                                    ? 'selected="selected"' : '') ?>><?php _e('OFF'); ?></option>
                                            </select>
                                        </div>
                                    </div>
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
        'method'     => 'get',
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
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
            function setVal(id, v) { var el = document.getElementById(id); if (el) { el.value = v; } }

            // Selecting a country clears the region/city chain below it.
            var countryId = document.getElementById('countryId');
            if (countryId) {
                countryId.addEventListener('change', function () {
                    setVal('regionId', ''); setVal('region', '');
                    setVal('cityId', ''); setVal('city', '');
                });
            }

            var countryName = document.getElementById('countryName');
            if (countryName) {
                oscAutocomplete(countryName, {
                    source: "<?php echo osc_base_url(true); ?>?page=ajax&action=location_countries",
                    minLength: 0,
                    onSearch: function () { setVal('countryId', ''); },
                    onSelect: function (item) {
                        setVal('countryId', item.id);
                        setVal('regionId', ''); setVal('region', '');
                        setVal('cityId', ''); setVal('city', '');
                    }
                });
            }

            var region = document.getElementById('region');
            if (region) {
                oscAutocomplete(region, {
                    // Region depends on the chosen country, resolved at fetch time.
                    source: function () {
                        var country = val('countryId') || val('countryName');
                        return "<?php echo osc_base_url(true); ?>?page=ajax&action=location_regions&country=" + encodeURIComponent(country);
                    },
                    minLength: 2,
                    onSearch: function () { setVal('regionId', ''); },
                    onSelect: function (item) {
                        setVal('cityId', ''); setVal('city', '');
                        setVal('regionId', item.id);
                    }
                });
            }

            var city = document.getElementById('city');
            if (city) {
                oscAutocomplete(city, {
                    source: function () {
                        var reg = val('regionId') || val('region');
                        return "<?php echo osc_base_url(true); ?>?page=ajax&action=location_cities&region=" + encodeURIComponent(reg);
                    },
                    minLength: 2,
                    onSearch: function () { setVal('cityId', ''); },
                    onSelect: function (item) { setVal('cityId', item.id); }
                });
            }
        });
    </script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>