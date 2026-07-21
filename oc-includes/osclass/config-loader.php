<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Resolve Shopclass configuration.
 *
 * config.php in the web root is OPTIONAL. When it exists it is loaded and its
 * values win. When it is absent, the database configuration is read from
 * environment variables instead, so Shopclass can run entirely from the
 * environment (containers, platform config, 12-factor deploys). Because
 * config.php itself may use `getenv('X') ?: 'default'`, an environment variable
 * can also override a value baked into config.php.
 *
 * Crucially, when NOTHING is configured (no config.php and no DB_NAME in the
 * environment) this defines no database constants at all — leaving the
 * installer free to define them from its form. It never invents placeholder
 * defaults.
 *
 * Recognised variables: DB_HOST (accepts "host:port"), DB_PORT, DB_NAME,
 * DB_USER, DB_PASSWORD, DB_TABLE_PREFIX, and optionally REL_WEB_URL / WEB_PATH.
 *
 * Safe to include more than once.
 */

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$oscHasConfigFile = file_exists(ABS_PATH . 'config.php');
if ($oscHasConfigFile) {
    require_once ABS_PATH . 'config.php';
}

/**
 * Read an environment variable, treating unset and empty-string as "not set".
 */
$oscEnv = static function ($name) {
    $value = getenv($name);

    return ($value === false || $value === '') ? null : $value;
};

// The database name is the marker of a real configuration: it comes from
// config.php if that defined it, otherwise from the environment.
$oscDbName = defined('DB_NAME') ? DB_NAME : $oscEnv('DB_NAME');

if ($oscDbName !== null && $oscDbName !== '') {
    // A real configuration exists — make sure every DB constant is defined,
    // filling any that config.php left unset from the environment.
    defined('DB_NAME')         or define('DB_NAME', $oscDbName);
    defined('DB_HOST')         or define('DB_HOST', $oscEnv('DB_HOST') ?? 'localhost');
    defined('DB_USER')         or define('DB_USER', $oscEnv('DB_USER') ?? '');
    defined('DB_PASSWORD')     or define('DB_PASSWORD', $oscEnv('DB_PASSWORD') ?? '');
    defined('DB_TABLE_PREFIX') or define('DB_TABLE_PREFIX', $oscEnv('DB_TABLE_PREFIX') ?? 'oc_');

    $oscDbPort = $oscEnv('DB_PORT');
    if (!defined('DB_PORT') && $oscDbPort !== null) {
        define('DB_PORT', $oscDbPort);
    }
}

// Site URLs may also be supplied by the environment (independent of the DB).
if (!defined('REL_WEB_URL') && $oscEnv('REL_WEB_URL') !== null) {
    define('REL_WEB_URL', $oscEnv('REL_WEB_URL'));
}
if (!defined('WEB_PATH') && $oscEnv('WEB_PATH') !== null) {
    define('WEB_PATH', $oscEnv('WEB_PATH'));
}

// True when the database configuration came from the environment (no config.php
// file). The installer reads this to know it must NOT write a config.php.
defined('OSC_CONFIG_FROM_ENV') or define('OSC_CONFIG_FROM_ENV', !$oscHasConfigFile && defined('DB_NAME'));

unset($oscHasConfigFile, $oscEnv, $oscDbName, $oscDbPort);

if (!function_exists('osc_is_configured')) {
    /**
     * Whether Shopclass has a database to connect to — from config.php or from
     * the environment. False means the app has not been set up yet.
     *
     * @return bool
     */
    function osc_is_configured(): bool
    {
        return defined('DB_NAME') && DB_NAME !== '';
    }
}

/* file end: ./oc-includes/osclass/config-loader.php */
