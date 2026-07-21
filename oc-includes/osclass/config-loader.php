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

// config.php location and opt-out, both driven by the environment:
//   OSC_IGNORE_CONFIG_FILE=1   ignore any config.php and use the environment
//                              only (handy in containers, where a config.php
//                              from a bind mount would otherwise be read).
//   OSC_CONFIG_FILE=/path      load configuration from an alternate path
//                              (e.g. outside the web root).
$oscIgnoreFile = filter_var((string)getenv('OSC_IGNORE_CONFIG_FILE'), FILTER_VALIDATE_BOOLEAN);
$oscConfigFile = getenv('OSC_CONFIG_FILE');
if ($oscConfigFile === false || $oscConfigFile === '') {
    $oscConfigFile = ABS_PATH . 'config.php';
}

$oscHasConfigFile = false;
if (!$oscIgnoreFile && is_file($oscConfigFile)) {
    require_once $oscConfigFile;
    $oscHasConfigFile = true;
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

// Last-resort fallback for env-only deploys (no config.php): when the site URLs
// were supplied by neither config.php nor the environment, derive them from the
// current HTTP request so the app can still boot instead of fataling on an
// undefined WEB_PATH. Setting WEB_PATH explicitly is still recommended for
// production — the host used here comes from the request Host header, which a
// client can spoof.
if (!$oscHasConfigFile && defined('DB_NAME')
    && (!defined('WEB_PATH') || !defined('REL_WEB_URL'))
    && PHP_SAPI !== 'cli'
) {
    $oscHost = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    // Only trust a syntactically valid host[:port]; a missing or malformed Host
    // header (CLI, some proxies) yields no definition rather than a broken URL.
    if ($oscHost !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $oscHost)) {
        $oscScheme = ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1))
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
            ? 'https' : 'http';

        // Base URL path to the app root: strip the running script's path
        // (relative to ABS_PATH) off the end of its request URL. Handles
        // subdirectory installs and any entry point (root index.php, oc-admin/…).
        $oscBasePath   = '/';
        $oscScriptName = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
        $oscScriptFile = isset($_SERVER['SCRIPT_FILENAME'])
            ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_FILENAME']) : '';
        $oscAppRoot    = rtrim(str_replace('\\', '/', ABS_PATH), '/');
        if ($oscScriptName !== '' && $oscScriptFile !== '' && $oscAppRoot !== ''
            && strpos($oscScriptFile, $oscAppRoot) === 0
        ) {
            $oscRelScript = ltrim(substr($oscScriptFile, strlen($oscAppRoot)), '/');
            if ($oscRelScript !== '' && str_ends_with($oscScriptName, $oscRelScript)) {
                $oscBasePath = substr($oscScriptName, 0, -strlen($oscRelScript));
            } else {
                $oscBasePath = rtrim(dirname($oscScriptName), '/\\') . '/';
            }
            unset($oscRelScript);
        } elseif ($oscScriptName !== '') {
            $oscBasePath = rtrim(dirname($oscScriptName), '/\\') . '/';
        }
        $oscBasePath = '/' . ltrim($oscBasePath, '/');
        if (substr($oscBasePath, -1) !== '/') {
            $oscBasePath .= '/';
        }

        defined('REL_WEB_URL') or define('REL_WEB_URL', $oscBasePath);
        defined('WEB_PATH')    or define('WEB_PATH', $oscScheme . '://' . $oscHost . $oscBasePath);

        unset($oscScheme, $oscBasePath, $oscScriptName, $oscScriptFile, $oscAppRoot);
    }
    unset($oscHost);
}

// True when the database configuration came from the environment (no config.php
// file). The installer reads this to know it must NOT write a config.php.
defined('OSC_CONFIG_FROM_ENV') or define('OSC_CONFIG_FROM_ENV', !$oscHasConfigFile && defined('DB_NAME'));

unset($oscHasConfigFile, $oscEnv, $oscDbName, $oscDbPort, $oscIgnoreFile, $oscConfigFile);

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
