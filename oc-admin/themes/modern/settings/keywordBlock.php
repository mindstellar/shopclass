<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_admin_page(array(
    'section' => __('Settings'),
    'heading' => __('Spam filter'),
    'title'   => __('Spam filter'),
    'help'    => __('Two checks on what visitors post: Akismet, and your own list of blocked keywords.'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=settings&action=keyword_block_add',
            'title' => __('Add keyword'),
        ),
    ),
));

//customize Head
/**
 * Emit the blocked-keywords list's script: row selection, bulk actions and the delete dialogs.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        // Called by the keyword row action links.
        function delete_dialog(item_id) {
            var d = document.getElementById('dialog-keyword-delete');
            var input = d.querySelector("input[name='id[]']");
            if (input) { input.value = item_id; }
            d.showModal();
            return false;
        }
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

$aData     = __get('aData');
$aRawRows  = __get('aRawRows');
$sort      = Params::getParam('sort');
$direction = Params::getParam('direction');
$prefsForm = __get('moderation_form');
$akismet   = __get('akismet_form');

$columns = $aData['aColumns'];
$rows    = $aData['aRows'];

$scopeOptions = array(
    'all'         => __('Title and description'),
    'title'       => __('Title only'),
    'description' => __('Description only'),
    'meta'        => __('Custom fields'),
);

?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>

<?php osc_admin_body_open(); ?>
    <?php osc_admin_section(__('Akismet'), array(
        'id'    => 'spam-filter-akismet',
        'intro' => __('Akismet checks new comments and listings for spam. It needs a key from akismet.com.'),
    )); ?>
        <?php osc_admin_settings_form($akismet['id'], array('titles' => false) + $akismet); ?>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('Keyword blocklist'), array(
        'id'    => 'keyword-block-settings',
        'intro' => __('Listings containing a blocked keyword are quarantined (flagged spam and hidden) as soon as they '
                         . 'are posted or edited, and the matched keyword is recorded so you can see why. Enable the filter '
                         . 'below before it does anything.'),
    )); ?>
        <?php osc_admin_settings_form($prefsForm['id'], $prefsForm); ?>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('Import keyword list'), array('id' => 'keyword-block-import')); ?>
        <p><?php _e('Paste a comma-separated keyword list — useful for migrating an existing list from a theme or plugin. Wrap a keyword in asterisks (e.g. *viagra*) to import it as a substring match. Keywords already on file are skipped.'); ?></p>
        <?php osc_admin_form_open(array(
            'name'   => 'keyword_block_import_form',
            'page'   => 'settings',
            'action' => 'keyword_block_import_post',
        )); ?>
                <?php
                osc_admin_textarea(array(
                    'id'          => 'import_list',
                    'name'        => 'import_list',
                    'label'       => __('Keywords'),
                    'rows'        => 4,
                    'width'       => 'key',
                    'placeholder' => 'viagra, *casino*, call girl',
                ));
                osc_admin_select(array(
                    'name'    => 'import_scope',
                    'label'   => __('Where to match'),
                    'options' => $scopeOptions,
                )); ?>
                <?php osc_admin_form_close(array(
                    array('label' => __('Import'), 'type' => 'submit'),
                )); ?>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('Blocked keywords')); ?>
    <div class="relative">
        <?php osc_admin_form_open(array(
            'id'         => 'datatablesForm',
            'page'       => 'settings',
            'horizontal' => false,
        )); ?>

            <?php osc_admin_bulk_actions(array('options' => __get('bulk_options'))); ?>
            <?php
            osc_admin_data_table($aData, array(
                'sort'      => $sort,
                'direction' => $direction,
                'empty'     => array(
                    'title' => __('No blocked keywords yet'),
                    'text'  => __('Add a keyword above, or import a list, to start quarantining matching listings.'),
                ),
            ));
            ?>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
    </div>
    <?php osc_admin_section_close(); ?>
<?php
osc_admin_pagination($aData);
?>
    <?php osc_admin_confirm_dialog(array(
        'id'         => 'dialog-keyword-delete',
        'method'     => 'post',
        'fields'     => array('page' => 'settings', 'action' => 'keyword_block_delete', 'id[]' => ''),
        'title'      => __('Delete keyword'),
        'text'       => __('Listings already flagged by this keyword stay as they are; only future matching stops.'),
        'confirm'    => __('Delete'),
        'confirm_id' => 'keyword-delete-submit',
    )); ?>
    <?php osc_admin_bulk_confirm_dialog(); ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
