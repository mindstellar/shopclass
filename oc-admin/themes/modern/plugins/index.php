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
         . __("Install or uninstall the plugins available in your installation. In some cases, "
              . "you'll have to configure the plugin in order to get it to work.")
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Manage Plugins'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
        <a href="<?php echo osc_admin_base_url(true); ?>?page=plugins&amp;action=add"
           class="ms-1 text-success float-end" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php _e('Add plugin'); ?>"><i
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
    return sprintf(__('Plugins &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$iDisplayLength = __get('iDisplayLength');
$aData          = __get('aPlugins');

$tab_index = 2;
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>

<?php if (Params::getParam('error') != '') { ?>
    <!-- flash message -->
    <div class="flashmessage flashmessage-error" style="display:block">
        <?php _e("Plugin couldn't be installed because it triggered a <strong>fatal error</strong>"); ?>
        <a class="btn ico btn-mini ico-close">x</a>
        <iframe style="border:0;" width="100%" height="60"
                src="<?php echo osc_admin_base_url(true); ?>?page=plugins&amp;action=error_plugin&amp;plugin=<?php
                echo Params::getParam('error'); ?>"></iframe>
    </div>
    <!-- /flash message -->
<?php } ?>
<div id="upload-plugins">
    <table class="table" cellpadding="0" cellspacing="0">
        <thead>
        <tr>
            <th><?php _e('Name'); ?></th>
            <th class="col-status"><?php _e('Status'); ?></th>
            <th><?php _e('Description'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (count($aData['aaData']) > 0) { ?>
            <?php foreach ($aData['aaData'] as $array) { ?>
                <?php
                // Both classes are wanted: `plugin-*` is the old row hook, kept because a site
                // owner may have styled it, and `status-*` is what tints and shapes the badge.
                $sStatus = $array['plugin_status'];
                unset($array['plugin_status']);
                // The controller appends the action links as five trailing cells
                // (update, configure, enable/disable, install/uninstall, delete). Gather them into
                // the shared .actions strip under the plugin name, the same row-actions pattern the
                // datatables use, instead of one action per empty column. The update-available
                // notice already sits in the name cell, so it stays out of the strip.
                $options = array();
                foreach (array(3, 4, 5, 6) as $ak) {
                    if (isset($array[$ak]) && trim($array[$ak]) !== '') {
                        $options[] = $array[$ak];
                    }
                }
                $actions = empty($options) ? ''
                    : '<div class="actions"><ul><li>' . implode('</li><li>', $options) . '</li></ul></div>';
                ?>
                <tr class="plugin-<?php echo $sStatus; ?> status-<?php echo $sStatus; ?>">
                    <td><?php echo $array[0] . $actions; ?></td>
                    <td class="col-status"><?php echo $array['status']; ?></td>
                    <td><?php echo $array[1]; ?></td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="3" class="text-center">
                    <p><?php _e('No data available in table'); ?></p>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php
    function showingResults()
    {
        $aData = __get('aPlugins');
        echo '<ul class="showing-results"><li><span>' . osc_pagination_showing((Params::getParam('iPage') - 1)
                                                                               * $aData['iDisplayLength'] + 1,
                                                                               ((Params::getParam('iPage') - 1)
                                                                                * $aData['iDisplayLength'])
                                                                               + count($aData['aaData']),
                                                                               $aData['iTotalDisplayRecords'])
             . '</span></li></ul>';
    }


    osc_add_hook('before_show_pagination_admin', 'showingResults');
    osc_show_pagination_admin($aData);
    ?>

    <div class="display-select-bottom">
        <form method="get" action="<?php echo osc_admin_base_url(true); ?>" class="inline nocsrf">
            <?php foreach (Params::getParamsAsArray('get') as $key => $value) { ?>
                <?php if ($key !== 'iDisplayLength') { ?>
                    <input type="hidden" name="<?php echo osc_esc_html($key); ?>"
                           value="<?php echo osc_esc_html($value); ?>"/>
                <?php }
            } ?>
            <select name="iDisplayLength" class="form-select form-select-sm select-box-medium float-left"
                    onchange="this.form.submit();">
                <option value="10" <?php if (Params::getParam('iDisplayLength') == 10) {
                    echo 'selected';
                                   } ?> ><?php printf(__('%d plugins'), 10); ?></option>
                <option value="25" <?php if (Params::getParam('iDisplayLength') == 25) {
                    echo 'selected';
                                   } ?> ><?php printf(__('%d plugins'), 25); ?></option>
                <option value="50" <?php if (Params::getParam('iDisplayLength') == 50) {
                    echo 'selected';
                                   } ?> ><?php printf(__('%d plugins'), 50); ?></option>
                <option value="100" <?php if (Params::getParam('iDisplayLength') == 100) {
                    echo 'selected';
                                    } ?> ><?php printf(__('%d plugins'), 100); ?></option>
            </select>
        </form>
    </div>
</div>
<dialog id="pluginModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="plugins"/>
        <input type="hidden" name="action" value=""/>
        <input type="hidden" name="plugin" value=""/>
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"></p>
            <p class="osc-dialog-text"></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="pluginModalSubmit" class="btn btn-danger btn-sm" type="submit"></button>
        </div>
    </form>
</dialog>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        oscTooltip(document.querySelectorAll('.plugin-tooltip'), '<?php echo osc_esc_js(__('Problems with this plugin? Ask for support.')); ?>', {
            layout: 'gray-tooltip',
            position: {x: 'right', y: 'middle'}
        });
    });
    function uninstall_dialog(plugin, title) {
        var pluginModal = document.getElementById("pluginModal")
        pluginModal.querySelector("input[name='plugin']")
            .value = plugin;
        pluginModal.querySelector("input[name='action']")
            .value = "uninstall";
        pluginModal.querySelector(".osc-dialog-title")
            .textContent = title;
        pluginModal.querySelector(".osc-dialog-text")
            .textContent = "<?php echo osc_esc_js(__('This action can not be undone.'
                                                     . ' Uninstalling plugins may result in a permanent loss of data. '
                                                     . 'Are you sure you want to continue?')); ?>";
        pluginModal.querySelector("#pluginModalSubmit")
            .textContent = "<?php echo osc_esc_js(__('Uninstall')); ?>";
        pluginModal.showModal();
        return false;
    }

    function delete_plugin(plugin) {
        var pluginModal = document.getElementById("pluginModal")
        pluginModal.querySelector("input[name='plugin']")
            .value = plugin;
        pluginModal.querySelector("input[name='action']")
            .value = "delete";
        pluginModal.querySelector(".osc-dialog-title")
            .textContent = "<?php echo osc_esc_js(__('Delete Plugin'))?>:" + plugin
        pluginModal.querySelector(".osc-dialog-text")
            .textContent = "<?php echo osc_esc_js(__('You are about to delete the files of the plugin. Do you want to continue?'))?>";
        pluginModal.querySelector("#pluginModalSubmit")
            .textContent = "<?php echo osc_esc_js(__('Delete')); ?>";
        pluginModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
