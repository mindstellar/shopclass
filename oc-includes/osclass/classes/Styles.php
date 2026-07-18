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
 * Styles enqueue class.
 *
 * @since 3.1.1
 */
class Styles extends Dependencies
{

    private static $instance;
    public $styles = [];

    /**
     * Initialize Scripts class
     */
    public static function init()
    {
        $print_styles = static function () {
            self::newInstance()->printStyles();
        };

        if (defined('OC_ADMIN') && OC_ADMIN) {
            Plugins::addHook('admin_header', $print_styles, 9);
        } else {
            Plugins::addHook('header', $print_styles, 9);
        }
    }

    /**
     * Print the HTML tags to load the styles
     */
    public function printStyles()
    {
        // Keeping compatibility with old methods
        $styles = $this->getStyles();
        foreach ($styles as $css) {
            echo $this->cssLinkTag($css);
        }
    }

    /**
     * Get the css styles urls
     */
    public function getStyles()
    : array
    {
        $styles = array();
        $this->order();
        foreach ($this->queue as $id) {
            if (isset($this->registered[$id]['url'])) {
                $styles[] = $this->registered[$id]['url'];
            }
        }

        return $styles;
    }

    /**
     * Return css tag with given css url
     *
     * @param string $css
     *
     * @return string
     */
    private function cssLinkTag(string $css)
    : string
    {
        return '<link href="' . Plugins::applyFilter('style_url', $css) . '" rel="stylesheet" type="text/css" />'
               . PHP_EOL;
    }

    /**
     * @return \Styles
     */
    public static function newInstance()
    : Styles
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Add style to be loaded
     *
     * @param $id
     * @param $url
     */
    public function addStyle($id, $url)
    {
        $this->register($id, $url, null);
        $this->enqueue($id);

    }

    /**
     * Remove style to not be loaded
     *
     * @param $id
     */
    public function removeStyle($id)
    {
        unset($this->styles[$id]);
    }

    /**
     * Enqueue Style to be loaded
     *
     * @param $id
     */
    public function enqueue($id)
    {
        $this->queue[$id] = $id;
    }

    /**
     * Remove Style to not be loaded
     *
     * @param $id
     */
    public function removeFromQueue($id)
    {
        unset($this->queue[$id]);
    }
}
