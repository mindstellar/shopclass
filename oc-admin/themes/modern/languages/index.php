<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Osclass (Mindstellar).
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
                    <tr>
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
<dialog id="languageModal" class="osc-dialog">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="languages" />
        <input type="hidden" name="action" value="import_locations" />
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><?php _e('Import a language'); ?>:</p>
            <p class="osc-dialog-text"><?php _e("Import a language from our database. " . "Already imported languages aren't shown."); ?></p>
            <div class="mb-3">
                <label><?php _e('Import a language'); ?>:</label>
                <select class="form-select-sm form-select" name="language" required>
                    <option value=""><?php _e('Select an option'); ?>
                </select>
                <p class="text-danger"></p>
            </div>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button type="submit" class="btn btn-primary btn-sm"><?php echo osc_esc_html(__('Import')); ?></button>
        </div>
    </form>
</dialog>
<dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="languages" />
        <input type="hidden" name="action" value="delete" />
        <input type="hidden" name="id[]" value="" />
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo __('Delete language'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this language?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" class="btn btn-danger btn-sm" type="submit"><?php echo __('Delete'); ?></button>
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
        document.getElementById("languageModal").showModal();
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
        var deleteModal = document.getElementById("deleteModal");
        var input = deleteModal.querySelector("input[name='id[]'], input[name='id']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>