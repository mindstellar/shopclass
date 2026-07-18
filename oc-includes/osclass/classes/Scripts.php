<?php
/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\utility\Deprecate;

/**
 * Scripts enqueue class.
 *
 * @since 3.1.1
 */
class Scripts extends Dependencies
{

    private static $instance;
    /**
     * Keep an array of loaded scripts
     *
     * @var array
     */
    private $scriptsLoaded;

    public function __construct()
    {
        parent::__construct();
        $this->scriptsLoaded = array();
    }

    /**
     * Enqueue Script Code to footer_scripts_loaded hook
     *
     * @param string $code         javascript code string with script tag
     * @param array  $dependencies ids array of registered js libraries (not script code) this code depends on
     */
    public static function enqueueScriptCode($code, $dependencies = null, $admin = false)
    {
        $prefix = '';
        if ($admin === true) {
            $prefix = 'admin_';
        }
        $print_code = static function () use ($code) {
            echo $code;
        };
        Plugins::addHook($prefix.'footer_scripts_loaded', $print_code, 10);
        if ($dependencies !== null && is_array($dependencies)) {
            foreach ($dependencies as $script) {
                self::newInstance()->enqueueScript($script);
            }
        }
    }

    /**
     * Enqueue script to be loaded
     *
     * @param $id
     */
    public function enqueueScript($id)
    {
        $this->queue[$id] = $id;
    }

    /**
     * @return \Scripts
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Initialize Scripts
     */
    public static function init()
    {
        if (defined('OC_ADMIN') && OC_ADMIN) {
            self::initPrintScripts(true);
        } else {
            self::initPrintScripts();
        }
    }

    /**
     *  Print the HTML tags to load the scripts
     */
    public function printScripts()
    {
        // Emit scripts with `defer` (parallel download, non-blocking, executed in order
        // after parsing) — the modern default. It is enabled by default in the admin,
        // which is fully vanilla and whose inline scripts wait for DOMContentLoaded; it is
        // left off on the public front by default, because a legacy theme's inline
        // `$(document).ready(...)` cannot run before a deferred jQuery. Any theme or plugin
        // can force it either way with the `scripts_defer` filter, e.g. a modern front
        // theme opting in:  osc_add_filter('scripts_defer', static function () { return true; });
        $defer = Plugins::applyFilter('scripts_defer', defined('OC_ADMIN') && OC_ADMIN) ? ' defer' : '';
        foreach ($this->getScripts() as $script) {
            if ($script && !in_array($script, $this->scriptsLoaded, false)) {
                echo '<script src="' . Plugins::applyFilter('theme_url', $script) . '"' . $defer . '></script>' . PHP_EOL;
                $this->scriptsLoaded[] = $script;
            }
        }
    }

    /**
     *  Get the scripts urls
     */
    public function getScripts()
    {
        $scripts = array();
        $this->order();
        foreach ($this->resolved as $id) {
            if (isset($this->registered[$id]['url'])) {
                $scripts[] = $this->registered[$id]['url'];
            }
        }

        return $scripts;
    }

    /**
     * Init Scripts enqueue hooks
     *
     * @param false $admin
     */
    private static function initPrintScripts(bool $admin = false)
    {
        $prefix = '';
        if ($admin === true) {
            $prefix = 'admin_';
        }
        $printScript = function () {
            Scripts::newInstance()->printScripts();
        };
        if (!Preference::newInstance()->get($prefix.'enqueue_scripts_in_footer')) {
            Plugins::addHook($prefix.'header', $printScript, 8);
            Deprecate::deprecatedRunHook($prefix.'header_scripts_loaded', '5.1.0', $prefix.'scripts_loaded');
        }
        Plugins::addHook($prefix.'footer', $printScript, 8);
        $scriptsLoaded = static function () use ($prefix) {
            Plugins::runHook($prefix.'scripts_loaded');
            Deprecate::deprecatedRunHook($prefix.'footer_scripts_loaded', '5.1.0', $prefix.'scripts_loaded');
        };
        Plugins::addHook($prefix.'footer', $scriptsLoaded, 10);
    }

    /**
     * Add script to be loaded
     *
     * @param $id
     * @param $url
     * @param $dependencies mixed, it could be an array or a string
     */
    public function registerScript($id, $url, $dependencies = null)
    {
        $this->register($id, $url, $dependencies);
    }

    /**
     * Remove script to not be loaded
     *
     * @param $id
     */
    public function unregisterScript($id)
    {
        $this->unregister($id);
    }

    /**
     * Enqueu script to be loaded
     *
     * @param $id
     *
     * @deprecated since 4.0.0
     */
    public function enqueuScript($id)
    {
        Deprecate::deprecatedFunction(__METHOD__, '4.0.0', 'Scripts::enqueueScript()');
        $this->enqueueScript($id);
    }

    /**
     * Remove script to not be loaded
     *
     * @param $id
     */
    public function removeScript($id)
    {
        unset($this->queue[$id]);
    }
}
