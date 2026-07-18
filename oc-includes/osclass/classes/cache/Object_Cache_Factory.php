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

/**
 * Class Object_Cache_Factory
 */
class Object_Cache_Factory
{

    private static $instance;

    /**
     * @return \Object_Cache_default
     */
    public static function newInstance()
    {
        if (self::$instance === null) {
            self::$instance = self::getCache();
        }

        return self::$instance;
    }

    /**
     * @return null|\Object_Cache_default
     */
    public static function getCache()
    {
        if (self::$instance === null) {
            $cache = 'default';
            if (defined('OSC_CACHE')) {
                $cache = OSC_CACHE;
            }

            $cache_class = 'Object_Cache_' . $cache;

            if (class_exists($cache_class, true)) {
                // all correct ?
                if (call_user_func(array($cache_class, 'is_supported'))) {
                    self::$instance = new $cache_class();
                } else {
                    self::$instance = new Object_Cache_default();
                    trigger_error('Cache ' . $cache . ' NOT SUPPORTED - loaded Object_Cache_default cache',
                        E_USER_NOTICE);
                }

                return self::$instance;
            }

            throw new RuntimeException('Unknown cache');
        }

        return self::$instance;
    }
}
