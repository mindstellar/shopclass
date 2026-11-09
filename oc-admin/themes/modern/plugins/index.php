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
    'heading' => __('Manage plugins'),
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
$aData          = __get('aData');
$aRawRows       = __get('aRawRows');
$aCounts        = __get('aCounts');
if (!is_array($aData)) {
    $aData = array('aColumns' => array(), 'aRows' => array(), 'iDisplayLength' => $iDisplayLength, 'iTotalDisplayRecords' => 0, 'iTotalRecords' => 0, 'iPage' => 1);
}
$aRawRows  = is_array($aRawRows) ? $aRawRows : array();
$aCounts   = is_array($aCounts) ? $aCounts : array();
$columns   = $aData['aColumns'];
$rows      = $aData['aRows'];
$sort      = Params::getParamString('sort');
$direction = Params::getParamString('direction');
$status    = Params::getParamString('status');
$search    = Params::getParamString('sSearch');

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
                    <?php echo osc_admin_count(count($aMarketUpdates), 'market-updates-count'); ?>
                </a></li>
        </ul>
    </div>

    <div id="market-tab-installed">
<?php if (Params::getParam('error') != '') { ?>
    <div class="flashmessage flashmessage-error">
        <?php _e("Plugin couldn't be installed because it triggered a <strong>fatal error</strong>"); ?>
        <a class="btn ico btn-mini ico-close">x</a>
        <iframe style="border:0;" width="100%" height="60"
                src="<?php echo osc_admin_base_url(true); ?>?page=plugins&amp;action=error_plugin&amp;<?php
                echo osc_csrf_token_url(); ?>&amp;plugin=<?php
                echo osc_esc_html(urlencode(Params::getParamString('error'))); ?>"></iframe>
    </div>
<?php } ?>
<div id="upload-plugins">
    <?php
    $tabBase = osc_admin_base_url(true) . '?page=plugins' . ($search !== '' ? '&sSearch=' . urlencode($search) : '');
    $tabs    = array(array('label' => __('All'), 'url' => $tabBase, 'active' => $status === '', 'count' => (int) ($aCounts['all'] ?? 0)));
    foreach (array('active' => __('Active'), 'disabled' => __('Disabled'), 'uninstalled' => __('Not installed'), 'update' => __('Update ready')) as $key => $label) {
        if ($key !== 'update' || !empty($aCounts['update']) || $status === 'update') {
            $tabs[] = array('label' => $label, 'url' => $tabBase . '&status=' . $key, 'active' => $status === $key, 'count' => (int) ($aCounts[$key] ?? 0));
        }
    }
    osc_admin_tab_nav($tabs);
    osc_admin_list_filter(array(
        'page'     => 'plugins',
        'hidden'   => $status !== '' ? array('status' => $status) : array(),
        'active'   => $search !== '',
        'reset'    => osc_admin_base_url(true) . '?page=plugins' . ($status !== '' ? '&status=' . urlencode($status) : ''),
        'fields'   => array(
            array(
                'type'        => 'search',
                'name'        => 'sSearch',
                'id'          => 'fPluginSearch',
                'label'       => __('Search plugins'),
                'placeholder' => __('Name or description'),
                'value'       => $search,
            ),
        ),
        'bulk'     => array('options' => __get('bulk_options'), 'form' => 'datatablesForm'),
        'per_page' => array('label' => __('%d Plugins'), 'current' => $iDisplayLength),
    ));
    ?>
    <form id="datatablesForm" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="plugins"/>
        <input type="hidden" name="status" value="<?php echo osc_esc_html($status); ?>"/>
        <input type="hidden" name="sSearch" value="<?php echo osc_esc_html($search); ?>"/>
        <?php
        osc_admin_data_table($aData, array(
            'sort'      => $sort,
            'direction' => $direction,
            'class'     => 'plugins-table',
            'row_class' => static fn ($key, $row) => 'plugin-' . $aRawRows[$key]['state'],
            'row_attrs' => static function ($key, $row) use ($aRawRows) {
                $pkg    = $aRawRows[$key];
                $detail = array(
                    'slug'              => $pkg['slug'],
                    'name'              => $pkg['name'],
                    'author'            => $pkg['author'],
                    'version'           => $pkg['version'],
                    'short_description' => $pkg['description'],
                );

                return array(
                    'id'               => 'pkg-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $pkg['slug']),
                    'data-market-item' => json_encode($detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                );
            },
            'cell'      => static function ($column, $value, $key) use ($aRawRows) {
                if ($column !== 'status') {
                    return null;
                }
                $pkg = $aRawRows[$key];
                // The table hands over the plain state; the badges are the theme's.
                ob_start();
                osc_package_state($pkg['state']);
                $state = (string) ob_get_clean();
                ob_start();
                osc_package_mode_badge($pkg['modes']);
                $badge = (string) ob_get_clean();
                $note  = $pkg['update'] ? osc_esc_html(__('An update is ready for this plugin. Open the Updates tab to apply it.')) : '';
                if ($note !== '' || $badge !== '') {
                    $state .= '<p class="osc-pkg-note osc-pkg-note--' . ($note !== '' ? 'update' : 'info') . '">' . $note . ' ' . $badge . '</p>';
                }

                return $state;
            },
            'empty'     => $status !== '' || $search !== ''
                ? array(
                    'icon'   => 'bi-search',
                    'title'  => __('No plugins match'),
                    'text'   => __('Try another status tab or search.'),
                    'action' => array(
                        'label'   => __('Show all plugins'),
                        'url'     => osc_admin_base_url(true) . '?page=plugins',
                        'variant' => 'secondary',
                    ),
                )
                : array(
                    'icon'   => 'bi-plug',
                    'title'  => __('No plugins installed'),
                    'text'   => __('Plugins extend what the panel and your site can do. Install one from Browse, or upload its zip file.'),
                    'action' => array(
                        'label'   => __('Add plugin'),
                        'url'     => osc_admin_base_url(true) . '?page=plugins&amp;action=add',
                        'variant' => 'primary',
                    ),
                ),
        ));
        ?>
    </form>
    <?php osc_admin_pagination($aData); ?>
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
<?php osc_admin_bulk_confirm_dialog(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
