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
         . __('Add, edit or delete information associated to registered users. Keep in mind that deleting a user also '
              . 'deletes all the listings the user published.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Users'); ?>
        <a href="<?php echo osc_admin_base_url(true) . '?page=users&action=settings'; ?>"
           class="ms-1 text-dark float-end" title="<?php _e('Settings'); ?>"><i class="bi bi-gear-fill"></i></a>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
        <a href="<?php echo osc_admin_base_url(true) . '?page=users&action=create'; ?>"
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
    return sprintf(__('Manage users &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

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
    <h2 class="render-title"><?php _e('Manage users'); ?></h2>
    <div class="relative">
        <div id="users-toolbar" class="table-toolbar d-flex justify-content-end">
            <form method="get" action="<?php echo osc_admin_base_url(true); ?>" class="inline nocsrf">
                <?php foreach (Params::getParamsAsArray('get') as $key => $value) { ?>
                    <?php if ($key !== 'iDisplayLength') { ?>
                        <input type="hidden" name="<?php echo osc_esc_html($key); ?>"
                               value="<?php echo osc_esc_html($value); ?>"/>
                    <?php }
                } ?>
                <select name="iDisplayLength" class="form-select form-select-sm"
                        onchange="this.form.submit();">
                    <option value="10"><?php printf(__('%d Listings'), 10); ?></option>
                    <option value="25" <?php if (Params::getParam('iDisplayLength') == 25) {
                        echo 'selected';
                                       } ?> ><?php printf(__('%d Listings'), 25); ?></option>
                    <option value="50" <?php if (Params::getParam('iDisplayLength') == 50) {
                        echo 'selected';
                                       } ?> ><?php printf(__('%d Listings'), 50); ?></option>
                    <option value="100" <?php if (Params::getParam('iDisplayLength') == 100) {
                        echo 'selected';
                                        } ?> ><?php printf(__('%d Listings'), 100); ?></option>
                </select>
            </form>
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
                          // actions; "a filter is applied" is a state. ?>
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

            <div id="bulk-actions">
                <div class="input-group input-group-sm">
                    <?php osc_print_bulk_actions('bulk_actions', 'action', __get('bulk_options'),
                                                 'select-box-extra'); ?>
                    <input type="submit" id="bulk_apply" class="btn btn-primary" value="<?php echo osc_esc_html(__('Apply')); ?>"/>
                </div>
            </div>
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
                            <tr class="<?php echo implode(' ',
                                                          osc_apply_filter('datatable_user_class', array(), $aRawRows[$key], $row)); ?>">
                                <?php foreach ($row as $k => $v) { ?>
                                    <?php // Status becomes a badge. Wrapped here in the theme, not in the DataTable, so the
                                          // `users_processing_row` filter still hands plugins the plain word. ?>
                                    <td class="col-<?php echo $k; ?>" data-col-name="<?php echo ucfirst($k); ?>"><?php
                                        echo $k === 'status' ? '<span class="osc-status">' . $v . '</span>' : $v;
                                    ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="9" class="text-center">
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
function showingResults()
{
    $aData = __get('aData');
    echo '<ul class="showing-results"><li><span>' . osc_pagination_showing((Params::getParam('iPage') - 1)
                                                                           * $aData['iDisplayLength'] + 1,
                                                                           ((Params::getParam('iPage') - 1) * $aData['iDisplayLength'])
                                                                           + count($aData['aRows']),
                                                                           $aData['iTotalDisplayRecords'], $aData['iTotalRecords'])
         . '</span></li></ul>';
}


osc_add_hook('before_show_pagination_admin', 'showingResults');
osc_show_pagination_admin($aData);
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
    <dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="users"/>
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="id[]" value=""/>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo osc_esc_html(__('Delete user')); ?>
                </p>
                <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this user?'); ?></p>
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
            // check_all bulkactions
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