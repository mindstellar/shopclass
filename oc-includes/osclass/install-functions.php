<?php
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

use mindstellar\admin\SystemChecks;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Strip every tag from a raw server value, unless the caller opted out.
 *
 * @param string $value
 * @param bool   $xss_check False returns the value untouched
 *
 * @return string
 */
function _purify($value, $xss_check)
{
    if (!$xss_check) {
        return $value;
    }

    // The same strip-every-tag purifier the request layer uses, so the installer cannot
    // sanitise to a different standard than the site it is installing.
    return Params::stripTags($value);
}

/**
 * Read one $_SERVER value, stripped and optionally HTML-encoded.
 *
 * @param string $param         Key to read; an empty key returns ''
 * @param bool   $htmlencode
 * @param bool   $xss_check
 * @param bool   $quotes_encode Encode quotes too when $htmlencode is on
 *
 * @return string Empty string when the key is missing
 */
function getServerParam($param, $htmlencode = false, $xss_check = true, $quotes_encode = true)
{
    if ($param == "") {
        return '';
    }
    if (!isset($_SERVER[$param])) {
        return '';
    }
    $value = _purify($_SERVER[$param], $xss_check);
    if ($htmlencode) {
        if ($quotes_encode) {
            return htmlspecialchars($value, ENT_QUOTES);
        }

        return htmlspecialchars($value, ENT_NOQUOTES);
    }

    return ($value);
}

/**
 * The url of the site
 *
 * @return string The url of the site
 * @since 1.2
 *
 */

function get_absolute_url()
{
    $protocol =
        (getServerParam('HTTPS') === 'on' || getServerParam('HTTP_X_FORWARDED_PROTO') === 'https')
        ? 'https' : 'http';
    $pos      = strpos(getServerParam('REQUEST_URI'), 'oc-includes');
    $URI      = rtrim(substr(getServerParam('REQUEST_URI'), 0, $pos), '/') . '/';

    return $protocol . '://' . osc_request_host() . $URI;
}

/**
 * The relative url on the domain url
 *
 * @return string The relative url on the domain url
 * @since 1.2
 *
 */
function get_relative_url()
{
    $url = Params::getServerParam('REQUEST_URI', false, false);

    return substr($url, 0, strpos($url, '/oc-includes')) . "/";
}

/**
 * Get the requirements to install Shopclass
 *
 * @return array Requirements
 * @since 1.2
 *
 */
function get_requirements()
{
    $array = array(
        'PHP version' => array(
            'requirement' => sprintf(__('PHP version >= %s'), SystemChecks::PHP_FLOOR),
            'fn'          => install_php_version_supported(PHP_VERSION),
            'solution'    => sprintf(__('At least PHP %s (PHP %s or higher recommended) is required to run Shopclass. '
                . 'You may talk with your hosting to upgrade your PHP version.'), SystemChecks::PHP_FLOOR, '8.4')
        ),

        'MySQLi extension for PHP' => array(
            'requirement' => __('MySQLi extension for PHP'),
            'fn'          => extension_loaded('mysqli'),
            'solution'    => __('MySQLi extension is required. How to '
                . '<a target="_blank" href="http://www.php.net/manual/en/mysqli.setup.php">install/configure</a>.')
        ),

        'GD extension for PHP'   => array(
            'requirement' => __('GD extension for PHP'),
            'fn'          => extension_loaded('gd'),
            'solution'    => __('GD extension is required. How to '
                . '<a target="_blank" href="http://www.php.net/manual/en/image.setup.php">install/configure</a>.')
        ),
        'cURL extension for PHP' => array(
            'requirement' => __('cURL extension for PHP'),
            'fn'          => extension_loaded('curl'),
            'solution'    => __('cURL extension is required. How to '
                . '<a target="_blank" href="https://www.php.net/manual/en/curl.setup.php">install/configure</a>.')
        ),
        'Folder <code>oc-content/uploads</code> exists' => array(
            'requirement' => __('Folder <code>oc-content/uploads</code> exists'),
            'fn'          => file_exists(ABS_PATH . 'oc-content/uploads/'),
            'solution'    => sprintf(
                __('You have to create <code>uploads</code> folder, i.e.: <code>mkdir %soc-content/uploads/</code>'),
                ABS_PATH
            )
        ),

        'Folder <code>oc-content/uploads</code> is writable' => array(
            'requirement' => __('<code>oc-content/uploads</code> folder is writable'),
            'fn'          => is_writable(ABS_PATH . 'oc-content/uploads/'),
            'solution'    => sprintf(
                __('<code>uploads</code> folder has to be writable, i.e.: '
                    . '<code>chmod 0755 %soc-content/uploads/</code>'),
                ABS_PATH
            )
        ),
        // oc-content/downlods
        'Folder <code>oc-content/downloads</code> exists'    => array(
            'requirement' => __('Folder <code>oc-content/downloads</code> exists'),
            'fn'          => file_exists(ABS_PATH . 'oc-content/downloads/'),
            'solution'    => sprintf(
                __('You have to create <code>downloads</code> folder, i.e.: '
                    . '<code>mkdir %soc-content/downloads/</code>'),
                ABS_PATH
            )
        ),

        'Folder <code>oc-content/downloads</code> is writable' => array(
            'requirement' => __('<code>oc-content/downloads</code> folder is writable'),
            'fn'          => is_writable(ABS_PATH . 'oc-content/downloads/'),
            'solution'    => sprintf(
                __('<code>downloads</code> folder has to be writable, i.e.: '
                    . '<code>chmod 0755 %soc-content/downloads/</code>'),
                ABS_PATH
            )
        ),
        // oc-content/languages
        'Folder <code>oc-content/languages</code> exists'      => array(
            'requirement' => __('Folder <code>oc-content/languages</code> folder exists'),
            'fn'          => file_exists(ABS_PATH . 'oc-content/languages/'),
            'solution'    => sprintf(
                __('You have to create the <code>languages</code> folder, i.e.: '
                    . '<code>mkdir %soc-content/languages/</code>'),
                ABS_PATH
            )
        ),

        'Folder <code>oc-content/languages</code> is writable' => array(
            'requirement' => __('<code>oc-content/languages</code> folder is writable'),
            'fn'          => is_writable(ABS_PATH . 'oc-content/languages/'),
            'solution'    => sprintf(
                __('<code>languages</code> folder has to be writable, i.e.: '
                    . '<code>chmod 0755 %soc-content/languages/</code>'),
                ABS_PATH
            )
        ),
    );

    // Only when a database is already configured (config.php or environment); otherwise
    // there is nothing to connect to until step 2.
    $serverInfo = install_configured_server_info();
    if ($serverInfo !== '') {
        $array['Database server version'] = array(
            'requirement' => sprintf(__('MySQL 5.7.5+ or MariaDB 10.2+ (found %s)'), osc_esc_html($serverInfo)),
            'fn'          => install_db_version_supported($serverInfo),
            'solution'    => __('Shopclass needs MySQL 5.7.5 or newer, or MariaDB 10.2 or newer. '
                . 'Ask your hosting to upgrade the database server.')
        );
    }

    $config_writable = false;
    $root_writable   = false;
    $config_sample   = false;
    if (file_exists(ABS_PATH . 'config.php')) {
        if (is_writable(ABS_PATH . 'config.php')) {
            $config_writable = true;
        }
        $array['File <code>config.php</code> is writable'] = array(
            'requirement' => __('<code>config.php</code> file is writable'),
            'fn'          => $config_writable,
            'solution'    => sprintf(
                __('<code>config.php</code> file has to be writable, i.e.: <code>chmod 0755 %sconfig.php</code>'),
                ABS_PATH
            )
        );
    } else {
        if (is_writable(ABS_PATH)) {
            $root_writable = true;
        }
        $array['Root directory is writable'] = array(
            'requirement' => __('Root directory is writable'),
            'fn'          => $root_writable,
            'solution'    => sprintf(
                __('Root folder has to be writable, i.e.: <code>chmod 0755 %s</code>'),
                ABS_PATH
            )
        );

        if (file_exists(ABS_PATH . 'config-sample.php')) {
            $config_sample = true;
        }
        $array['File <code>config-sample.php</code> exists'] = array(
            'requirement' => __('<code>config-sample.php</code> file exists'),
            'fn'          => $config_sample,
            'solution'    => __('<code>config-sample.php</code> file is required, you should re-download Shopclass.')
        );
    }

    return $array;
}

/**
 * The server version string of the configured database, or '' when none is configured or
 * it cannot be reached.
 *
 * @return string
 * @since 6.4.0
 */
function install_configured_server_info(): string
{
    require_once LIB_PATH . 'osclass/config-loader.php';
    if (!osc_is_configured()) {
        return '';
    }

    try {
        $probe = new \mindstellar\database\ConnectionManager(DB_HOST, DB_USER, DB_PASSWORD, '');
        if ($probe->getErrorConnectionLevel() > 0 || !$probe->getHandle() instanceof mysqli) {
            return '';
        }

        return (new \mindstellar\database\Connection($probe->getHandle()))->serverInfo();
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Whether a database server version string meets the floor: MySQL 5.7.5+ or MariaDB 10.2+.
 * 5.7.5 is where GET_LOCK() became reentrant per-connection, which the migration runner relies on.
 *
 * @param string $serverInfo as reported by the server, e.g. "8.0.36" or "5.5.5-10.11.6-MariaDB"
 *
 * @return bool
 * @since 6.4.0
 */
function install_db_version_supported(string $serverInfo): bool
{
    // Older MariaDB clients see a "5.5.5-" prefix in front of the real version.
    $info = preg_replace('/^5\.5\.5-/', '', trim($serverInfo));
    if (!preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $info, $m)) {
        return false;
    }

    $floor = stripos($info, 'mariadb') !== false ? '10.2.0' : '5.7.5';

    return version_compare($m[1], $floor, '>=');
}

/**
 * Whether a PHP version meets the floor (SystemChecks::PHP_FLOOR).
 *
 * @param string $version e.g. PHP_VERSION
 *
 * @return bool
 * @since 7.1.0
 */
function install_php_version_supported(string $version): bool
{
    return version_compare($version, SystemChecks::PHP_FLOOR, '>=');
}

/**
 * Whether every requirement to install Shopclass is met.
 *
 * @param array<string,array{requirement:string,fn:bool,solution:string}> $array
 *
 * @return bool True when nothing is missing
 * @since 6.3.0
 */
function requirements_met($array)
{
    foreach ($array as $v) {
        if (!$v['fn']) {
            return false;
        }
    }

    return true;
}

/**
 * Check if some of the requirements to install Shopclass are correct or not
 *
 * @param array<string,array{requirement:string,fn:bool,solution:string}> $array
 *
 * @return bool True when at least one requirement is NOT met
 * @deprecated since 6.3.0 use requirements_met() instead, which answers the way its name reads
 * @see requirements_met()
 * @since 1.2
 */
function check_requirements($array)
{
    return !requirements_met($array);
}

/**
 * Turn a MySQL/mysqli error code into a plain-language message a non-technical
 * site owner can act on. Single source of truth shared by the install routine
 * and the "Test connection" endpoint so both speak the same words.
 *
 * The message answers, in order: what happened, whose side it is on, what to do
 * next — never a bare "Error number: N". Any host/database name folded in comes
 * from the installer form and MUST be escaped at render time (osc_esc_html for
 * HTML, json_encode for the JSON endpoint); it is left raw here so the caller
 * controls the context.
 *
 * @param int   $code Connection or query error number
 * @param array $ctx  Optional context: 'dbhost', 'dbname'
 *
 * @return array{error: string, field: ?string} Message plus the form field to
 *         flag, when the error points at one.
 */
function install_db_error_message($code, array $ctx = array())
{
    $host   = isset($ctx['dbhost']) ? $ctx['dbhost'] : '';
    $dbname = isset($ctx['dbname']) ? $ctx['dbname'] : '';

    switch ((int)$code) {
        case 2002:
        case 2003:
        case 2005:
            return array(
                'error' => sprintf(
                    __("We couldn't reach a database server at %s. If your site and database are on the "
                        . "same hosting, 'localhost' is usually the right host."),
                    $host
                ),
                'field' => 'dbhost',
            );
        case 1045:
            return array(
                'error' => __('The database server rejected that username and password. Re-copy them from '
                    . 'your hosting panel — passwords are easy to mistype.'),
                'field' => 'password',
            );
        case 1044:
            return array(
                'error' => sprintf(
                    __("That user connected, but the database %s does not exist or the user isn't allowed "
                        . 'to use it. In your hosting panel, create the database and give the user access to it.'),
                    $dbname
                ),
                'field' => 'dbname',
            );
        case 1049:
            return array(
                'error' => sprintf(
                    __("There's no database called %s yet. Open More options below and let Shopclass create "
                        . 'it for you, or create it in your hosting panel first.'),
                    $dbname
                ),
                'field' => 'dbname',
            );
        case 1006:
            return array(
                'error' => __("This user isn't allowed to create databases. Create the database in your "
                    . 'hosting panel first, then leave "Create the database" unchecked.'),
                'field' => 'createdb',
            );
        case 1050:
            return array(
                'error' => __('This database already has Shopclass tables in it. Choose a different table '
                    . 'prefix under More options, or point Shopclass at an empty database.'),
                'field' => 'tableprefix',
            );
        case 1142:
        case 1471:
            return array(
                'error' => __("The user connected, but isn't allowed to write to this database. Grant it "
                    . 'full privileges on the database and try again.'),
                'field' => 'username',
            );
        default:
            return array(
                'error' => sprintf(
                    __('The database returned an unexpected error (code %s). Your hosting support will '
                        . 'recognise this number.'),
                    (int)$code
                ),
                'field' => null,
            );
    }
}

/**
 * Whether the database already has Shopclass tables with this prefix. The prefix is escaped so
 * it cannot act as a LIKE wildcard.
 *
 * @param mysqli $db
 * @param string $prefix
 *
 * @return bool
 */
function install_prefix_in_use(mysqli $db, string $prefix): bool
{
    $count = (new \mindstellar\database\Connection($db))->scalar(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE BINARY ? ESCAPE \'!\'',
        array(\mindstellar\database\TablePrefix::like($prefix))
    );

    return (int) $count > 0;
}

/**
 * Whether the Shopclass tables under this prefix are an install that never finished: the
 * sentinel row the last step writes is missing.
 *
 * @param mysqli $db
 * @param string $prefix a validated table prefix
 *
 * @return bool
 */
function install_is_unfinished(mysqli $db, string $prefix): bool
{
    try {
        $res = $db->query('SELECT COUNT(*) FROM `' . $prefix . "t_preference` WHERE s_name = 'osclass_installed'");
    } catch (mysqli_sql_exception $e) {
        // Only a missing table means unfinished; a damaged or locked one may be a real site.
        return (int) $e->getCode() === 1146;
    }

    return $res instanceof mysqli_result && (int) $res->fetch_row()[0] === 0;
}

/**
 * Drop the tables of an unfinished install: only the tables the installer creates.
 *
 * @param mysqli $db
 * @param string $prefix a validated table prefix
 *
 * @return void
 */
function install_drop_unfinished(mysqli $db, string $prefix): void
{
    preg_match_all('/CREATE TABLE \/\*TABLE_PREFIX\*\/(\w+)/', (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql'), $m);
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_merge($m[1], array('t_migration')) as $table) {
        $db->query('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * @return array{error: string, field: string}
 */
function install_unfinished_message(): array
{
    return array(
        'error' => __('This database has a Shopclass install that did not finish. Turn on "Remove the '
            . 'unfinished install" under More options to start again.'),
        'field' => 'reset_unfinished',
    );
}

/**
 * Try the database settings entered on step 2 without committing to them, so the
 * owner can confirm the connection works before running the real install. This
 * is the installer's biggest fear-reducer.
 *
 * Uses only ad-hoc connections — it must NEVER establish the shared singleton,
 * or a wrong password typed here would poison the real install that follows.
 *
 * @return array{ok: bool, level: string, message: string, field: ?string}
 *         level is 'success' | 'warning' | 'error'; field names the form field
 *         to flag, when the result points at one.
 */
function install_test_db_connection()
{
    $dbhost      = Params::getParam('dbhost');
    $dbname      = Params::getParam('dbname');
    $username    = Params::getParam('username');
    $password    = Params::getParam('password', false, false);
    $tableprefix = Params::getParam('tableprefix');
    $createdb    = Params::getParam('createdb') != '';
    $ctx         = array('dbhost' => $dbhost, 'dbname' => $dbname);

    if ($tableprefix === '') {
        $tableprefix = 'oc_';
    }

    if ($dbhost === '' || $dbname === '' || $username === '') {
        return array(
            'ok'      => false,
            'level'   => 'error',
            'message' => __('Fill in the host, database name and username first.'),
            'field'   => $dbhost === '' ? 'dbhost' : ($dbname === '' ? 'dbname' : 'username'),
        );
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableprefix)) {
        return array(
            'ok'      => false,
            'level'   => 'error',
            'message' => __('The table prefix can only contain letters, numbers and underscores.'),
            'field'   => 'tableprefix',
        );
    }

    // When asked to create the database, the meaningful test is whether the
    // admin credentials can reach the server at all.
    if ($createdb) {
        $adminUser = Params::getParam('admin_username');
        $adminPwd  = Params::getParam('admin_password', false, false);
        $probe     = new \mindstellar\database\ConnectionManager($dbhost, $adminUser, $adminPwd, '');
        $code      = $probe->getErrorConnectionLevel();
        if ($code > 0) {
            $msg = install_db_error_message($code, $ctx);

            return array('ok' => false, 'level' => 'error', 'message' => $msg['error'], 'field' => $msg['field']);
        }

        return array(
            'ok'      => true,
            'level'   => 'success',
            'message' => sprintf(
                __('Connected to the server. The database %s will be created during install.'),
                $dbname
            ),
            'field'   => null,
        );
    }

    // Otherwise connect as the real user, to the target database.
    $probe = new \mindstellar\database\ConnectionManager($dbhost, $username, $password, $dbname);
    $code  = $probe->getErrorConnectionLevel();
    if ($code === 0) {
        $code = $probe->getErrorLevel();
    }
    if ($code > 0) {
        $msg = install_db_error_message($code, $ctx);

        return array('ok' => false, 'level' => 'error', 'message' => $msg['error'], 'field' => $msg['field']);
    }

    // Connected. Warn early if this prefix already has Shopclass tables.
    $db = $probe->getHandle();
    if ($db instanceof mysqli) {
        $prefixInUse = install_prefix_in_use($db, $tableprefix);
        if ($prefixInUse && install_is_unfinished($db, $tableprefix)) {
            if (Params::getParam('reset_unfinished') == '') {
                $msg = install_unfinished_message();

                return array('ok' => false, 'level' => 'warning', 'message' => $msg['error'], 'field' => $msg['field']);
            }

            return array(
                'ok'      => true,
                'level'   => 'success',
                'message' => sprintf(__('Connected to %s. The unfinished install will be removed when you continue.'), $dbname),
                'field'   => null,
            );
        }
        if ($prefixInUse) {
            return array(
                'ok'      => false,
                'level'   => 'warning',
                'message' => __('Connected, but this database already has Shopclass tables with that '
                    . 'prefix. Choose a different table prefix under More options, or use an empty database.'),
                'field'   => 'tableprefix',
            );
        }
        // Connecting is not enough: the install creates tables, so try one.
        $table = '`' . $tableprefix . 'install_probe`';
        try {
            $db->query('CREATE TABLE ' . $table . ' (i INT)');
            $db->query('DROP TABLE ' . $table);
        } catch (mysqli_sql_exception $e) {
            $msg = install_db_error_message($e->getCode(), $ctx);

            return array('ok' => false, 'level' => 'error', 'message' => $msg['error'], 'field' => $msg['field']);
        }
    }

    return array(
        'ok'      => true,
        'level'   => 'success',
        'message' => sprintf(__('Connected to %s. Ready to install.'), $dbname),
        'field'   => null,
    );
}

/**
 * Per-session installer nonce. The installer runs before any preference exists
 * (so osc_csrf_check() cannot yet work), so state-changing installer requests
 * carry this token instead. Created on first render, stable for the session.
 *
 * @return string
 */
function install_nonce()
{
    $nonce = Session::getInstance()->_get('install_nonce');
    if (!$nonce) {
        $nonce = bin2hex(random_bytes(16));
        Session::getInstance()->_set('install_nonce', $nonce);
    }

    return $nonce;
}

/**
 * Verify the nonce submitted with a state-changing installer request against the
 * one stored in the session, in constant time.
 *
 * @return bool
 */
function install_nonce_check()
{
    $token   = (string)Params::getParam('install_nonce');
    $session = (string)Session::getInstance()->_get('install_nonce');

    return $token !== '' && $session !== '' && hash_equals($session, $token);
}

/**
 * insert/update preference allow_report_osclass
 *
 * @param int|string $value Boolean preference value, 1 or 0
 *
 * @return void
 */
function set_allow_report_osclass($value)
{
    $values = array(
        's_section' => 'osclass',
        's_name'    => 'allow_report_osclass',
        's_value'   => $value,
        'e_type'    => 'BOOLEAN'
    );

    Preference::getInstance()->insert($values);
}

/**
 * Install Shopclass database
 *
 * @return array{error:string,field?:string}|false False on success, an error payload otherwise
 * @since 1.2
 *
 */
function oc_install()
{
    $dbhost      = Params::getParam('dbhost');
    $dbname      = Params::getParam('dbname');
    $username    = Params::getParam('username');
    $password    = Params::getParam('password', false, false);
    $tableprefix = Params::getParam('tableprefix');
    $createdb    = false;
    require_once LIB_PATH . 'osclass/helpers/hSecurity.php';

    if (!$tableprefix) {
        $tableprefix = 'oc_';
    }

    // The table prefix becomes a SQL identifier: it is concatenated into the
    // table names every later query targets, and identifiers cannot be bound as
    // parameters. Whoever submits the installer form controls it, so validate it
    // strictly here — a stray backtick or comment must not be able to break out
    // of an identifier. Prefixes are code-facing and near-always [A-Za-z0-9_].
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableprefix)) {
        return array(
            'error' => __('The table prefix can only contain letters, numbers and underscores.'),
            'field' => 'tableprefix',
        );
    }

    // The site language row is built from the language's files, so a language that is not on
    // disk stops the install here, before any table is created.
    if (!isset(osc_listLocales()[osc_current_admin_locale()]['locale_code'])) {
        return array(
            'error' => sprintf(__('The language %s is not installed.'), osc_current_admin_locale()),
        );
    }

    // A site that already has database settings installs only into that database. Without
    // this, a visitor who reached the form on a live site could point config.php at theirs.
    if (osc_is_configured() && !(($dbhost === DB_HOST || (defined('DB_PORT') && $dbhost === DB_HOST . ':' . DB_PORT))
        && $dbname === DB_NAME && $username === DB_USER
        && $tableprefix === DB_TABLE_PREFIX && hash_equals((string)DB_PASSWORD, (string)$password))
    ) {
        return array(
            'error' => defined('OSC_CONFIG_FROM_ENV') && OSC_CONFIG_FROM_ENV
                ? __('Enter the database settings this site is configured with in its environment.')
                : __('This site already has database settings in config.php. Enter the same ones, or delete config.php to use a different database.'),
            'field' => 'dbname',
        );
    }

    if (Params::getParam('createdb') != '') {
        $createdb = true;
    }

    if ($createdb) {
        $adminuser = Params::getParam('admin_username');
        $adminpwd  = Params::getParam('admin_password', false, false);

        // Probe with an ad-hoc connection: it must never become the shared
        // singleton, so a wrong password here can't poison later queries.
        $adminInstance = new \mindstellar\database\ConnectionManager($dbhost, $adminuser, $adminpwd, '');
        $error_num   = $adminInstance->getErrorConnectionLevel();

        if ($error_num > 0) {
            return install_db_error_message($error_num, array('dbhost' => $dbhost, 'dbname' => $dbname));
        }

        // Bound to the admin handle rather than the shared one: the database this
        // statement creates does not exist yet, so there is nothing to connect to.
        $adminDb = new \mindstellar\database\Connection($adminInstance->getHandle());
        // Backtick-quote the database name (escaping any backtick) so a name with
        // a hyphen or other punctuation — common on shared hosting — is created
        // safely and can't break out of the identifier.
        $quotedDbName = '`' . str_replace('`', '``', $dbname) . '`';

        try {
            $adminDb->execute(sprintf(
                "CREATE DATABASE IF NOT EXISTS %s DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'",
                $quotedDbName
            ));
        } catch (\mindstellar\database\DbException $e) {
            // MySQL reports a refused CREATE DATABASE as 1044, access denied to that database.
            $code = (int) $e->getCode() === 1044 ? 1006 : $e->getCode();

            return install_db_error_message($code, array('dbhost' => $dbhost, 'dbname' => $dbname));
        }

        unset($dbInstance, $adminDb, $adminInstance);
    }

    // Ad-hoc probe of the real Shopclass credentials (still not the singleton).
    $dbInstance      = new \mindstellar\database\ConnectionManager($dbhost, $username, $password, $dbname);
    $error_num = $dbInstance->getErrorConnectionLevel();

    if ($error_num == 0) {
        $error_num = $dbInstance->getErrorLevel();
    }

    if ($error_num > 0) {
        return install_db_error_message($error_num, array('dbhost' => $dbhost, 'dbname' => $dbname));
    }
    // Stop before anything is created, so an existing site's database is left as it was.
    $handle = $dbInstance->getHandle();
    if ($handle instanceof mysqli && install_prefix_in_use($handle, $tableprefix)) {
        if (!install_is_unfinished($handle, $tableprefix)) {
            return install_db_error_message(1050, array('dbhost' => $dbhost, 'dbname' => $dbname));
        }
        if (Params::getParam('reset_unfinished') == '') {
            return install_unfinished_message();
        }
        install_drop_unfinished($handle, $tableprefix);
    }

    // When the configuration comes from the environment there is no config.php
    // to write or check — the database settings are managed externally.
    //
    // So an env-only install does NOT get OSC_DB_STRICT_MODE, which a config.php
    // install has written into it below: there is no file to write it to, and
    // defaulting it on for every OSC_CONFIG_FROM_ENV deploy would flip existing
    // containers to strict on their next image pull. config-loader.php cannot tell
    // the two apart — it runs before the database is reachable, and config.php is
    // the only marker of a fresh install there is. Set OSC_DB_STRICT_MODE=1 in the
    // environment to opt a container in.
    $writesConfig = !(defined('OSC_CONFIG_FROM_ENV') && OSC_CONFIG_FROM_ENV);

    if ($writesConfig) {
        if (file_exists(ABS_PATH . 'config.php')) {
            if (!is_writable(ABS_PATH . 'config.php')) {
                return array('error' => __("Can't write in config.php file. Check if the file is writable."));
            }
            create_config_file($dbname, $username, $password, $dbhost, $tableprefix);
        } else {
            if (!file_exists(ABS_PATH . 'config-sample.php')) {
                return array(
                    'error' => __("config-sample.php doesn't exist. Check if everything is "
                        . 'decompressed correctly.')
                );
            }
            if (!is_writable(ABS_PATH)) {
                return array('error' => __('Can\'t copy config-sample.php. Check if the root directory is writable.'));
            }
        }
    }

    define_install_constants($dbhost, $dbname, $username, $password, $tableprefix);

    // Establish the shared singleton connection now. Every write below — the DAO
    // models, the migration ledger and the parameterized osc_db_* API — resolves
    // this same handle, so they all run over one connection to the new schema.
    $conn = \mindstellar\database\ConnectionManager::getInstance(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $c_db = $conn->getHandle();
    $db   = new \mindstellar\database\Connection($c_db);

    $sql = file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');

    try {
        $db->executeScript($sql);
    } catch (\mindstellar\database\DbException $e) {
        return install_db_error_message($e->getCode(), array('dbhost' => $dbhost, 'dbname' => $dbname));
    }

    // Schema is already at target state; record every migration as applied without running it.
    $runner = new mindstellar\migration\MigrationRunner($db, ABS_PATH . 'oc-includes/osclass/installer/migrations');
    $runner->ensureLedger();
    $runner->baseline();

    $locales = osc_listLocales();
    $values  = array(
        'pk_c_code'         => osc_current_admin_locale(),
        's_name'            => $locales[osc_current_admin_locale()]['name'],
        's_short_name'      => $locales[osc_current_admin_locale()]['short_name'],
        's_description'     => $locales[osc_current_admin_locale()]['description'],
        's_version'         => $locales[osc_current_admin_locale()]['version'],
        's_direction'       => $locales[osc_current_admin_locale()]['direction'],
        's_author_name'     => $locales[osc_current_admin_locale()]['author_name'],
        's_author_url'      => $locales[osc_current_admin_locale()]['author_url'],
        's_currency_format' => $locales[osc_current_admin_locale()]['currency_format'],
        's_date_format'     => $locales[osc_current_admin_locale()]['date_format'],
        'b_enabled'         => 1,
        'b_enabled_bo'      => 1
    );

    if (isset($locales[osc_current_admin_locale()]['stop_words'])) {
        $values['s_stop_words'] = $locales[osc_current_admin_locale()]['stop_words'];
    }

    // The site language row must exist before the mail templates import: those
    // rows carry a foreign key to t_locale. Written through the parameterized
    // query builder (bound values, one prepared statement).
    try {
        \mindstellar\database\Db::table(DB_TABLE_PREFIX . 't_locale')->insert($values);
    } catch (\Throwable $e) {
        error_log('Shopclass install: could not save the site language row: ' . $e->getMessage());

        return array(
            'error' => __("Setup couldn't save the site language. The database user may not be able to "
                . 'write to the database — grant it full privileges and try again.')
        );
    }

    $required_files = array(
        ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql',
        ABS_PATH . 'oc-includes/osclass/installer/pages.sql',

    );

    $sql = '';
    foreach ($required_files as $file) {
        if (!file_exists($file)) {
            return array('error' => sprintf(__('The file %s doesn\'t exist'), $file));
        }

        $sql .= file_get_contents($file);
    }

    try {
        $db->executeScript($sql);
        // ImageMagick keeps photo colours, so a new site uses it where it is loaded.
        if (extension_loaded('imagick')) {
            $db->execute('UPDATE ' . DB_TABLE_PREFIX . "t_preference SET s_value = '1' WHERE s_section = 'osclass' AND s_name = 'use_imagick'");
        }
    } catch (\mindstellar\database\DbException $e) {
        return install_db_error_message($e->getCode(), array('dbhost' => $dbhost, 'dbname' => $dbname));
    }

    // Email templates, after pages.sql has created the rows they describe. The
    // chosen language ships its own set; where it does not, the bundled English one
    // is imported under that language rather than under en_US, so the site still has
    // templates for the locale it actually runs in.
    $mail_json = ABS_PATH . 'oc-content/languages/' . osc_current_admin_locale() . '/mail.json';
    if (!file_exists($mail_json)) {
        $mail_json = ABS_PATH . 'oc-includes/osclass/installer/mail.json';
    }

    $mail_templates = @file_get_contents($mail_json);
    if ($mail_templates === false) {
        return array('error' => sprintf(__('The file %s doesn\'t exist'), $mail_json));
    }

    $decoded = json_decode($mail_templates, true);
    if (is_array($decoded)) {
        $decoded['language'] = osc_current_admin_locale();
        $mail_templates      = json_encode($decoded);
    }

    Page::getInstance()->importEmailJsonTemplates($mail_templates);

    // Seed the installer's own preference rows through the parameterized API,
    // grouped in one transaction so a mid-write failure leaves none of them
    // behind. REPLACE INTO (matching Preference::replace) keeps a retry
    // idempotent instead of hitting a duplicate key.
    try {
        $prefTable   = DB_TABLE_PREFIX . 't_preference';
        $adminLocale = osc_current_admin_locale();
        \mindstellar\database\Db::transaction(static function () use ($prefTable, $adminLocale) {
            $replace = "REPLACE INTO $prefTable (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)";
            \mindstellar\database\Db::execute($replace, array('language', $adminLocale, 'osclass', 'STRING'));
            \mindstellar\database\Db::execute($replace, array('admin_language', $adminLocale, 'osclass', 'STRING'));
            \mindstellar\database\Db::execute($replace, array('csrf_name', 'CSRF' . mt_rand(0, mt_getrandmax()), 'osclass', 'STRING'));
            \mindstellar\database\Db::execute($replace, array('csrf_secret', bin2hex(random_bytes(32)), 'osclass', 'STRING'));
        });
    } catch (\Throwable $e) {
        error_log('Shopclass install: seeding preferences failed: ' . $e->getMessage());

        return array(
            'error' => __('Setup could not finish writing its initial settings. Nothing partial was saved '
                . '— press the button to try again.')
        );
    }

    if ($writesConfig) {
        copy_config_file($dbname, $username, $password, $dbhost, $tableprefix);
    }
    // The site step creates the admin; it runs only after this session installed the database.
    Session::getInstance()->_set('install_db_done', 1);

    return false;
}

/**
 * Insert the starter category tree for a site type.
 *
 * @param string $type 'classifieds' or 'shop'
 *
 * @return int Number of categories written
 * @throws \InvalidArgumentException when the type is unknown
 */
function install_seed_categories(string $type): int
{
    if (!in_array($type, array('classifieds', 'shop'), true)) {
        throw new \InvalidArgumentException('Unknown site type: ' . $type);
    }
    require_once LIB_PATH . 'osclass/formatting.php';

    if (!function_exists('osc_apply_filter')) {
        /**
         * Installer stand-in for the plugin filter helper: returns the value unchanged.
         *
         * @param string $dummyfilter Filter name, ignored
         * @param mixed  $str
         *
         * @return mixed The value it was given
         */
        // @phpstan-ignore function.inner (installer-only stand-in, declared once)
        function osc_apply_filter($dummyfilter, $str)
        {
            return $str;
        }
    }

    $rows      = require LIB_PATH . 'osclass/installer/data/categories-' . $type . '.php';
    $locale    = osc_current_admin_locale();
    $mCat      = Category::getInstance();
    $positions = array();
    foreach ($rows as $row) {
        $parent                    = $row['parent'];
        $key                       = (string) $parent;
        $positions[$key]           = ($positions[$key] ?? 0) + 1;
        $fields                    = array(
            'pk_i_id'            => $row['id'],
            'fk_i_parent_id'     => $parent,
            'i_position'         => $positions[$key],
            'i_expiration_days'  => 0,
            'b_enabled'          => 1,
        );
        $mCat->insert($fields, array($locale => array('s_name' => $row['name'])));
    }

    return count($rows);
}

/**
 * Add the sample custom fields and listings for a site type, and record their ids so they can be removed.
 * Each step is non-fatal; what went wrong comes back in 'errors'.
 *
 * @param string      $type     'classifieds' or 'shop'
 * @param string|null $imageDir folder of photos named like the sample's 'image' keys; the bundled pack when null
 *
 * @return array{listings: int, fields: int, errors: string[]}
 * @throws \InvalidArgumentException when the type is unknown
 * @throws \RuntimeException when sample data is already installed
 */
function install_seed_sample(string $type, ?string $imageDir = null): array
{
    if (!in_array($type, array('classifieds', 'shop'), true)) {
        throw new \InvalidArgumentException('Unknown site type: ' . $type);
    }
    require_once LIB_PATH . 'osclass/helpers/hKv.php';
    if (osc_kv_get('sample', 'items') !== null) {
        throw new \RuntimeException('Sample data is already installed.');
    }

    $locale  = osc_current_admin_locale();
    $errors  = array();
    $fieldId   = array();
    $fieldType = array();
    $fieldIds  = array();
    $groupIds  = array();
    $itemIds   = array();

    $groupModel = FieldGroup::getInstance();
    $service    = \mindstellar\fields\FieldService::make($locale);
    foreach (require LIB_PATH . 'osclass/installer/data/fields-' . $type . '.php' as $position => $group) {
        try {
            $groupId = $groupModel->insertGroup($group['name'], '', $position);
            if (!$groupId) {
                throw new \RuntimeException('group');
            }
            $groupIds[] = (int) $groupId;
            $groupModel->insertCategories($groupId, $group['categories']);
            foreach ($group['fields'] as $field) {
                $id = $service->create(array(
                    'name'       => $field['name'],
                    'type'       => $field['type'],
                    'searchable' => $field['searchable'],
                    'options'    => $field['options'] ?? array(),
                ));
                $groupModel->setFieldSingleGroup($id, $groupId);
                $fieldId[$field['key']]   = $id;
                $fieldIds[]               = $id;
                $fieldType[$field['key']] = $field['type'];
            }
        } catch (\Throwable $e) {
            $errors[] = 'fields: ' . $e->getMessage();
        }
    }

    $service = new \mindstellar\listing\ListingService();
    $actor   = \mindstellar\auth\Actor::admin(1, '127.0.0.1');
    $imgDir  = $imageDir ?? LIB_PATH . 'osclass/installer/sample/img/';
    foreach (require LIB_PATH . 'osclass/installer/data/sample-' . $type . '.php' as $row) {
        $copy = null;
        try {
            $meta = array();
            foreach ($row['fields'] as $key => $value) {
                if (isset($fieldId[$key])) {
                    $meta[$fieldId[$key]] = $fieldType[$key] === 'DATE' ? strtotime((string) $value) : $value;
                }
            }
            $form = array(
                'catId'        => $row['category'],
                'title'        => array($locale => $row['title']),
                'description'  => array($locale => $row['description']),
                'price'        => $row['price'],
                'contactName'  => __('Example seller'),
                'contactEmail' => 'seller@example.com',
                'meta'         => $meta,
            );
            if (isset($row['variants'])) {
                $form['product'] = array('sell' => 1, 'hasOptions' => 1, 'variants' => $row['variants']);
            } elseif (isset($row['stock'])) {
                $form['product'] = array('sell' => 1, 'stock' => $row['stock']);
            }
            // A photo is moved into the listing and deleted from where it was, so give it a copy.
            $source = $imgDir . $row['image'] . '.webp';
            if (is_file($source)) {
                $copy = tempnam(sys_get_temp_dir(), 'sample');
                copy($source, $copy);
                $form['photos'] = array($copy);
            }
            $save = static fn (array $form) => $service->create(
                \mindstellar\listing\ListingInput::fromArray($form, $actor, true),
                $actor,
                true
            );
            try {
                $saved = $save($form);
            } catch (\Throwable $e) {
                // A photo the server refuses must not cost the listing.
                if (!isset($form['photos'])) {
                    throw $e;
                }
                $errors[] = $row['image'] . ': ' . $e->getMessage();
                unset($form['photos']);
                $saved = $save($form);
            }
            $itemIds[] = $saved->id();
        } catch (\Throwable $e) {
            $errors[] = $row['category'] . ': ' . $e->getMessage();
        }
        if ($copy !== null && is_file($copy)) {
            unlink($copy);
        }
    }

    osc_kv_set('sample', 'items', $itemIds);
    osc_kv_set('sample', 'fields', $fieldIds);
    osc_kv_set('sample', 'groups', $groupIds);

    return array('listings' => count($itemIds), 'fields' => count($fieldIds), 'errors' => $errors);
}

/**
 * Load the helpers the starter content and sample listings call; the installer's bootstrap leaves them out.
 *
 * @return void
 */
function install_load_seed_helpers(): void
{
    foreach (array('hSecurity', 'hValidate', 'hUsers', 'hItems', 'hFields', 'hKv', 'hTheme', 'hBilling') as $helper) {
        require_once LIB_PATH . 'osclass/helpers/' . $helper . '.php';
    }
}

/**
 * Write what the Your-site step chose: the category tree, the example page and, if asked, the sample data.
 * Non-fatal: a failure is logged and flagged for the finish screen, and the site still installs.
 *
 * @param string $type   'classifieds' or 'shop'
 * @param bool   $sample add sample custom fields and listings
 *
 * @return bool True when everything was written
 */
function install_seed_site(string $type, bool $sample): bool
{
    // The Your-site step can be sent twice; the second call finds the tree and stops.
    if (\mindstellar\database\Db::selectOne('SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_category LIMIT 1') !== null) {
        return true;
    }
    $ok = true;
    try {
        install_seed_categories($type);
        install_seed_page();
    } catch (\Throwable $e) {
        error_log('Shopclass install: starter content could not be added: ' . $e->getMessage());
        $ok = false;
    }
    if ($sample && $ok) {
        try {
            $result = install_seed_sample($type);
            if ($result['errors'] !== array()) {
                error_log('Shopclass install: sample data was added with errors: ' . implode('; ', $result['errors']));
                $ok = $result['listings'] > 0;
            }
        } catch (\Throwable $e) {
            error_log('Shopclass install: sample data could not be added: ' . $e->getMessage());
            $ok = false;
        }
    }
    if (!$ok) {
        Session::getInstance()->_set('install_sample_warning', 1);
    }

    return $ok;
}

/**
 * Insert the default example page.
 *
 * @return void
 */
function install_seed_page(): void
{
    require LIB_PATH . 'osclass/installer/basic_data.php';
    /** @var array<string, mixed> $page */
    Page::getInstance()->insert(
        array(
            's_internal_name' => $page['s_internal_name'],
            'b_indelible'     => 0,
            's_meta'          => json_encode('')
        ),
        array(
            osc_current_admin_locale() => array(
                's_title' => $page['s_title'],
                's_text'  => $page['s_text']
            )
        )
    );
}

/**
 * Define the database and path constants the rest of the installer needs, if not already set.
 *
 * @param string $dbhost
 * @param string $dbname
 * @param string $username
 * @param string $password
 * @param string $tableprefix
 *
 * @return void
 */
function define_install_constants($dbhost, $dbname, $username, $password, $tableprefix)
{

    defined('DB_NAME') or define('DB_NAME', $dbname);
    defined('DB_USER') or define('DB_USER', $username);
    defined('DB_PASSWORD') or define('DB_PASSWORD', $password);
    defined('DB_HOST') or define('DB_HOST', $dbhost);
    defined('DB_TABLE_PREFIX') or define('DB_TABLE_PREFIX', $tableprefix);
    defined('REL_WEB_URL') or define('REL_WEB_URL', get_relative_url());
    defined('WEB_PATH') or define('WEB_PATH', get_absolute_url());
}

/**
 * Create config file from scratch
 *
 * @param string $dbname      Database name
 * @param string $username    User of the database
 * @param string $password    Password for user of the database
 * @param string $dbhost      Database host
 * @param string $tableprefix Prefix for table names
 *
 * @return void
 * @since 1.2
 *
 */
function create_config_file($dbname, $username, $password, $dbhost, $tableprefix)
{
    [$abs_url, $rel_url] = install_urls();
    [$dbname, $username, $password, $dbhost, $tableprefix, $abs_url, $rel_url] = array_map(
        'install_config_literal',
        [$dbname, $username, $password, $dbhost, $tableprefix, $abs_url, $rel_url]
    );
    $config_text = <<<CONFIG
<?php
/**
 * The base MySQL settings of Shopclass
 */

/** MySQL database name for Shopclass */
define('DB_NAME', getenv('DB_NAME') ?: '$dbname');

/** MySQL database username */
define('DB_USER', getenv('DB_USER') ?: '$username');

/** MySQL database password */
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '$password');

/** MySQL hostname (an environment variable, when set, overrides this) */
define('DB_HOST', getenv('DB_HOST') ?: '$dbhost');

/** Database Table prefix */
define('DB_TABLE_PREFIX', getenv('DB_TABLE_PREFIX') ?: '$tableprefix');

/**
 * Keep the server's own strict SQL modes instead of relaxing them.
 *
 * With this on, a value the column cannot hold is rejected rather than silently
 * cut short or clamped. Remove the line to go back to the relaxed modes.
 */
define('OSC_DB_STRICT_MODE', true);

define('REL_WEB_URL', '$rel_url');

defined('WEB_PATH') or define('WEB_PATH', '$abs_url');

CONFIG;

    if (file_put_contents(ABS_PATH . 'config.php', $config_text) !== false) {
        install_config_chmod(ABS_PATH . 'config.php');
    }
}

/**
 * Site URL and path for config.php. A CLI run has no request to derive them from,
 * so the CLI installer defines them up front.
 *
 * @return string[] [absolute URL, relative path]
 */
function install_urls()
{
    if (PHP_SAPI === 'cli' && defined('WEB_PATH') && defined('REL_WEB_URL')) {
        return [WEB_PATH, REL_WEB_URL];
    }

    return [get_absolute_url(), get_relative_url()];
}

/**
 * A value made safe to place inside a single-quoted PHP string in config.php.
 *
 * @param string $value
 *
 * @return string
 */
function install_config_literal($value)
{
    return addcslashes((string) $value, "'\\");
}

/**
 * Whether a site address is a plain http(s) URL that config.php can hold.
 *
 * @param string $url
 *
 * @return bool
 */
function install_web_url_valid($url)
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('#^https?://[^\'\\\\?\#]+$#i', (string) $url) === 1;
}

/**
 * Make config.php readable by the web server but writable only by its owner.
 * Skipped when another user owns the file, where chmod() cannot work.
 *
 * @param string $path
 *
 * @return void
 */
function install_config_chmod($path)
{
    $owner = @fileowner($path);
    if ($owner !== false && (!function_exists('posix_geteuid') || $owner === posix_geteuid())) {
        @chmod($path, 0644);
    }
}

/**
 * Create config from config-sample.php file
 *
 * @param string $dbname
 * @param string $username
 * @param string $password
 * @param string $dbhost
 * @param string $tableprefix
 *
 * @return bool False when config-sample.php cannot be read or config.php cannot be written
 * @since 1.2
 */
function copy_config_file($dbname, $username, $password, $dbhost, $tableprefix)
{
    // Prepare variables
    [$abs_url, $rel_url] = install_urls();
    [$dbname, $username, $password, $dbhost, $tableprefix, $abs_url, $rel_url] = array_map(
        'install_config_literal',
        [$dbname, $username, $password, $dbhost, $tableprefix, $abs_url, $rel_url]
    );

    // Load config sample
    $config_sample_path = ABS_PATH . 'config-sample.php';
    $config_sample = file($config_sample_path);
    if (!$config_sample) {
        // Handle file loading error
        return false;
    }

    // Only the quoted placeholders, in one pass: the bare words also appear in the
    // sample's comments, and a value already written must not be replaced again.
    $config_text = strtr(implode('', $config_sample), array(
        "'database_name'" => "'$dbname'",
        "'username'"      => "'$username'",
        "'password'"      => "'$password'",
        "'db_host'"       => "'$dbhost'",
        "'oc_'"           => "'$tableprefix'",
        "'rel_here'"      => "'$rel_url'",
        "'web_path_here'" => "'$abs_url'",
    ));

    // Write to config.php
    $config_path = ABS_PATH . 'config.php';
    $write_success = file_put_contents($config_path, $config_text);
    if (!$write_success) {
        // Handle write error
        return false;
    }

    install_config_chmod($config_path);

    return true;
}

/**
 * Whether the site has database settings but its database cannot be used right now: the
 * server is down, the password is wrong, or a table is damaged. A missing database or
 * missing tables is a site to install, not an outage.
 *
 * @return bool
 */
function install_database_unreachable(): bool
{
    require_once LIB_PATH . 'osclass/config-loader.php';
    if (!osc_is_configured()) {
        return false;
    }

    try {
        $probe = new \mindstellar\database\ConnectionManager(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $code  = $probe->getErrorConnectionLevel() ?: $probe->getErrorLevel();
        if ($code > 0) {
            return $code !== 1049;
        }
        $probe->getHandle()->query('SELECT 1 FROM `' . DB_TABLE_PREFIX . 't_preference` LIMIT 1');
    } catch (mysqli_sql_exception $e) {
        return (int) $e->getCode() !== 1146;
    } catch (\Throwable $e) {
        return true;
    }

    return false;
}

/**
 * Whether a usable database configuration exists and carries the installed sentinel.
 * Any configuration, connection or query failure counts as not installed.
 *
 * @return bool
 */
function is_osclass_installed()
{
    // Resolve configuration from config.php or the environment.
    require_once LIB_PATH . 'osclass/config-loader.php';

    // No database configured at all: not installed — let the installer run.
    if (!osc_is_configured()) {
        return false;
    }

    try {
        // Its own connection, never the shared one: a failed check must not leave a broken
        // connection behind for the install that may follow. The prefix is a config constant.
        $probe = new \mindstellar\database\ConnectionManager(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if ($probe->getErrorConnectionLevel() > 0 || $probe->getErrorLevel() > 0) {
            return false;
        }
        $count = $probe->getHandle()->query(
            'SELECT COUNT(*) FROM `' . DB_TABLE_PREFIX . "t_preference` WHERE s_name = 'osclass_installed'"
        )->fetch_row()[0];
    } catch (\Throwable $e) {
        return false;
    }

    return (int)$count === 1;
}

/**
 * Commit the install sentinel and return the credentials for the finish screen.
 *
 * @param string $password Plain-text admin password, echoed back for display
 *
 * @return array{s_email:string,admin_user:string,password:string}
 */
function finish_installation($password)
{
    require_once LIB_PATH . 'osclass/helpers/hPlugins.php';

    // The ping_search_engines preference still records the old opt-in cookie.
    $pingEngines = isset($_COOKIE['osclass_ping_engines']) && (int)$_COOKIE['osclass_ping_engines'] === 1;

    // Finalize in one transaction. The osclass_installed sentinel is written
    // LAST, so the row that is_osclass_installed() and upgrades key off can never
    // land beside a half-written finalize. REPLACE keeps a step-4 page refresh
    // from tripping a duplicate key; the row values are byte-identical to before.
    \mindstellar\database\Db::transaction(static function () use ($pingEngines) {
        $prefTable = DB_TABLE_PREFIX . 't_preference';
        $replace   = "REPLACE INTO $prefTable (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)";
        \mindstellar\database\Db::execute($replace, array('ping_search_engines', $pingEngines ? '1' : '0', 'osclass', 'BOOLEAN'));
        \mindstellar\database\Db::execute($replace, array('osclass_installed', '1', 'osclass', 'BOOLEAN'));
    });

    // Admin account for the credentials shown on the finish screen.
    $admin = \mindstellar\database\Db::table(DB_TABLE_PREFIX . 't_admin')->where('pk_i_id', 1)->first();

    return array(
        's_email'    => $admin['s_email'] ?? '',
        'admin_user' => $admin['s_username'] ?? '',
        'password'   => $password,
    );
}

/**
 * Menus
 *
 * @param array<string,mixed>|null $form_data
 * @param string|null              $error
 *
 * @return void
 */
function display_database_config($form_data = null, $error = null)
{
    include_once 'installer/gui/install-database.php';
}

/**
 * The notice shown when a step arrives without a valid installer session.
 *
 * @return void
 */
function install_session_expired_panel()
{
    ?>
    <div class="ins-panel ins-panel-warning" role="alert">
        <?php _e('Your session expired before setup finished. Please restart the installer to complete it.'); ?>
    </div>
    <p>
        <a class="ins-btn ins-btn-primary" href="install.php"><?php _e('Restart the installer'); ?></a>
    </p>
    <?php
}

/**
 * Render the "What kind of site?" step.
 *
 * @param array{available:bool, reason:string, plugins:list<array<string,mixed>>} $offer  ShopSetup::offer()
 * @param array{type:string, sample:bool, plugins:list<string>|null}             $chosen install_site_choice()
 *
 * @return void
 */
function display_site_type(array $offer, array $chosen)
{
    include 'installer/gui/install-site-type.php';
}

/**
 * What the visitor picked on the site-type step, as kept in the session.
 *
 * @return array{type:string, sample:bool, plugins:list<string>|null} plugins is null before the step was sent
 */
function install_site_choice(): array
{
    $session = Session::getInstance();
    $plugins = $session->_get('install_shop_plugins');

    return array(
        'type'    => $session->_get('install_site_type') === 'shop' ? 'shop' : 'classifieds',
        'sample'  => (bool) $session->_get('install_sample_data'),
        'plugins' => is_array($plugins) ? array_values(array_map('strval', $plugins)) : null,
    );
}

/**
 * Keep the site-type step's answer in the session. Shop is kept only when it was offered, and only
 * offered plugins are kept, so later steps install nothing else.
 *
 * @param array{available:bool, plugins:list<array<string,mixed>>} $offer ShopSetup::offer()
 *
 * @return void
 */
function install_save_site_choice(array $offer): void
{
    $shop    = Params::getParamString('site_type') === 'shop' && $offer['available'];
    $offered = array_column($offer['plugins'], 'slug');
    $posted  = Params::getParamArray('shop_plugins');
    $plugins = $shop ? array_values(array_intersect($offered, array_filter($posted, 'is_string'))) : array();

    $session = Session::getInstance();
    $session->_set('install_site_type', $shop ? 'shop' : 'classifieds');
    $session->_set('install_sample_data', Params::getParamString('sample_data') === '1' ? 1 : 0);
    $session->_set('install_shop_plugins', $plugins);
}

/**
 * Load every helper a normal request has and the core services; plugin install code expects them.
 *
 * @return void
 */
function install_load_app_helpers(): void
{
    foreach (\mindstellar\boot\Boot::HELPERS as $helper) {
        require_once LIB_PATH . $helper;
    }
    \mindstellar\boot\Boot::services();
}

/**
 * Whether a state-changing installer request may run: it carries the session's nonce and the
 * database step is done.
 *
 * @return bool
 */
function install_ajax_allowed(): bool
{
    return install_nonce_check() && (bool) Session::getInstance()->_get('install_db_done');
}

/**
 * One step of a shop install, one package per request: install_package (type theme or plugin)
 * or activate_theme. Only Aisle and the plugins ticked on the site-type step are accepted.
 *
 * @param string                              $action install_package or activate_theme
 * @param string                              $type   theme or plugin
 * @param string                              $slug
 * @param \mindstellar\install\ShopSetup|null $setup  the cached catalogue when null
 *
 * @return array{ok:bool, message:string}
 */
function install_shop_step(string $action, string $type, string $slug, ?\mindstellar\install\ShopSetup $setup = null): array
{
    $choice = install_site_choice();
    $theme  = \mindstellar\install\ShopSetup::THEME;
    if ($action === 'install_package' && $type === 'theme' && $slug === $theme) {
        $allowed = true;
    } elseif ($action === 'install_package' && $type === 'plugin') {
        $allowed = in_array($slug, (array) $choice['plugins'], true);
    } else {
        $allowed = $action === 'activate_theme';
        $slug    = $theme;
    }
    if ($choice['type'] !== 'shop' || !$allowed) {
        return array('ok' => false, 'message' => __('This package was not chosen for this site.'));
    }

    try {
        $setup ??= \mindstellar\install\ShopSetup::fromCache();
        if ($action === 'activate_theme') {
            $result = $setup->activate();
        } else {
            $result = $type === 'theme' ? $setup->installTheme() : $setup->installPlugin($slug);
        }
    } catch (\Throwable $e) {
        error_log('Shopclass install: setting up ' . $slug . ' failed: ' . $e->getMessage());
        $result = array('ok' => false, 'message' => __('Something went wrong while setting this up.'));
    }

    $session = Session::getInstance();
    $failed  = (array) ($session->_get('install_shop_failed') ?: array());
    unset($failed[$slug]);
    if (!$result['ok']) {
        $failed[$slug] = array('type' => $type === 'plugin' ? 'plugin' : 'theme', 'message' => $result['message']);
    }
    $session->_set('install_shop_failed', $failed);

    return $result;
}

/**
 * Render the installer's target-directory step.
 *
 * @return void
 */
function display_target()
{
    include_once 'installer/gui/install-target.php';
}

/**
 * Render the installer's finish step.
 *
 * @param string $password Plain-text admin password shown on the screen
 *
 * @return void
 */
function display_finish($password)
{
    include_once 'installer/gui/install-finish.php';
}

/**
 * Create the admin account and the site's identity preferences, then mail the
 * credentials to the address given on the form.
 *
 * @return array{email_status:string,s_password:string} email_status carries the mailer error, or '' on success
 */
function basic_info()
{
    $admin = Params::getParam('s_name');
    if (!$admin) {
        $admin = 'admin';
    }

    $password = Params::getParam('s_passwd', false, false);
    if (!$password) {
        $password = osc_genRandomPassword();
    }
    Params::setParam('password', $password);

    $adminUser  = $admin;
    $adminEmail = Params::getParam('email');
    $adminHash  = osc_hash_password($password);
    $webTitle   = Params::getParam('webtitle');

    // The admin account and the site's identity preferences are written together
    // in one transaction through the parameterized API: either the site has an
    // owner and a title, or nothing is saved. Success is the returned insert id,
    // never affected-rows (which don't propagate on the shared handle).
    // A retry after a failed finish finds the account this step already made; it is
    // updated, not inserted again, because the username and e-mail are unique.
    \mindstellar\database\Db::transaction(static function () use ($adminUser, $adminEmail, $adminHash, $webTitle) {
        $adminRow = array(
            's_name'     => 'Administrator',
            's_username' => $adminUser,
            's_password' => $adminHash,
            's_email'    => $adminEmail,
        );
        if (\mindstellar\database\Db::table(DB_TABLE_PREFIX . 't_admin')->where('pk_i_id', 1)->first() !== null) {
            \mindstellar\database\Db::table(DB_TABLE_PREFIX . 't_admin')->where('pk_i_id', 1)->update($adminRow);
        } else {
            \mindstellar\database\Db::table(DB_TABLE_PREFIX . 't_admin')->insert($adminRow);
        }

        $prefTable = DB_TABLE_PREFIX . 't_preference';
        $replace   = "REPLACE INTO $prefTable (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)";
        \mindstellar\database\Db::execute($replace, array('pageTitle', $webTitle, 'osclass', 'STRING'));
        \mindstellar\database\Db::execute($replace, array('contactEmail', $adminEmail, 'osclass', 'STRING'));
    });

    $body = sprintf(__('Hi %s,'), Params::getParam('webtitle')) . '<br/>';
    $body .= sprintf(__('Your Shopclass installation at %s is up and running.'
        . ' ' . 'You can access the administration panel with these details:'), WEB_PATH);
    $body .= '<br/>';
    $body .= '<ul>';
    // The password is raw input and the username may carry "&" or "<"; escaped, both
    // read exactly as typed in the HTML and in the plain copy made from it.
    $body .= '<li>' . sprintf(__('username: %s'), htmlspecialchars($admin, ENT_QUOTES, 'UTF-8', false)) . '</li>';
    $body .= '<li>' . sprintf(__('password: %s'), htmlspecialchars($password, ENT_QUOTES, 'UTF-8')) . '</li>';
    $body .= '</ul>';
    $body .= sprintf(
        __('Remember that for any doubts you might have you can consult our <a href="%1$s">documentation</a>'),
        'https://osclass.gitbook.io/osclass-docs/'
    );
    $body .= __('Cheers,') . '<br/>';
    $body .= __('The <a href="https://github.com/mindstellar/shopclass">Shopclass</a> team');

    $sitename = strtolower(Params::getServerParam('SERVER_NAME'));
    if (0 === strpos($sitename, 'www.')) {
        $sitename = substr($sitename, 4);
    }

    $mail           = new PHPMailer(true);
    $mail->CharSet  = 'utf-8';
    $mail->Host     = 'localhost';
    $mail->From     = 'osclass@' . $sitename;
    $mail->FromName = 'Shopclass';
    $mail->Subject  = 'Shopclass successfully installed!';
    $mail->addAddress(Params::getParam('email'), 'Shopclass administrator');
    $mail->Body    = $body;
    $mail->AltBody = _osc_mail_text($body);

    try {
        $mail->send();

        return array('email_status' => '', 's_password' => $password);
    } catch (\PHPMailer\PHPMailer\Exception $exception) {
        return array(
            'email_status' => Params::getParam('email') . '<br>' . $exception->errorMessage(),
            's_password'   => $password
        );
    }
}
