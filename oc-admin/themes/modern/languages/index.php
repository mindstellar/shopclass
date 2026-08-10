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
    'section' => __('Settings'),
    'title'   => __('Languages'),
    'help'    => __("Add, edit or delete the language in which your Shopclass is displayed, "
                    . "both the part that's viewable by users and the admin panel."),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=languages&amp;action=add',
            'title' => __('Upload language'),
        ),
        array(
            'icon'    => 'bi-arrow-down-circle-fill',
            'url'     => '#',
            'onclick' => 'languageModal()',
            'title'   => __('Download language'),
        ),
    ),
));

function customHead()
{
    ?>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

$iDisplayLength = __get('iDisplayLength');
$aData          = __get('aLanguages');

osc_current_admin_theme_path('parts/header.php');
?>
<?php osc_admin_page_head(__('Manage Languages')); ?>
<div class="relative">
    <form id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post" data-dialog-open="false">
        <input type="hidden" name="page" value="languages" />
        <?php osc_admin_bulk_actions(array('options' => __get('bulk_options'))); ?>
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
                        <?php osc_admin_table_empty(6, array(
                            'icon'   => 'bi-translate',
                            'title'  => __('No languages yet'),
                            'text'   => __('Import a language from the catalog or upload a package to add one.'),
                            'action' => array(
                                'label'   => __('Upload language'),
                                'url'     => osc_admin_base_url(true) . '?page=languages&amp;action=add',
                                'variant' => 'primary',
                            ),
                        )); ?>
                    <?php } ?>
                </tbody>
            </table>
            <div id="table-row-actions"></div>
        </div>
    </form>
</div>

<?php osc_admin_pagination($aData); ?>
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
<?php osc_admin_confirm_dialog(array(
    'id'         => 'deleteModal',
    'method'     => 'get',
    'fields'     => array('page' => 'languages', 'action' => 'delete', 'id[]' => ''),
    'title'      => __('Delete language'),
    'text'       => __('The site falls back to the default language for any content that relied on this one.'),
    'confirm'    => __('Delete'),
    'confirm_id' => 'deleteSubmit',
)); ?>
<?php osc_admin_bulk_confirm_dialog(); ?>
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