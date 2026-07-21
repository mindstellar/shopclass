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
define('DB_NAME', 'database_name');

/** MySQL database username */
define('DB_USER', 'username');

/** MySQL database password */
define('DB_PASSWORD', 'password');

/** MySQL hostname */
define('DB_HOST', 'db_host'); // i.e localhost,

/**
 * Optional MySQL port. Only needed when your database runs on a non-default
 * port; you can also append it to DB_HOST above as 'host:port'.
 */
// define('DB_PORT', 3306);

/** Database Table prefix */
define('DB_TABLE_PREFIX', 'oc_');

/** Website relative root path */
define('REL_WEB_URL', 'rel_here');

/** Website base url */
defined('WEB_PATH') or define('WEB_PATH', 'web_path_here'); // i.e http://localhost/

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
 *   'memcache'  - DEPRECATED legacy memcache extension; use 'memcached' instead
 */
//define('OSC_CACHE', 'memcached');

/** Cache entry lifetime in seconds. Default is 60. */
//define('OSC_CACHE_TTL', 60);

/**
 * Optional memcached/memcache server list. Omit to use 127.0.0.1:11211.
 * Each entry needs default_host, default_port and default_weight.
 */
//$_cache_config = array(
//    array('default_host' => '127.0.0.1', 'default_port' => 11211, 'default_weight' => 1),
//);




