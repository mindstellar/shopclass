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
    'section' => __('Plugins'),
    'title'   => __('Plugins'),
    'help'    => __("Install or uninstall the plugins available in your installation. In some cases, "
                    . "you'll have to configure the plugin in order to get it to work."),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=plugins&amp;action=add',
            'title' => __('Add plugin'),
        ),
    ),
));

$iDisplayLength = __get('iDisplayLength');
$aData          = __get('aPlugins');

$tab_index = 2;

osc_current_admin_theme_path('parts/market.php');

$aMarketBrowse  = __get('aMarketBrowse');
$aMarketUpdates = __get('aMarketUpdates');
$aMarketMeta    = __get('aMarketMeta');
if (!is_array($aMarketBrowse)) {
    $aMarketBrowse = array();
}
if (!is_array($aMarketUpdates)) {
    $aMarketUpdates = array();
}
if (!is_array($aMarketMeta)) {
    $aMarketMeta = array(
        'last_checked' => 0, 'error' => null, 'writable' => true,
        'disabled' => false, 'categories' => array(), 'catalog_available' => false,
    );
}

osc_register_script('admin-market', osc_asset_url_versioned(osc_current_admin_theme_js_url('market.js')), array('admin-osc', 'admin-ui-osc'));
osc_enqueue_script('admin-market');

$marketCsrf       = osc_csrf_token_url();
$marketInstallUrl = osc_admin_base_url(true) . '?page=ajax&action=market_install&type=plugin&' . $marketCsrf;
$marketUpdateUrl  = osc_admin_base_url(true) . '?page=ajax&action=market_update&type=plugin&' . $marketCsrf;
$marketRefreshUrl = osc_admin_base_url(true) . '?page=ajax&action=market_refresh&type=plugin&' . $marketCsrf;
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_page_head(__('Manage plugins')); ?>

<div class="market-app" data-type="plugin"
     data-install-url="<?php echo osc_esc_html($marketInstallUrl); ?>"
     data-update-url="<?php echo osc_esc_html($marketUpdateUrl); ?>"
     data-refresh-url="<?php echo osc_esc_html($marketRefreshUrl); ?>"
     data-i18n='<?php echo osc_esc_html(json_encode(osc_market_i18n('plugin'))); ?>'>
    <div class="osc-tab">
        <ul>
            <li><a href="#market-tab-installed"><?php _e('Installed'); ?></a></li>
            <li><a href="#market-tab-browse"><?php _e('Browse'); ?></a></li>
            <li><a href="#market-tab-updates"><?php _e('Updates'); ?>
                    <span class="market-tab-count" id="market-updates-count">(<?php echo (int) count($aMarketUpdates); ?>)</span>
                </a></li>
        </ul>
    </div>

    <div id="market-tab-installed">
<?php if (Params::getParam('error') != '') { ?>
    <!-- flash message -->
    <div class="flashmessage flashmessage-error">
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
                    <td data-col-name="<?php echo osc_esc_html(__('Name')); ?>"><?php echo $array[0] . $actions; ?></td>
                    <td class="col-status" data-col-name="<?php echo osc_esc_html(__('Status')); ?>"><?php echo $array['status']; ?></td>
                    <td data-col-name="<?php echo osc_esc_html(__('Description')); ?>"><?php echo $array[1]; ?></td>
                </tr>
            <?php } ?>
        <?php } else {
            osc_admin_table_empty(3, array(
                'icon'   => 'bi-plug',
                'title'  => __('No plugins installed'),
                'text'   => __('Plugins extend what the panel and your site can do. Install one from Browse, or upload a package.'),
                'action' => array(
                    'label'   => __('Add plugin'),
                    'url'     => osc_admin_base_url(true) . '?page=plugins&amp;action=add',
                    'variant' => 'primary',
                ),
            ));
        } ?>
        </tbody>
    </table>
    <?php osc_admin_pagination($aData); ?>

    <div class="display-select-bottom">
            <?php osc_admin_per_page(array('label' => __('%d Plugins'), 'current' => $iDisplayLength)); ?>
    </div>
</div>
    </div>

    <div id="market-tab-browse" hidden>
        <?php osc_market_render_browse($aMarketBrowse, $aMarketMeta, 'plugin'); ?>
    </div>

    <div id="market-tab-updates" hidden>
        <?php osc_market_render_updates($aMarketUpdates, $aMarketMeta, 'plugin'); ?>
    </div>

    <?php osc_market_render_detail_dialog('plugin'); ?>
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
