<?php
/**
 * The base configuration for Shopclass
 *
 * The config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Database table prefix
 * * Optional settings
 *
 * @package Shopclass
 */

/** MySQL database name for Shopclass */
define('DB_NAME', getenv('DB_NAME') ?: 'database_name');

/** MySQL database username */
define('DB_USER', getenv('DB_USER') ?: 'username');

/** MySQL database password */
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'password');

/** MySQL hostname (an environment variable, when set, overrides the value here) */
define('DB_HOST', getenv('DB_HOST') ?: 'db_host'); // i.e localhost,

/**
 * Optional MySQL port. Only needed when your database runs on a non-default
 * port; you can also append it to DB_HOST above as 'host:port'.
 */
// define('DB_PORT', 3306);

/** Database Table prefix */
define('DB_TABLE_PREFIX', getenv('DB_TABLE_PREFIX') ?: 'oc_');

/**
 * Keep the server's own strict SQL modes instead of relaxing them.
 *
 * With this on, a value the column cannot hold is rejected rather than silently
 * cut short or clamped. New installs get it; an install upgraded from an older
 * release does not, because a plugin that has been writing over-long values for
 * years would begin to fail. Remove the line to go back to the relaxed modes.
 */
define('OSC_DB_STRICT_MODE', true);

/** Website relative root path */
define('REL_WEB_URL', 'rel_here');

/** Website base url */
defined('WEB_PATH') or define('WEB_PATH', 'web_path_here'); // i.e http://localhost/

/** Turn off restoring a backup from the admin; backups still work. */
//define('OSC_DISABLE_WEB_RESTORE', true);

// Below are optional settings and should only be enabled for debugging purposes

/** Enable osclass debug */
//define('OSC_DEBUG', false); //default is false

/** Enable osclass debugging to oc-content/debug.log */
//define('OSC_DEBUG_LOG', false); //default is false

/** Enable osclass database debug */
//define('OSC_DEBUG_DB', false); //default is false

/** Enable osclass db query logging */
//define('OSC_DEBUG_DB_LOG', false); //default is false

/** Enable osclass db query explain logging */
//define('OSC_DEBUG_DB_EXPLAIN', false); //default is false

/**
 * Object cache driver. Default is 'default' (a per-request in-memory array that
 * does NOT persist between requests). For a real shared cache, install the
 * matching PHP extension and set one of:
 *
 *   'memcached' - modern memcached extension (recommended)
 *   'apcu'      - APCu user cache (single server)
 *   'redis'     - Redis or Valkey; uses the phpredis extension when installed, and a
 *                 built-in client otherwise, so it needs no extension
 *   'memcache'  - the old driver is gone; this value now uses 'memcached'
 */
//define('OSC_CACHE', 'memcached');

/** Cache entry lifetime in seconds. Default is 60. */
//define('OSC_CACHE_TTL', 60);

/**
 * Optional memcached server list. Omit to use 127.0.0.1:11211.
 * Each entry needs default_host, default_port and default_weight.
 */
//$_cache_config = array(
//    array('default_host' => '127.0.0.1', 'default_port' => 11211, 'default_weight' => 1),
//);

/**
 * Optional Redis/Valkey server. Omit to use 127.0.0.1:6379. A host starting with "/" is a
 * Unix socket; "tls://host" connects over TLS. Only the first entry is used.
 */
//$_cache_config = array(
//    array('default_host' => '127.0.0.1', 'default_port' => 6379, 'password' => '', 'username' => '', 'database' => 0),
//);
