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
<form id="pluginModal" method="get" action="<?php echo osc_admin_base_url(true); ?>"
      class="modal fade static">
    <input type="hidden" name="page" value="plugins"/>
    <input type="hidden" name="action" value=""/>
    <input type="hidden" name="plugin" value=""/>
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Cancel'); ?></button>
                <button id="pluginModalSubmit" class="btn btn-sm btn-red" type="submit">
                </button>
            </div>
        </div>
    </div>
</form>
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
        pluginModal.querySelector(".modal-title")
            .textContent = title;
        pluginModal.querySelector(".modal-body")
            .textContent = "<?php echo osc_esc_js(__('This action can not be undone.'
                                                     . ' Uninstalling plugins may result in a permanent loss of data. '
                                                     . 'Are you sure you want to continue?')); ?>";
        pluginModal.querySelector("#pluginModalSubmit")
            .textContent = "<?php echo osc_esc_js(__('Uninstall')); ?>";
        (new bootstrap.Modal(document.getElementById("pluginModal"))).toggle()
        return false;
    }

    function delete_plugin(plugin) {
        var pluginModal = document.getElementById("pluginModal")
        pluginModal.querySelector("input[name='plugin']")
            .value = plugin;
        pluginModal.querySelector("input[name='action']")
            .value = "delete";
        pluginModal.querySelector(".modal-title")
            .textContent = "<?php echo osc_esc_js(__('Delete Plugin'))?>:" + plugin
        pluginModal.querySelector(".modal-body")
            .textContent = "<?php echo osc_esc_js(__('You are about to delete the files of the plugin. Do you want to continue?'))?>";
        pluginModal.querySelector("#pluginModalSubmit")
            .textContent = "<?php echo osc_esc_js(__('Delete')); ?>";
        (new bootstrap.Modal(document.getElementById("pluginModal"))).toggle()
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
