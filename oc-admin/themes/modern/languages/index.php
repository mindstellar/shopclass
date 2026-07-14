<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

function addHelp()
{
    echo '<p>'
        . __("Add, edit or delete the language in which your Osclass is displayed, "
            . "both the part that's viewable by users and the admin panel.")
        . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
        <a href="#" onclick="languageModal()" class="ms-1 text-success float-end" title="<?php _e('Download language'); ?>">
            <i class="bi bi-arrow-down-circle-fill"></i>
        </a>
        <a href="<?php echo osc_admin_base_url(true); ?>?page=languages&amp;action=add" class="ms-1 text-success float-end" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php _e('Upload language');
        ?>"><i class="bi bi-plus-circle-fill"></i></a>
    </h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');

function customPageTitle($string)
{
    return sprintf(__('Languages &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

function customHead()
{
    ?>
    <script type="text/javascript">
        $(document).ready(function() {
            // check_all bulkactions
            $("#check_all").change(function() {
                var isChecked = $(this).prop("checked");
                $('.col-bulkactions input').each(function() {
                    if (isChecked == 1) {
                        this.checked = true;
                    } else {
                        this.checked = false;
                    }
                });
            });
        });
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

$iDisplayLength = __get('iDisplayLength');
$aData          = __get('aLanguages');

osc_current_admin_theme_path('parts/header.php');
?>
<h2 class="render-title">
    <?php _e('Manage Languages'); ?>
</h2>
<div class="relative">
    <form id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post" data-dialog-open="false">
        <input type="hidden" name="page" value="languages" />
        <div id="bulk-actions">
            <div class="input-group input-group-sm">
                <?php osc_print_bulk_actions('bulk_actions', 'action', __get('bulk_options'), 'select-box-extra'); ?>
                <input type="submit" id="bulk_apply" class="btn btn-primary" value="<?php echo osc_esc_html(__('Apply')); ?>" />
            </div>
        </div>
        <div class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                    <tr class="table-secondary">
                        <th class="col-bulkactions"><input id="check_all" type="checkbox" /></th>
                        <th><?php _e('Name'); ?></th>
                        <th class="col-short-name"><?php _e('Short name'); ?></th>
                        <th class="col-description"><?php _e('Description'); ?></th>
                        <th><?php _e('Enabled (website)'); ?></th>
                        <th><?php _e('Enabled (oc-admin)'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($aData['aaData']) > 0) { ?>
                        <?php foreach ($aData['aaData'] as $array) { ?>
                            <tr>
                                <?php foreach ($array as $key => $value) { ?>
                                    <td <?php if ($key === 0) {
                                            echo 'class="col-bulkactions"';
                                        } elseif ($key === 1) {
                                            echo 'data-col-name="' . __("Name") . '"';
                                        } elseif ($key === 2) {
                                            echo 'class="col-short-name" data-col-name="' . __("Short name") . '"';
                                        } elseif ($key === 3) {
                                            echo 'class="col-description" data-col-name="' . __("Description") . '"';
                                        } elseif ($key === 4) {
                                            echo 'class="col-enabled-website" data-col-name="' . __("Enabled (website)") . '"';
                                        } elseif ($key === 5) {
                                            echo 'class="col-enabled-backend" data-col-name="' . __("Enabled (oc-admin)") . '"';
                                        } ?>>
                                        <?php echo $value; ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="6" class="text-center">
                                <p><?php _e('No data available in table'); ?></p>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <div id="table-row-actions"></div>
        </div>
    </form>
</div>

<?php osc_show_pagination_admin($aData); ?>
<form id="languageModal" method="get" action="<?php echo osc_admin_base_url(true); ?>" class="modal fade static">
    <input type="hidden" name="page" value="languages" />
    <input type="hidden" name="action" value="import_locations" />
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <?php _e('Import a language'); ?>:
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><?php _e("Import a language from our database. " . "Already imported languages aren't shown."); ?></p>
                <div class="mb-3 ">
                    <label><?php _e('Import a language'); ?>:</label>
                    <select class="form-select-sm form-select" name="language" required>
                        <option value=""><?php _e('Select an option'); ?>
                    </select>
                    <p class="text-danger"></p>
                </div>
            </div>
            <div class="modal-footer">
                <div class="wrapper">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                    <button type="submit" class="btn btn-sm btn-submit"><?php echo osc_esc_html(__('Import')); ?></button>
                </div>
            </div>
        </div>
    </div>
</form>
<form id="deleteModal" method="get" action="<?php echo osc_admin_base_url(true); ?>" class="modal fade static">
    <input type="hidden" name="page" value="languages" />
    <input type="hidden" name="action" value="delete" />
    <input type="hidden" name="id[]" value="" />
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <?php echo __('Delete language'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php _e('Are you sure you want to delete this language?'); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                <button id="deleteSubmit" class="btn btn-sm btn-red" type="submit">
                    <?php echo __('Delete'); ?>
                </button>
            </div>
        </div>
    </div>
</form>
<div id="bulkActionsModal" class="modal fade static" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkActionsModalLabel"><?php _e('Bulk actions'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                <button id="bulkActionsSubmit" onclick="bulkActionsSubmit()" class="btn btn-sm btn-red"><?php echo osc_esc_html(__('Delete')); ?></button>
            </div>
        </div>
    </div>
</div>
<script>
    var aExistingLanguages = <?php echo json_encode(OSCLocale::newInstance()->listAll()); ?>;
    var localeImportUrl = '<?php echo osc_esc_js(osc_get_i18n_repository_url()) ?>';
    let languageOptionsSet = false;
    // shift locale code as array key
    for (let i = 0; i < aExistingLanguages.length; i++) {
        aExistingLanguages[aExistingLanguages[i].pk_c_code] = aExistingLanguages[i];
        delete aExistingLanguages[i];
    }
    // function to compare to version
    function compareVersion(a, b) {
        var aParts = a.split('.');
        var bParts = b.split('.');
        for (var i = 0; i < aParts.length; i++) {
            if (aParts[i] > bParts[i]) return 1;
            if (aParts[i] < bParts[i]) return -1;
        }
        return 0;
    }

    function languageModal() {
        var importSelect;
        (new bootstrap.Modal(document.getElementById("languageModal"))).toggle()
        importSelect = document.querySelector("#languageModal select");

        if (languageOptionsSet === false) {
            fetch(localeImportUrl).then(response => {
                if (response.ok) {
                    return response.json();
                }
            }).then(locales => {
                var localeCodes;
                var opt;
                // add to select options
                locales.forEach(locale => {
                    let isUpdated
                    // check if locale is not already in the existing languages list, if it has same or higher version, don't add it
                    if (aExistingLanguages[locale.locale_code] === undefined || (isUpdated = compareVersion(locale.version, aExistingLanguages[locale.locale_code].s_version) > 0)) {
                        opt = document.createElement('option');
                        opt.value = locale.locale_code;
                        opt.innerHTML = locale.name;
                        if(isUpdated) {
                            opt.innerHTML += ' (<?php _e('Updated');?>)';
                        }
                        importSelect.appendChild(opt);
                    }
                });
                languageOptionsSet = true;
            }).catch(error => {
                document.querySelector("#languageModal .text-danger").textContent = '<?php osc_esc_js(__('No official languages available.')); ?> ' + error;
            });
        }
        return false;
    }

    function delete_dialog(id) {
        var deleteModal = document.getElementById("deleteModal")
        deleteModal.querySelector("input[name='id[]']").value = id;
        (new bootstrap.Modal(document.getElementById("deleteModal"))).toggle()
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>