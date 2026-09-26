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
osc_current_admin_theme_path('parts/package-ui.php');

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
    <?php if (count($aData['aaData']) > 0) { ?>
        <?php osc_package_list_open('osc-pkg-list--plugins'); ?>
        <?php foreach ($aData['aaData'] as $array) {
            $pkg  = $array['pkg'];
            $url  = osc_admin_base_url(true) . '?page=plugins&amp;action=';
            $csrf = '&amp;' . osc_csrf_token_url();
            $file = $pkg['file'];

            $meta   = array(sprintf(__('Version %s'), osc_esc_html($pkg['version'])));
            $meta[] = $pkg['author_uri'] !== ''
                ? sprintf(__('by %s'), '<a target="_blank" rel="noopener" href="'
                    . osc_esc_html(osc_sanitize_url($pkg['author_uri'])) . '">' . osc_esc_html($pkg['author']) . '</a>')
                : sprintf(__('by %s'), osc_esc_html($pkg['author']));

            $links = array();
            if ($pkg['configurable']) {
                $links[] = '<a href="' . $url . 'admin&amp;plugin=' . urlencode($file) . $csrf . '">'
                    . osc_esc_html(__('Settings')) . '</a>';
            }
            if ($pkg['plugin_uri'] !== '') {
                $links[] = '<a target="_blank" rel="noopener" href="'
                    . osc_esc_html(osc_sanitize_url($pkg['plugin_uri'])) . '">' . osc_esc_html(__('Website')) . '</a>';
            }
            if ($pkg['support_uri'] !== '') {
                $links[] = '<a target="_blank" rel="noopener" href="'
                    . osc_esc_html(osc_sanitize_url($pkg['support_uri'])) . '">' . osc_esc_html(__('Support')) . '</a>';
            }

            $danger = array();
            if ($pkg['installed']) {
                $primary = $pkg['enabled']
                    ? array('label' => __('Disable'), 'url' => $url . 'disable&amp;plugin=' . urlencode($file) . $csrf,
                            'variant' => 'btn-secondary')
                    : array('label' => __('Enable'), 'url' => $url . 'enable&amp;plugin=' . urlencode($file) . $csrf,
                            'variant' => 'btn-primary');
                $danger[] = '<a href="' . $url . 'uninstall&amp;plugin=' . urlencode($file) . $csrf
                    . '" onclick="return uninstall_dialog(\'' . osc_esc_js($file) . '\', \''
                    . osc_esc_js($pkg['name']) . '\');">' . osc_esc_html(__('Uninstall')) . '</a>';
            } else {
                $primary  = array('label' => __('Install'),
                                  'url'   => $url . 'install&amp;plugin=' . urlencode($file) . $csrf,
                                  'variant' => 'btn-primary');
                $danger[] = '<a href="#" onclick="return delete_plugin(\'' . osc_esc_js($file) . '\');">'
                    . osc_esc_html(__('Delete files')) . '</a>';
            }

            osc_package_row(array(
                'art'          => osc_market_installed_art('plugin', $pkg['slug']),
                'slug'         => $pkg['slug'],
                'name'         => $pkg['name'],
                'state'        => $pkg['state'],
                'class'        => 'plugin-' . $pkg['state'],
                'meta'         => $meta,
                'description'  => $pkg['description'],
                'note'         => $pkg['update']
                    ? osc_esc_html(__('An update is ready for this plugin. Open the Updates tab to apply it.'))
                    : '',
                'note_variant' => 'update',
                'actions'      => array('primary' => $primary, 'links' => $links, 'danger' => $danger),
                'detail'       => array(
                    'name'              => $pkg['name'],
                    'author'            => $pkg['author'],
                    'version'           => $pkg['version'],
                    'short_description' => $pkg['description'],
                ),
            ));
        } ?>
        <?php osc_package_list_close(); ?>
    <?php } else {
        osc_admin_empty(array(
            'icon'   => 'bi-plug',
            'title'  => __('No plugins installed'),
            'text'   => __('Plugins extend what the panel and your site can do. Install one from Browse, or upload its zip file.'),
            'action' => array(
                'label'   => __('Add plugin'),
                'url'     => osc_admin_base_url(true) . '?page=plugins&amp;action=add',
                'variant' => 'primary',
            ),
        ));
    } ?>
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
    <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
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
