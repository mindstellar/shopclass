<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
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

/**
 * Class CAdminPlugins
 */
class CAdminPlugins extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        //specific things for this class
        osc_run_hook('init_admin_plugins');
    }

    // Business layer...
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            case 'add':
                $this->doView('plugins/add.php');
                break;
            case 'add_post':
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }
                osc_csrf_check();

                $package = Params::getFiles('package');
                if (isset($package['size']) && $package['size'] != 0) {
                    $path   = osc_plugins_path();
                    $status = osc_unzip_file($package['tmp_name'], $path);
                    @unlink($package['tmp_name']);
                } else {
                    $status = 3;
                }
                switch ($status) {
                    case (0):
                        $msg = _m('The plugin folder is not writable');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (1):
                        $msg = _m('The plugin has been uploaded correctly');
                        osc_add_flash_ok_message($msg, 'admin');
                        break;
                    case (2):
                        $msg = _m('The zip file is not valid');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (3):
                        $msg = _m('No file was uploaded');
                        osc_add_flash_error_message($msg, 'admin');
                        $this->redirectTo(osc_admin_base_url(true) . '?page=plugins&action=add');
                        break;
                    case (-1):
                    default:
                        $msg = _m('There was a problem adding the plugin');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                break;
            case 'install':
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }
                osc_csrf_check();
                $pn = Params::getParam('plugin');

                // set header just in case it's triggered some fatal error
                header('Location: ' . osc_admin_base_url(true) . '?page=plugins&error=' . $pn, true, '302');

                $installed = Plugins::install($pn);
                if (is_array($installed)) {
                    switch ($installed['error_code']) {
                        case ('error_output'):
                            osc_add_flash_error_message(sprintf(
                                _m('The plugin generated %d characters of <strong>unexpected output</strong> during the installation. Output: "%s"'),
                                strlen($installed['output']),
                                $installed['output']
                            ), 'admin');
                            break;
                        case ('error_installed'):
                            osc_add_flash_error_message(_m('Plugin is already installed'), 'admin');
                            break;
                        case ('error_file'):
                            osc_add_flash_error_message(
                                _m("Plugin couldn't be installed because their files are missing"),
                                'admin'
                            );
                            break;
                        case ('custom_error'):
                            osc_add_flash_error_message(sprintf(
                                _m("Plugin couldn't be installed because of: %s"),
                                $installed['msg']
                            ), 'admin');
                            break;
                        default:
                            osc_add_flash_error_message(_m("Plugin couldn't be installed"), 'admin');
                            break;
                    }
                } else {
                    osc_add_flash_ok_message(_m('Plugin installed'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                break;
            case 'uninstall':
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }
                osc_csrf_check();

                if (Plugins::uninstall(Params::getParam('plugin'))) {
                    osc_add_flash_ok_message(_m('Plugin uninstalled'), 'admin');
                } else {
                    osc_add_flash_error_message(_m("Plugin couldn't be uninstalled"), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                break;
            case 'enable':
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }
                osc_csrf_check();

                if (Plugins::activate(Params::getParam('plugin'))) {
                    osc_add_flash_ok_message(_m('Plugin enabled'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('Plugin is already enabled'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                break;
            case 'disable':
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }
                osc_csrf_check();

                if (Plugins::deactivate(Params::getParam('plugin'))) {
                    osc_add_flash_ok_message(_m('Plugin disabled'), 'admin');
                } else {
                    osc_add_flash_error_message(_m('Plugin is already disabled'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                break;
            case 'admin':
                $plugin = Params::getParam('plugin');
                if ($plugin != '') {
                    osc_run_hook($plugin . '_configure');
                }
                break;
            case 'admin_post':
                osc_run_hook('admin_post');
                break;
            case 'renderplugin':
                if (Params::existParam('route')) {
                    $routes = Rewrite::newInstance()->getRoutes();
                    $rid    = Params::getParam('route');
                    $file   = '../';
                    if (isset($routes[$rid], $routes[$rid]['file'])) {
                        $file = $routes[$rid]['file'];
                    }
                } else {
                    // DEPRECATED: Disclosed path in URL is deprecated, use routes instead
                    // This will be REMOVED in 3.4
                    $file = Params::getParam('file');
                    // We pass the GET variables (in case we have somes)
                    if (preg_match('|(.+?)\?(.*)|', $file, $match)) {
                        $file = $match[1];
                        if (preg_match_all('|&([^=]+)=([^&]*)|', urldecode('&' . $match[2] . '&'), $get_vars)) {
                            for ($var_k = 0; $var_k < count($get_vars[1]); $var_k++) {
                                Params::setParam($get_vars[1][$var_k], $get_vars[2][$var_k]);
                            }
                        }
                    } else {
                        $file = Params::getParam('file');
                    }
                }
                osc_run_hook('renderplugin_controller');
                if (strpos($file, '../') === false && strpos($file, '..\\') === false && $file != '') {
                    $this->_exportVariableToView('file', osc_plugins_path() . $file);
                    $this->doView('plugins/view.php');
                }
                break;
            case 'configure':
                $plugin = Params::getParam('plugin');
                if ($plugin != '') {
                    $plugin_data = Plugins::getInfo($plugin);
                    $this->_exportVariableToView('categories', Category::newInstance()->toTreeAll());
                    $this->_exportVariableToView(
                        'selected',
                        PluginCategory::newInstance()->listSelected($plugin_data['short_name'])
                    );
                    $this->_exportVariableToView('plugin_data', $plugin_data);
                    $this->doView('plugins/configuration.php');
                } else {
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }
                break;
            case 'configure_post':
                osc_csrf_check();
                $plugin_short_name = Params::getParam('plugin_short_name');
                $categories        = Params::getParam('categories');
                if ($plugin_short_name != '') {
                    Plugins::cleanCategoryFromPlugin($plugin_short_name);
                    if (isset($categories)) {
                        Plugins::addToCategoryPlugin($categories, $plugin_short_name);
                    }
                    osc_run_hook('plugin_categories_' . Params::getParam('plugin'), $categories);
                    osc_add_flash_ok_message(_m('Configuration was saved'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }

                osc_add_flash_error_message(_m('No plugin selected'), 'admin');
                $this->doView('plugins/index.php');
                break;
            case 'delete':
                osc_csrf_check();
                $plugin = str_replace('/index.php', '', Params::getParam('plugin'));
                $path   = preg_replace('([/]+)', '/', CONTENT_PATH . 'plugins/' . $plugin);
                if ($plugin != '' && strpos($plugin, '../') === false && strpos($plugin, '..\\') === false
                    && $path != CONTENT_PATH . 'plugins/'
                ) {
                    if (osc_deleteDir($path)) {
                        osc_add_flash_ok_message(_m('The files were deleted'), 'admin');
                    } else {
                        osc_add_flash_error_message(sprintf(
                            _m('There were an error deleting the files, please check the permissions of the files in %s'),
                            $path . '/'
                        ), 'admin');
                    }
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }

                osc_add_flash_error_message(_m('No plugin selected'), 'admin');
                $this->doView('plugins/index.php');
                break;
            case 'error_plugin':
                // force php errors and simulate plugin installation to show the errors in the iframe
                $plugin = Params::getParam('plugin');
                if (strpos($plugin, '../') !== false || strpos($plugin, '..\\') !== false) {
                    osc_add_flash_error_message(_m('Invalid plugin file'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
                }
                if (!OSC_DEBUG) {
                    error_reporting(E_ALL | E_STRICT);
                }
                @ini_set('display_errors', 1);

                include(osc_plugins_path() . $plugin);
                Plugins::install($plugin);
                exit;
                break;
            default:
                if (Params::getParam('checkUpdated') != '') {
                    osc_admin_toolbar_update_plugins(true);
                }

                if (Params::getParam('iDisplayLength') == '') {
                    Params::setParam('iDisplayLength', 25);
                }

                $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

                $p_iPage = 1;
                if (is_numeric(Params::getParam('iPage')) && Params::getParam('iPage') >= 1) {
                    $p_iPage = Params::getParam('iPage');
                }
                Params::setParam('iPage', $p_iPage);
                $aPlugin        = Plugins::listAll();
                $active_plugins = osc_get_plugins();

                // pagination
                $start = ($p_iPage - 1) * Params::getParam('iDisplayLength');
                $limit = Params::getParam('iDisplayLength');
                $count = count($aPlugin);

                $displayRecords = $limit;
                if (($start + $limit) > $count) {
                    $displayRecords = ($start + $limit) - $count;
                }
                // --------------------------------------------------------

                $aData = array();
                $aInfo = array();
                $max   = ($start + $limit);
                if ($max > $count) {
                    $max = $count;
                }
                $aPluginsToUpdate = json_decode(osc_get_preference('plugins_to_update'), true);
                $bPluginsToUpdate = is_array($aPluginsToUpdate) ? true : false;
                // Catalog-sourced updates (docs/MARKET.md) are keyed by slug and read from the
                // cached catalog only -- cheap, no network egress on page render. Most catalog
                // packages carry no `Plugin update URI`, so the legacy in_array() check below
                // (keyed on that URI) cannot tell them apart once it contains more than one
                // blank entry; this keys the per-row check on the slug instead.
                try {
                    $aMarketPendingUpdates = \mindstellar\market\PackageIndex::forPlugins()->pendingUpdates();
                } catch (\Throwable $e) {
                    $aMarketPendingUpdates = array();
                }
                for ($i = $start; $i < $max; $i++) {
                    $plugin = $aPlugin[$i];
                    $row    = array();
                    $pInfo  = osc_plugin_get_info($plugin);
                    $pSlug  = dirname($plugin) !== '.' ? dirname($plugin) : $plugin;

                    // prepare row 1
                    $installed = 0;
                    if (osc_plugin_is_installed($plugin)) {
                        $installed = 1;
                    }
                    $enabled = 0;
                    if (osc_plugin_is_enabled($plugin)) {
                        $enabled = 1;
                    }
                    // prepare row 2
                    $sUpdate = '';
                    $pUpdateUri = @$pInfo['plugin_update_uri'];
                    if (isset($aMarketPendingUpdates[$pSlug])
                        || ($bPluginsToUpdate && $pUpdateUri != '' && in_array($pUpdateUri, $aPluginsToUpdate, true))
                    ) {
                        $sUpdate = '<a class="market_update market-popup" href="#'
                            . htmlentities($pUpdateUri) . '">'
                            . __("There's a new update available") . '</a>';
                    }
                    // prepare row 4
                    $sConfigure = '';
                    if (isset($active_plugins[$plugin . '_configure'])) {
                        $sConfigure =
                            '<a href="' . osc_admin_base_url(true) . '?page=plugins&amp;action=admin&amp;plugin='
                            . $pInfo['filename'] . '&amp;' . osc_csrf_token_url() . '">' . __('Configure') . '</a>';
                    }
                    // prepare row 5
                    $sEnable = '';
                    if ($installed) {
                        if ($enabled) {
                            $sEnable =
                                '<a href="' . osc_admin_base_url(true) . '?page=plugins&amp;action=disable&amp;plugin='
                                . $pInfo['filename'] . '&amp;' . osc_csrf_token_url() . '">' . __('Disable') . '</a>';
                        } else {
                            $sEnable =
                                '<a href="' . osc_admin_base_url(true) . '?page=plugins&amp;action=enable&amp;plugin='
                                . $pInfo['filename'] . '&amp;' . osc_csrf_token_url() . '">' . __('Enable') . '</a>';
                        }
                    }
                    // prepare row 6
                    if ($installed) {
                        $sInstall = '<a onclick="javascript:return uninstall_dialog(\'' . $pInfo['filename'] . '\', \''
                            . $pInfo['plugin_name'] . '\');" href="' . osc_admin_base_url(true)
                            . '?page=plugins&amp;action=uninstall&amp;plugin=' . $pInfo['filename'] . '&amp;'
                            . osc_csrf_token_url() . '">' . __('Uninstall') . '</a>';
                    } else {
                        $sInstall =
                            '<a href="' . osc_admin_base_url(true) . '?page=plugins&amp;action=install&amp;plugin='
                            . $pInfo['filename'] . '&amp;' . osc_csrf_token_url() . '">' . __('Install') . '</a>';
                    }
                    $sDelete = '';
                    if (!$installed) {
                        $sDelete =
                            '<a onclick="delete_plugin(\'' . $pInfo['filename'] . '\');" href="#" >' . __('Delete')
                            . '</a>';
                    }

                    $sHelp = '';
                    if ($pInfo['support_uri'] != '') {
                        $sHelp = '<span class="plugin-support-icon plugin-tooltip" ><a target="_blank" href="'
                            . osc_sanitize_url($pInfo['support_uri']) . '" ><i class="bi bi-info-circle-fill" title="'
                            . osc_esc_html(__('Problems with this plugin? Ask for support.')) . '" ></i></a></span>';
                    }
                    $sSiteUrl = '';
                    if ($pInfo['plugin_uri'] != '') {
                        $sSiteUrl =
                            ' | <a target="_blank" href="' . $pInfo['plugin_uri'] . '">' . __('Plugins Site') . '</a>';
                    }
                    if ($pInfo['author_uri'] != '') {
                        $sAuthor =
                            __('By') . ' <a target="_blank" href="' . $pInfo['author_uri'] . '">' . $pInfo['author']
                            . '</a>';
                    } else {
                        $sAuthor = __('By') . ' ' . $pInfo['author'];
                    }
                    // The state of a plugin used to reach the page as a row colour and nothing
                    // else. It now travels as a word as well, rendered in the Status column as
                    // a badge; the class on the <tr> only picks the badge's tint and glyph.
                    $plugin_status = 'uninstalled';
                    $sStatusWord   = __('Not installed');
                    if ($installed) {
                        if ($enabled) {
                            $plugin_status = 'active';
                            $sStatusWord   = __('Active');
                        } else {
                            $plugin_status = 'disabled';
                            $sStatusWord   = __('Disabled');
                        }
                    }
                    $row['plugin_status'] = $plugin_status;
                    $row[]   =
                        '<input type="hidden" name="installed" value="' . $installed . '" enabled="' . $enabled . '" />'
                        . $pInfo['plugin_name'] . $sHelp . '<div>' . $sUpdate . '</div>';
                    // Keyed, not appended: the template gives this one cell the .col-status
                    // class, and it has to be able to tell which cell it is.
                    $row['status'] = '<span class="osc-status">' . osc_esc_html($sStatusWord) . '</span>';
                    $row[]   = $pInfo['description'] . '<br />' . __('Version:') . $pInfo['version'] . ' | ' . $sAuthor
                        . $sSiteUrl;
                    $row[]   = ($sUpdate != '') ? $sUpdate : '';
                    $row[]   = ($sConfigure != '') ? $sConfigure : '';
                    $row[]   = ($sEnable != '') ? $sEnable : '';
                    $row[]   = ($sInstall != '') ? $sInstall : '';
                    $row[]   = ($sDelete != '') ? $sDelete : '';
                    $aData[] = $row;
                    if (@$pInfo['plugin_update_uri'] != '') {
                        $aInfo[@$pInfo['plugin_update_uri']] = $pInfo;
                    } else {
                        $aInfo[$i] = $pInfo;
                    }
                }

                $array['iTotalRecords']        = $displayRecords;
                $array['iTotalDisplayRecords'] = count($aPlugin);
                $array['iDisplayLength']       = $limit;
                $array['aaData']               = $aData;
                $array['aaInfo']               = $aInfo;

                // --------------------------------------------------------
                $page = Params::getParamInt('iPage');
                if (count($array['aaData']) == 0 && $page != 1) {
                    $total   = $array['iTotalDisplayRecords'];
                    $maxPage = ceil($total / (int)$array['iDisplayLength']);

                    $url = osc_admin_base_url(true) . '?' . Params::getServerParam('QUERY_STRING', false, false);

                    if ($maxPage == 0) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=1', $url);
                        $this->redirectTo($url);
                    }

                    if ($page > 1) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=' . $maxPage, $url);
                        $this->redirectTo($url);
                    }
                }

                $this->_exportVariableToView('aPlugins', $array);

                list($aMarketBrowse, $aMarketUpdates, $aMarketMeta) = $this->buildMarketViewData();
                $this->_exportVariableToView('aMarketBrowse', $aMarketBrowse);
                $this->_exportVariableToView('aMarketUpdates', $aMarketUpdates);
                $this->_exportVariableToView('aMarketMeta', $aMarketMeta);

                $this->doView('plugins/index.php');
                break;
        }
    }

    //hopefully generic...

    /**
     * Browse / Updates / Meta data sets the plugins view renders (docs/MARKET.md §8.2):
     * catalog packages not yet installed, installed plugins with a pending update, and
     * the surrounding metadata (last check, write access, disabled state). Reads the
     * cached catalog only -- it never forces a live fetch on page render.
     *
     * @return array{0: array, 1: array, 2: array} [$browse, $updates, $meta]
     */
    private function buildMarketViewData()
    {
        $catalog      = \mindstellar\market\Catalog::forPlugins();
        $packageIndex = \mindstellar\market\PackageIndex::forPlugins();

        $index   = $catalog->index();
        $updates = $catalog->updates();

        $browse = array();
        foreach ($packageIndex->available() as $slug => $row) {
            $latest     = $updates[$slug][0] ?? null;
            $compatInfo = $latest !== null
                ? array(
                    'requires'     => $latest['requires'],
                    'requires_php' => $latest['requires_php'],
                    'tested_up_to' => $latest['tested'],
                )
                : array();
            $browse[] = array(
                'slug'              => $row['slug'],
                'name'              => $row['name'],
                'short_description' => $row['short_description'],
                'author'            => $row['author'],
                'version'           => $row['version'],
                'icon'              => $row['icon'],
                'categories'        => $row['categories'],
                'tags'              => $row['tags'],
                'updated_at'        => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '',
                'compat'            => array(
                    'status'  => $row['compatibility']['status'],
                    'blocked' => $row['compatibility']['blocked'],
                    'reason'  => $row['compatibility']['reason'],
                    'badge'   => \mindstellar\market\Compatibility::badgeLabel($compatInfo),
                ),
            );
        }

        $marketUpdates = array();
        foreach ($packageIndex->installed() as $slug => $row) {
            if ($row['update'] === null) {
                continue;
            }
            $update     = $row['update'];
            $compatInfo = array(
                'requires'     => $update['requires'] ?? '',
                'requires_php' => $update['requires_php'] ?? '',
                'tested_up_to' => $update['tested'] ?? '',
            );
            $verdict           = \mindstellar\market\Compatibility::evaluate($compatInfo);
            $marketUpdates[]   = array(
                'slug'              => $row['slug'],
                'name'              => $row['name'],
                'installed_version' => $row['version'],
                'new_version'       => $update['version'],
                'size'              => $update['size'] ?? 0,
                'compat'            => array(
                    'status'  => $verdict['status'],
                    'blocked' => $verdict['blocked'],
                    'reason'  => $verdict['reason'],
                    'badge'   => \mindstellar\market\Compatibility::badgeLabel($compatInfo),
                ),
            );
        }

        $categories = array();
        foreach ($index as $row) {
            foreach ((array) ($row['categories'] ?? array()) as $category) {
                if (is_string($category) && $category !== '') {
                    $categories[$category] = true;
                }
            }
        }
        $categories = array_keys($categories);
        sort($categories);

        $meta = array(
            'last_checked'      => $catalog->lastChecked(),
            'error'             => $catalog->lastError(),
            'writable'          => is_writable(osc_plugins_path()),
            'disabled'          => osc_package_installs_disabled() || defined('DEMO'),
            'categories'        => $categories,
            'catalog_available' => $index !== array() || $updates !== array(),
        );

        return array($browse, $marketUpdates, $meta);
    }
}

/* file end: ./oc-admin/CAdminPlugins.php */
