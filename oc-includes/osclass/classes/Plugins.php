<?php

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
 * Class Plugins
 */
class Plugins
{
    public static $plugins_infos = array();
    private static $used_hooks = array();
    private static $hooks;
    private static $installed;
    private static $enabled;

    /**
     * Nothing to set up; every member of this class is static.
     */
    public function __construct()
    {
    }

    /**
     * Run every callback registered on a filter hook, threading $content through them.
     *
     * @param string $hook
     * @param mixed  $content Value to filter; returned unchanged when nothing is registered
     * @param mixed  ...$args Extra arguments passed to each callback
     *
     * @return mixed
     */
    public static function applyFilter($hook, $content = '', ...$args)
    {
        if (isset(self::$hooks[$hook])) {
            self::$used_hooks[$hook] = true;
            for ($priority = 0; $priority <= 10; $priority++) {
                if (isset(self::$hooks[$hook][$priority]) && is_array(self::$hooks[$hook][$priority])) {
                    foreach (self::$hooks[$hook][$priority] as $fxName) {
                        if (is_callable($fxName)) {
                            $content = $fxName($content, ...$args);
                        } else {
                            trigger_error('Unknown filter ' . $fxName, E_USER_WARNING);
                        }
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Whether a plugin (as 'dir/index.php') is in the installed list.
     *
     * @param string $plugin
     *
     * @return bool
     */
    public static function isInstalled($plugin)
    {
        if (!isset(self::$installed)) {
            self::$installed = self::listInstalled();
        }
        if (in_array($plugin, self::$installed)) {
            return true;
        }

        return false;
    }

    /**
     * The installed plugins, as 'dir/index.php' paths.
     *
     * @return string[]
     */
    public static function listInstalled()
    {
        if (isset(self::$installed)) {
            return self::$installed;
        }
        $plugins_list = unserialize(osc_installed_plugins(), array('allowed_classes' => false));
        if (is_array($plugins_list)) {
            self::$installed = $plugins_list;

            return self::$installed;
        }

        return array();
    }

    /**
     * Whether a plugin (as 'dir/index.php') is in the active list.
     *
     * @param string $plugin
     *
     * @return bool
     */
    public static function isEnabled($plugin)
    {
        if (!isset(self::$enabled)) {
            self::$enabled = self::listEnabled();
        }
        if (in_array($plugin, self::$enabled)) {
            return true;
        }

        return false;
    }

    /**
     * The active plugins, as 'dir/index.php' paths.
     *
     * @return string[]
     */
    public static function listEnabled()
    {
        if (isset(self::$enabled)) {
            return self::$enabled;
        }
        $plugins_list = unserialize(osc_active_plugins(), array('allowed_classes' => false));
        if (is_array($plugins_list)) {
            self::$enabled = $plugins_list;

            return self::$enabled;
        }

        return array();
    }

    /**
     * Case-insensitive natural comparison of two plugin info arrays by name.
     *
     * @param array<string,string> $a
     * @param array<string,string> $b
     *
     * @return int
     */
    public static function strnatcmpCustom($a, $b)
    {
        return strnatcasecmp($a['plugin_name'], $b['plugin_name']);
    }

    /**
     * The plugin declaring this update URI, as 'dir/index.php'.
     *
     * @param string $uri
     *
     * @return string|false false when no plugin declares it
     */
    public static function findByUpdateURI($uri)
    {
        $plugins = self::listAll();
        foreach ($plugins as $p) {
            $info = self::getInfo($p);
            if ($info['plugin_update_uri'] === $uri) {
                return $p;
            }
        }

        return false;
    }

    /**
     * Every plugin found on disk, as 'dir/index.php' paths.
     *
     * @param bool $sort Order them enabled, then installed, then the rest — each by name
     *
     * @return string[]
     */
    public static function listAll($sort = true)
    {
        $plugins     = array();
        $pluginsPath = osc_plugins_path();
        $dir         = opendir($pluginsPath);
        while ($file = readdir($dir)) {
            if (preg_match('/^[a-zA-Z0-9-_]+$/', $file, $matches)) {
                // This has to change in order to catch any .php file
                $pluginPath = $pluginsPath . "$file/index.php";
                if (file_exists($pluginPath)) {
                    $plugins[] = $file . '/index.php';
                } else {
                    trigger_error(sprintf(__('Plugin %s is missing the index.php file %s'), $file, $pluginPath), E_USER_WARNING);
                }
            }
        }
        closedir($dir);

        if ($sort) {
            $enabled       = self::listEnabled();
            $installed     = self::listInstalled();
            $extended_list = array();
            foreach ($plugins as $p) {
                $extended_list[$p] = self::getInfo($p);
            }
            uasort($extended_list, function ($a, $b) {
                return strnatcmp($a['plugin_name'], $b['plugin_name']);
            });
            $plugins = array();
            // Enabled
            foreach ($extended_list as $k => $v) {
                if (in_array($k, $enabled)) {
                    $plugins[] = $k;
                    unset($extended_list[$k]);
                }
            }
            // Installed but disabled
            foreach ($extended_list as $k => $v) {
                if (in_array($k, $installed)) {
                    $plugins[] = $k;
                    unset($extended_list[$k]);
                }
            }
            // Not installed
            foreach ($extended_list as $k => $v) {
                $plugins[] = $k;
            }
        }

        return $plugins;
    }

    /**
     * The header fields declared in a plugin's index.php, parsed once and cached.
     *
     * @param string $plugin 'dir/index.php' path
     *
     * @return array<string,string>
     */
    public static function getInfo($plugin)
    {
        if (isset(self::$plugins_infos[$plugin])) {
            return self::$plugins_infos[$plugin];
        }
        $s_info = file_get_contents(osc_plugins_path() . $plugin);
        $info   = array();
        if (preg_match('|Plugin Name:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['plugin_name'] = trim($match[1]);
        } else {
            $info['plugin_name'] = $plugin;
        }

        if (preg_match('|Plugin URI:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['plugin_uri'] = trim($match[1]);
        } else {
            $info['plugin_uri'] = '';
        }

        if (preg_match('|Plugin update URI:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['plugin_update_uri'] = trim($match[1]);
        } else {
            $info['plugin_update_uri'] = '';
        }

        if (preg_match('|Support URI:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['support_uri'] = trim($match[1]);
        } else {
            $info['support_uri'] = '';
        }

        if (preg_match('|Description:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['description'] = trim($match[1]);
        } else {
            $info['description'] = '';
        }

        if (preg_match('|Version:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['version'] = trim($match[1]);
        } else {
            $info['version'] = '';
        }

        if (preg_match('|Author:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['author'] = trim($match[1]);
        } else {
            $info['author'] = '';
        }

        if (preg_match('|Author URI:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['author_uri'] = trim($match[1]);
        } else {
            $info['author_uri'] = '';
        }

        if (preg_match('|Short Name:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['short_name'] = trim($match[1]);
        } else {
            $info['short_name'] = $info['plugin_name'];
        }

        if (preg_match('|Requires Shopclass:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['requires'] = trim($match[1]);
        } else {
            $info['requires'] = '';
        }

        if (preg_match('|Tested up to:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['tested_up_to'] = trim($match[1]);
        } else {
            $info['tested_up_to'] = '';
        }

        if (preg_match('|Requires PHP:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['requires_php'] = trim($match[1]);
        } else {
            $info['requires_php'] = '';
        }

        $info['filename']             = $plugin;
        self::$plugins_infos[$plugin] = $info;

        return $info;
    }

    /**
     * Absolute path to a file inside the plugins directory, if it exists.
     *
     * @param string $path Path relative to the plugins directory
     *
     * @return string|false
     */
    public static function resource($path)
    {
        $fullPath = PLUGINS_PATH . $path;

        return file_exists($fullPath) ? $fullPath : false;
    }

    /**
     * Register the callback that runs when this plugin is installed.
     *
     * @param string   $path     The plugin's own __FILE__
     * @param callable $function
     *
     * @return void
     */
    public static function register($path, $function)
    {
        $path = str_replace(PLUGINS_PATH, '', $path);
        $tmp  = explode('oc-content/plugins/', $path);
        if (count($tmp) == 2) {
            $path = $tmp[1];
        }
        self::addHook('install_' . $path, $function);
    }

    /**
     * Register a callback on a hook, unless that exact callback is already on it.
     *
     * @param string   $hook
     * @param callable $function
     * @param int      $priority 0-10; lower runs first
     *
     * @return void
     */
    public static function addHook($hook, $function, $priority = 5)
    {
        $hook         = preg_replace('|/+|', '/', str_replace('\\', '/', $hook));
        $plugin_path  = str_replace('\\', '/', osc_plugins_path());
        $hook         = str_replace($plugin_path, '', $hook);
        $found_plugin = false;
        if (isset(self::$hooks[$hook])) {
            for ($_priority = 0; $_priority <= 10; $_priority++) {
                if (isset(self::$hooks[$hook][$_priority])) {
                    foreach (self::$hooks[$hook][$_priority] as $fxName) {
                        if ($fxName === $function) {
                            $found_plugin = true;
                            break;
                        }
                    }
                }
            }
        }
        if (!$found_plugin) {
            self::$hooks[$hook][$priority][] = $function;
        }
    }

    /**
     * Install and activate a plugin.
     *
     * @param string $path 'dir/index.php' path
     *
     * @return true|array<string,string> true on success, else an array carrying error_code
     */
    public static function install($path)
    {
        osc_run_hook('before_plugin_install');
        self::resetOpcache();

        $plugins_list = unserialize(osc_installed_plugins(), array('allowed_classes' => false));

        if (is_array($plugins_list) && in_array($path, $plugins_list)) {
            return array('error_code' => 'error_installed');
        }

        if (!file_exists(PLUGINS_PATH . $path)) {
            return array('error_code' => 'error_file');
        }

        try {
            include_once PLUGINS_PATH . $path;

            self::runHook('install_' . $path);
        } catch (Exception $e) {
            return array('error_code' => 'custom_error', 'msg' => $e->getMessage());
        }

        if (!self::activate($path)) {
            return array('error_code' => '');
        }

        $plugins_list[] = $path;
        osc_set_preference('installed_plugins', serialize($plugins_list));

        // Check if something failed
        if (ob_get_length() > 0) {
            return array('error_code' => 'error_output', 'output' => ob_get_clean());
        }

        osc_run_hook('after_plugin_install');

        return true;
    }

    /**
     * Run every callback registered on an action hook, in priority order.
     *
     * @param string $hook
     * @param mixed  ...$args Arguments passed to each callback
     *
     * @return void
     */
    public static function runHook($hook, ...$args)
    {
        if (isset(self::$hooks[$hook])) {
            self::$used_hooks[$hook] = true;
            for ($priority = 0; $priority <= 10; $priority++) {
                if (isset(self::$hooks[$hook][$priority]) && is_array(self::$hooks[$hook][$priority])) {
                    foreach (self::$hooks[$hook][$priority] as $fxName) {
                        if (is_callable($fxName)) {
                            $fxName(...$args);
                        } else {
                            trigger_error('Invalid callable ' . $fxName . ' on hook ' . $hook, E_USER_WARNING);
                        }
                    }
                }
            }
        }
    }

    /**
     * Add a plugin to the active list and fire its enable hook.
     *
     * @param string $path 'dir/index.php' path
     *
     * @return bool false when it was already active
     */
    public static function activate($path)
    {
        osc_run_hook('before_plugin_activate');
        self::resetOpcache();

        $plugins_list = unserialize(osc_active_plugins(), array('allowed_classes' => false));

        if (is_array($plugins_list) && in_array($path, $plugins_list)) {
            return false;
        }

        $plugins_list[] = $path;
        osc_set_preference('active_plugins', serialize($plugins_list));

        self::reload();

        self::runHook($path . '_enable');

        osc_run_hook('after_plugin_activate');

        return true;
    }

    /**
     * Re-read the preferences and load the active plugins again.
     *
     * @return void
     */
    public static function reload()
    {
        osc_reset_preferences();
        self::init();
    }

    /**
     * Load the active plugins.
     *
     * @return void
     */
    public static function init()
    {
        self::loadActive();
    }

    /**
     * Include every active plugin's entry file, which is what registers its hooks.
     *
     * @return void
     */
    public static function loadActive()
    {

        $plugins_list = self::listEnabled();

        if (is_array($plugins_list) && count($plugins_list) > 0) {
            foreach ($plugins_list as $plugin_name) {
                $pluginPath = PLUGINS_PATH . $plugin_name;
                if (file_exists($pluginPath)) {
                    //This should include the file and adds the hooks
                    include_once $pluginPath;
                }
            }
        }
    }

    /**
     * Drop the compiled-bytecode (opcache) cache so a plugin's code change takes
     * effect immediately. With opcache.validate_timestamps=Off (the usual production
     * setting) a just-installed, updated, activated or deactivated plugin would keep
     * running the OLD bytecode until php-fpm restarts — which looks like the change
     * "not taking", or worse, runs a stale class against new state and fatals. Called
     * from a web request (the normal admin flow) this clears the FPM pool's cache so
     * the next request recompiles from disk. Public so theme activation can reuse it.
     *
     * @return void
     */
    public static function resetOpcache()
    {
        if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
            @opcache_reset();
        }
    }

    /**
     * Check if hook had run previously
     *
     * @param string $hook
     *
     * @return bool
     * @since 4.0.0
     */
    public static function hadRun($hook)
    {
        return isset(self::$used_hooks[$hook]);
    }

    /**
     * Check if hook is registered
     *
     * @param string $hook
     *
     * @return bool
     * @since 4.0.0
     */
    public static function hasHook($hook)
    {
        if (!isset(self::$hooks[$hook])) {
            return false;
        }
        // removeHook() unsets individual callbacks but leaves the (now empty) priority
        // buckets behind, so a bare isset() reports true forever once a hook has ever been
        // registered. Answer the question the name asks: is anything still listening?
        foreach (self::$hooks[$hook] as $callbacks) {
            if (!empty($callbacks)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deactivate a plugin, run its uninstall hook and drop it from the installed list.
     *
     * @param string $path 'dir/index.php' path
     *
     * @return bool false when the installed list could not be read
     */
    public static function uninstall($path)
    {
        osc_run_hook('before_plugin_uninstall');
        self::resetOpcache();

        $plugins_list = unserialize(osc_installed_plugins(), array('allowed_classes' => false));

        $path = str_replace(PLUGINS_PATH, '', $path);
        if (!is_array($plugins_list)) {
            return false;
        }

        include_once PLUGINS_PATH . $path;

        self::deactivate($path);
        /* if( !self::deactivate($path) ) {
          return false;
          } */

        self::runHook($path . '_uninstall');

        foreach ($plugins_list as $k => $v) {
            if ($v == $path) {
                unset($plugins_list[$k]);
            }
        }

        osc_set_preference('installed_plugins', serialize($plugins_list));

        $plugin = self::getInfo($path);
        self::cleanCategoryFromPlugin($plugin['short_name']);

        osc_run_hook('after_plugin_uninstall');

        return true;
    }

    /**
     * Remove a plugin from the active list and fire its disable hook.
     *
     * @param string $path 'dir/index.php' path
     *
     * @return bool false when the active list could not be read
     */
    public static function deactivate($path)
    {
        osc_run_hook('before_plugin_deactivate');
        self::resetOpcache();

        $plugins_list = unserialize(osc_active_plugins(), array('allowed_classes' => false));

        $path = str_replace(osc_plugins_path(), '', $path);
        // check if there is some plugin enabled
        if (!is_array($plugins_list)) {
            return false;
        }

        // remove $path from the active plugins list
        foreach ($plugins_list as $k => $v) {
            if ($v == $path) {
                unset($plugins_list[$k]);
            }
        }

        self::runHook($path . '_disable');

        // update t_preference field for active plugins
        osc_set_preference('active_plugins', serialize($plugins_list));

        self::reload();

        osc_run_hook('after_plugin_deactivate');

        return true;
    }

    /**
     * Drop every category association held by a plugin.
     *
     * @param string $plugin The plugin's short name
     *
     * @return void
     */
    public static function cleanCategoryFromPlugin($plugin)
    {
        $dao_pluginCategory = new PluginCategory();
        $dao_pluginCategory->delete(array('s_plugin_name' => $plugin));
        unset($dao_pluginCategory);
    }

    /**
     * Whether a plugin is associated with a category.
     *
     * @param string $name The plugin's short name
     * @param int    $id   Category id
     *
     * @return bool
     */
    public static function isThisCategory($name, $id)
    {
        return PluginCategory::newInstance()->isThisCategory($name, $id);
    }

    /**
     * Whether the catalogue offers this plugin a version it can safely move to.
     *
     * Reads the same PackageIndex the admin list and the toolbar counter read, so one
     * plugin's answer can never disagree with the count beside it. A catalogue failure
     * is absorbed as "no update", exactly as _osc_check_plugins_update() does -- a
     * network problem must not surface as an update prompt.
     *
     * @param string $plugin 'dir/index.php' path
     *
     * @return bool
     * @deprecated since 6.3.0 use mindstellar\market\PackageIndex::forPlugins()->pendingUpdates() instead
     * @see \mindstellar\market\PackageIndex::pendingUpdates()
     */
    public static function checkUpdate($plugin)
    {
        try {
            $pending = \mindstellar\market\PackageIndex::forPlugins()->pendingUpdates();
        } catch (\Throwable $e) {
            return false;
        }

        return isset($pending[dirname($plugin)]);
    }

    /**
     * Redirect to a plugin's configuration screen, resolving a plugin name to its path.
     *
     * @param string $path 'dir/index.php' path, or the plugin's declared name
     *
     * @return void
     */
    public static function configureView($path)
    {
        $plugin = str_replace(PLUGINS_PATH, '', $path);
        if (stripos($plugin, '.php') === false) {
            $plugins_list = unserialize(osc_active_plugins(), array('allowed_classes' => false));
            if (is_array($plugins_list)) {
                foreach ($plugins_list as $p) {
                    $data = self::getInfo($p);
                    if ($plugin == $data['plugin_name']) {
                        $plugin = $p;
                        break;
                    }
                }
            }
        }
        osc_redirect_to(osc_plugin_configure_url($plugin));
    }

    /**
     * Associate a plugin with the given categories, and their subcategories.
     *
     * @param int[]  $categories
     * @param string $plugin     The plugin's short name
     *
     * @return void
     */
    public static function addToCategoryPlugin($categories, $plugin)
    {
        $dao_pluginCategory = new PluginCategory();
        $dao_category       = new Category();
        if (!empty($categories)) {
            foreach ($categories as $catId) {
                $result = $dao_pluginCategory->isThisCategory($plugin, $catId);
                if ($result == 0) {
                    $fields                     = array();
                    $fields['s_plugin_name']    = $plugin;
                    $fields['fk_i_category_id'] = $catId;
                    $dao_pluginCategory->insert($fields);

                    $subs = $dao_category->findSubcategories($catId);
                    if (is_array($subs) && count($subs) > 0) {
                        $cats = array();
                        foreach ($subs as $sub) {
                            $cats[] = $sub['pk_i_id'];
                        }
                        self::addToCategoryPlugin($cats, $plugin);
                    }
                }
            }
        }
        unset($dao_pluginCategory, $dao_category);
    }

    /**
     * Unregister a callback from a hook, whatever priority it was added at.
     *
     * @param string   $hook
     * @param callable $function
     *
     * @return void
     */
    public static function removeHook($hook, $function)
    {
        for ($priority = 0; $priority <= 10; $priority++) {
            if (isset(self::$hooks[$hook][$priority])) {
                foreach (self::$hooks[$hook][$priority] as $k => $v) {
                    if ($v == $function) {
                        unset(self::$hooks[$hook][$priority][$k]);
                    }
                }
            }
        }
    }

    /**
     * Every registered hook, as hook name => priority => list of callbacks.
     *
     * @return array<string,array<int,array<int,callable>>>
     */
    public static function getActive()
    {
        return self::$hooks;
    }
}
