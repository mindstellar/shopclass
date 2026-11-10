<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
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

use mindstellar\admin\BulkAction;
use mindstellar\admin\ListPaging;
use mindstellar\market\PluginDependencies;
use mindstellar\security\PluginAjaxFile;

/**
 * Class CAdminPlugins
 */
class CAdminPlugins extends AdminSecBaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to plugins(). */
    private const ACTIONS = array(
        'add'            => 'addForm',
        'add_post'       => 'addPost',
        'install'        => 'install',
        'uninstall'      => 'uninstall',
        'enable'         => 'enable',
        'disable'        => 'disable',
        'admin'          => 'pluginAdmin',
        'admin_post'     => 'pluginAdminPost',
        'renderplugin'   => 'renderPlugin',
        'configure'      => 'configure',
        'configure_post' => 'configurePost',
        'delete'         => 'deletePlugin',
        'error_plugin'   => 'errorPlugin',
        'bulk_enable'    => 'bulkEnable',
        'bulk_disable'   => 'bulkDisable',
        'bulk_uninstall' => 'bulkUninstall',
    );

    /**
     * Let plugins hook the plugins section before anything is dispatched.
     */
    public function __construct()
    {
        parent::__construct();
        //specific things for this class
        osc_run_hook('init_admin_plugins');
    }

    // Business layer...

    /**
     * Dispatch the requested plugins action: upload, install, uninstall, enable, disable,
     * delete, a plugin's own admin and configure screens, and the market browser.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        $method = $this->actionMethod('plugins');

        $this->$method();
    }

    /**
     * The form to upload a plugin package.
     */
    private function addForm(): void
    {
        $this->doView('plugins/add.php');
    }

    /**
     * Install an uploaded plugin package.
     */
    private function addPost(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=plugins')) {
            return;
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
                $msg = _m('Plugin uploaded');
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
                $msg = _m('The plugin could not be added.');
                osc_add_flash_error_message($msg, 'admin');
                break;
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
    }

    /**
     * Install a plugin.
     */
    private function install(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=plugins')) {
            return;
        }
        osc_csrf_check();
        $pn = Params::getParamString('plugin');
        if ($this->refuseMissingRequirements($pn)) {
            return;
        }

        // set header just in case it's triggered some fatal error
        header('Location: ' . osc_admin_base_url(true) . '?page=plugins&error=' . $pn, true, 302);

        $error = Plugins::installError(Plugins::install($pn));
        if ($error !== null) {
            osc_add_flash_error_message(osc_esc_html($error), 'admin');
        } else {
            osc_add_flash_ok_message(_m('Plugin installed'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
    }

    /**
     * Uninstall a plugin.
     */
    private function uninstall(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=plugins')) {
            return;
        }
        osc_csrf_check();

        if ($this->uninstallOne(Params::getParamString('plugin'))) {
            osc_add_flash_ok_message(_m('Plugin uninstalled'), 'admin');
        } else {
            osc_add_flash_error_message(_m("Plugin couldn't be uninstalled"), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
    }

    /**
     * Turn a plugin on.
     */
    private function enable(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=plugins')) {
            return;
        }
        osc_csrf_check();

        $result = $this->enableOne(Params::getParamString('plugin'));
        if (is_string($result)) {
            osc_add_flash_error_message(osc_esc_html($result), 'admin');
        } elseif ($result) {
            osc_add_flash_ok_message(_m('Plugin enabled'), 'admin');
        } else {
            osc_add_flash_error_message(_m('Plugin is already enabled'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
    }

    /**
     * Send the admin back with an error when a plugin the given one requires is not ready.
     */
    private function refuseMissingRequirements(string $plugin): bool
    {
        $error = $this->missingRequirements($plugin);
        if ($error === null) {
            return false;
        }
        osc_add_flash_error_message(osc_esc_html($error), 'admin');
        $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');

        return true;
    }

    /**
     * Why a plugin cannot be turned on yet, or null when nothing it requires is missing.
     */
    private function missingRequirements(string $plugin): ?string
    {
        return Plugins::isEnabled($plugin) ? null : PluginDependencies::refuseActivation($plugin);
    }

    /**
     * Turn one installed plugin on: true when it changed, false when there was nothing to
     * change, or the reason it was refused.
     */
    private function enableOne(string $plugin): bool|string
    {
        if (!Plugins::isInstalled($plugin)) {
            return false;
        }

        return $this->missingRequirements($plugin) ?? Plugins::activate($plugin);
    }

    /**
     * Turn one installed plugin off: true when it changed.
     */
    private function disableOne(string $plugin): bool
    {
        return Plugins::isInstalled($plugin) && Plugins::deactivate($plugin);
    }

    /**
     * Uninstall one installed plugin: true when it changed.
     */
    private function uninstallOne(string $plugin): bool
    {
        return Plugins::isInstalled($plugin) && Plugins::uninstall($plugin);
    }

    /**
     * Turn the selected plugins on.
     */
    private function bulkEnable(): void
    {
        $changed = $this->bulk(
            fn (string $plugin) => $this->enableOne($plugin),
            static fn (int $n): string => sprintf(_mn('%d plugin has been enabled', '%d plugins have been enabled', $n), $n)
        );
        if ($changed !== null) {
            osc_run_hook('plugin_bulk_enable', $changed);
            $this->redirectTo($this->listUrl());
        }
    }

    /**
     * Turn the selected plugins off.
     */
    private function bulkDisable(): void
    {
        $changed = $this->bulk(
            fn (string $plugin) => $this->disableOne($plugin),
            static fn (int $n): string => sprintf(_mn('%d plugin has been disabled', '%d plugins have been disabled', $n), $n)
        );
        if ($changed !== null) {
            osc_run_hook('plugin_bulk_disable', $changed);
            $this->redirectTo($this->listUrl());
        }
    }

    /**
     * Uninstall the selected plugins.
     */
    private function bulkUninstall(): void
    {
        $changed = $this->bulk(
            fn (string $plugin) => $this->uninstallOne($plugin),
            static fn (int $n): string => sprintf(_mn('%d plugin has been uninstalled', '%d plugins have been uninstalled', $n), $n)
        );
        if ($changed !== null) {
            osc_run_hook('plugin_bulk_uninstall', $changed);
            $this->redirectTo($this->listUrl());
        }
    }

    /**
     * Run one single-plugin step over the selected plugins and flash how many changed and
     * which were skipped. Returns the plugins that changed, or null when refused on a demo.
     *
     * @param callable(string):(bool|string) $step
     * @param callable(int):string           $done the message for how many changed
     *
     * @return string[]|null
     */
    private function bulk(callable $step, callable $done): ?array
    {
        if ($this->refuseOnDemo($this->listUrl())) {
            return null;
        }
        osc_csrf_check();

        $changed = array();
        $skipped = array();
        $known   = array_flip(Plugins::listAll(false));
        foreach (array_unique(array_filter(Params::getParamArray('plugin'), 'is_string')) as $plugin) {
            $result = isset($known[$plugin]) ? $step($plugin) : false;
            if ($result === true) {
                $changed[] = $plugin;
            } elseif (is_string($result)) {
                osc_add_flash_error_message(osc_esc_html($result), 'admin');
            } else {
                $skipped[] = $plugin;
            }
        }

        osc_add_flash_ok_message($done(count($changed)), 'admin');
        if ($skipped !== array()) {
            osc_add_flash_warning_message(osc_esc_html(sprintf(
                _m('Skipped, nothing to change: %s'),
                implode(', ', array_map(static fn (string $p): string => dirname($p) !== '.' ? dirname($p) : $p, $skipped))
            )), 'admin');
        }

        return $changed;
    }

    /**
     * The plugins list with the status tab and search the bulk form came from.
     */
    private function listUrl(): string
    {
        $url    = osc_admin_base_url(true) . '?page=plugins';
        $status = Params::getParamString('status');
        if (in_array($status, PluginsDataTable::STATUSES, true)) {
            $url .= '&status=' . $status;
        }
        $search = trim(Params::getParamString('sSearch'));
        if ($search !== '') {
            $url .= '&sSearch=' . urlencode($search);
        }

        return $url;
    }

    /**
     * Turn a plugin off.
     */
    private function disable(): void
    {
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=plugins')) {
            return;
        }
        osc_csrf_check();

        if ($this->disableOne(Params::getParamString('plugin'))) {
            osc_add_flash_ok_message(_m('Plugin disabled'), 'admin');
        } else {
            osc_add_flash_error_message(_m('Plugin is already disabled'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
    }

    /**
     * A plugin's own settings screen, through its _configure hook.
     */
    private function pluginAdmin(): void
    {
        $plugin = Params::getParamString('plugin');
        if ($plugin != '') {
            osc_run_hook($plugin . '_configure');
        }
    }

    /**
     * Save a plugin's own settings screen, through the admin_post hook.
     */
    private function pluginAdminPost(): void
    {
        osc_run_hook('admin_post');
    }

    /**
     * Render a plugin's admin page file.
     */
    private function renderPlugin(): void
    {
        if (Params::existParam('route')) {
            $routes = Rewrite::getInstance()->getRoutes();
            $rid    = Params::getParamString('route');
            $file   = '../';
            if (isset($routes[$rid], $routes[$rid]['file'])) {
                $file = $routes[$rid]['file'];
            }
        } else {
            // DEPRECATED: Disclosed path in URL is deprecated, use routes instead
            // Kept: old plugins still link to file= pages.
            $file = Params::getParamString('file');
            // We pass the GET variables (in case we have somes)
            if (preg_match('|(.+?)\?(.*)|', $file, $match)) {
                $file = $match[1];
                if (preg_match_all('|&([^=]+)=([^&]*)|', urldecode('&' . $match[2] . '&'), $get_vars)) {
                    for ($var_k = 0; $var_k < count($get_vars[1]); $var_k++) {
                        Params::setParam($get_vars[1][$var_k], $get_vars[2][$var_k]);
                    }
                }
            } else {
                $file = Params::getParamString('file');
            }
        }
        osc_run_hook('renderplugin_controller');

        // This route ends in require_once, so the path is resolved here rather
        // than pattern-matched: .php only, and inside the plugins directory once
        // symlinks are followed. Checking for the literal '../' let anything else
        // in the tree through — a README, an uploaded file a plugin had written —
        // and every one of those is executed as PHP by the view.
        $resolved = PluginAjaxFile::resolve($file, osc_plugins_path());
        if ($resolved !== null) {
            $this->_exportVariableToView('file', $resolved);
            Plugins::runAs('plugin', Plugins::slug($file), fn () => $this->doView('plugins/view.php'));
        }
    }

    /**
     * The categories a plugin applies to.
     */
    private function configure(): void
    {
        $plugin = Params::getParamString('plugin');
        if ($plugin != '') {
            $plugin_data = Plugins::getInfo($plugin);
            $this->_exportVariableToView('categories', Category::getInstance()->toTreeAll());
            $this->_exportVariableToView(
                'selected',
                PluginCategory::getInstance()->listSelected($plugin_data['short_name'])
            );
            $this->_exportVariableToView('plugin_data', $plugin_data);
            $this->doView('plugins/configuration.php');
        } else {
            $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
        }
    }

    /**
     * Save the categories a plugin applies to.
     */
    private function configurePost(): void
    {
        osc_csrf_check();
        $plugin_short_name = Params::getParamString('plugin_short_name');
        $categories        = Params::getParam('categories');
        if ($plugin_short_name != '') {
            try {
                Plugins::cleanCategoryFromPlugin($plugin_short_name);
                Plugins::addToCategoryPlugin($categories, $plugin_short_name);
            } catch (\InvalidArgumentException $e) {
                osc_add_flash_error_message(_m('No plugin selected'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
            }
            osc_run_hook('plugin_categories_' . Params::getParamString('plugin'), $categories);
            osc_add_flash_ok_message(_m('Configuration was saved'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
        }

        osc_add_flash_error_message(_m('No plugin selected'), 'admin');
        $this->doView('plugins/index.php');
    }

    /**
     * Delete a plugin's files.
     */
    private function deletePlugin(): void
    {
        osc_csrf_check();
        $plugin = str_replace('/index.php', '', Params::getParamString('plugin'));
        $path   = preg_replace('([/]+)', '/', CONTENT_PATH . 'plugins/' . $plugin);
        if ($plugin != '' && strpos($plugin, '../') === false && strpos($plugin, '..\\') === false
            && $path != CONTENT_PATH . 'plugins/'
        ) {
            if (osc_deleteDir($path)) {
                osc_add_flash_ok_message(_m('The files were deleted'), 'admin');
            } else {
                osc_add_flash_error_message(sprintf(
                    _m('The plugin files could not be deleted. Check the permissions of %s.'),
                    $path . '/'
                ), 'admin');
            }
            $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
        }

        osc_add_flash_error_message(_m('No plugin selected'), 'admin');
        $this->doView('plugins/index.php');
    }

    /**
     * Load a plugin file on its own, to show the error it throws.
     */
    private function errorPlugin(): void
    {
        // force php errors and simulate plugin installation to show the errors in the iframe
        osc_csrf_check();
        if ($this->refuseOnDemo(osc_admin_base_url(true) . '?page=plugins')) {
            return;
        }
        $plugin   = Params::getParamString('plugin');
        $resolved = PluginAjaxFile::resolve($plugin, osc_plugins_path());
        if ($resolved === null) {
            osc_add_flash_error_message(_m('Invalid plugin file'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=plugins');
            return;
        }
        if (!OSC_DEBUG) {
            error_reporting(E_ALL);
        }
        @ini_set('display_errors', '1');

        include $resolved;
        Plugins::install($plugin);
        exit;
    }

    /**
     * The plugins table.
     */
    private function plugins(): void
    {
        if (Params::getParam('checkUpdated') != '') {
            osc_admin_toolbar_update_plugins(true);
        }
        // An option a plugin added through plugin_bulk_filter: its own handler does the work.
        $action = Params::getParamString('action');
        $selected = Params::getParamArray('plugin');
        if ($action !== '' && $selected !== []) {
            osc_csrf_check();
            osc_run_hook('plugin_bulk_action', $action, $selected);
        }

        require_once osc_lib_path() . 'osclass/classes/datatables/PluginsDataTable.php';

        $limit = ListPaging::length(25);
        Params::setParam('iDisplayLength', $limit);
        $this->_exportVariableToView('iDisplayLength', $limit);
        $page = ListPaging::page();

        if (Params::getParamString('sort') === '') {
            Params::setParam('sort', 'status');
        }
        if (Params::getParamString('direction') === '') {
            Params::setParam('direction', 'asc');
        }

        $table = new PluginsDataTable(PluginsDataTable::packages());
        $aData = $table->table(array(
            'status'         => Params::getParamString('status'),
            'sSearch'        => Params::getParamString('sSearch'),
            'sort'           => Params::getParamString('sort'),
            'direction'      => Params::getParamString('direction'),
            'iPage'          => $page,
            'iDisplayLength' => $limit,
        ));

        $pastEnd = ListPaging::pastEnd($aData, $page);
        if ($pastEnd !== null) {
            $this->redirectTo($pastEnd);
        }

        $this->_exportVariableToView('aData', $aData);
        $this->_exportVariableToView('aRawRows', $table->rawRows());
        $this->_exportVariableToView('aCounts', $table->counts());

        $bulk_options = BulkAction::options(
            array(
                'bulk_enable'    => __('Enable'),
                'bulk_disable'   => __('Disable'),
                'bulk_uninstall' => __('Uninstall'),
            ),
            __('Are you sure you want to %s the selected plugins?')
        );
        $this->_exportVariableToView('bulk_options', osc_apply_filter('plugin_bulk_filter', $bulk_options));

        list($aMarketBrowse, $aMarketUpdates, $aMarketMeta) = $this->buildMarketViewData();
        $this->_exportVariableToView('aMarketBrowse', $aMarketBrowse);
        $this->_exportVariableToView('aMarketUpdates', $aMarketUpdates);
        $this->_exportVariableToView('aMarketMeta', $aMarketMeta);

        $this->doView('plugins/index.php');
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
                'downloads'         => is_int($row['downloads'] ?? null) ? $row['downloads'] : 0,
                'mode_only'         => $row['mode_only'],
                'compat'            => array(
                    'status'  => $row['compatibility']['status'],
                    'blocked' => $row['compatibility']['blocked'],
                    'reason'  => $row['compatibility']['reason'],
                    // One fact about this install, from the status already decided above:
                    // what it needs, how far it was tested, or the version it works with.
                    'badge'   => \mindstellar\market\Compatibility::verdictLabel(
                        $row['compatibility']['status'],
                        $row['offer'] !== array() ? $row['offer'] : array(
                            'requires'     => is_string($row['requires_min'] ?? null) ? $row['requires_min'] : '',
                            'tested_up_to' => is_string($row['tested_max'] ?? null) ? $row['tested_max'] : '',
                        )
                    ),
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
                    // What this specific update asks of this install.
                    'badge'   => \mindstellar\market\Compatibility::verdictLabel(
                        $verdict['status'],
                        $compatInfo
                    ),
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
            'disabled'          => osc_market_changes_blocked(),
            'categories'        => $categories,
            'catalog_available' => $index !== array() || $updates !== array(),
        );

        // The toolbar count is saved once a day; recount when it disagrees with this list,
        // so the header drawn next shows the same number as the Updates tab.
        if ((int) osc_update_check_state('plugins')['count'] !== count($marketUpdates)) {
            osc_admin_toolbar_update_plugins(true);
        }

        return array($browse, $marketUpdates, $meta);
    }
}

/* file end: ./oc-admin/CAdminPlugins.php */
