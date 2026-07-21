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

use mindstellar\utility\Utils;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * @param $value
 * @param $xss_check
 *
 * @return string
 */
function _purify($value, $xss_check)
{
    if (!$xss_check) {
        return $value;
    }

    $_config = HTMLPurifier_Config::createDefault();
    $_config->set('HTML.Allowed', '');
    $_config->set(
        'Cache.SerializerPath',
        dirname(dirname(__DIR__)) . '/oc-content/uploads/'
    );

    $_purifier = new HTMLPurifier($_config);


    if (is_array($value)) {
        foreach ($value as $k => &$v) {
            $v = _purify($v, $xss_check); // recursive
        }
    } else {
        $value = $_purifier->purify($value);
    }

    return $value;
}


/**
 * @param      $param
 * @param bool $htmlencode
 * @param bool $xss_check
 * @param bool $quotes_encode
 *
 * @return string
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
            return htmlspecialchars(stripslashes($value), ENT_QUOTES);
        }

        return htmlspecialchars(stripslashes($value), ENT_NOQUOTES);
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

    return $protocol . '://' . getServerParam('HTTP_HOST') . $URI;
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
        'PHP version >= 8.0.0' => array(
            'requirement' => __('PHP version >= 8.0.0'),
            'fn'          => version_compare(PHP_VERSION, '8.0.0', '>='),
            'solution'    => sprintf(__('At least PHP %s (PHP %s or higher recommended) is required to run Shopclass. '
                . 'You may talk with your hosting to upgrade your PHP version.'), '8.0', '8.2')
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
 * Check if some of the requirements to install Shopclass are correct or not
 *
 * @param $array
 *
 * @return boolean Check if all the requirements are correct
 * @since 1.2
 */
function check_requirements($array)
{
    foreach ($array as $k => $v) {
        if (!$v['fn']) {
            return true;
        }
    }

    return false;
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
                    __("That user connected, but isn't allowed to use the database %s. In your hosting "
                        . 'panel, give the user access to this database.'),
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

    // When asked to create the database, the meaningful test is whether the
    // admin credentials can reach the server at all.
    if ($createdb) {
        $adminUser = Params::getParam('admin_username');
        $adminPwd  = Params::getParam('admin_password', false, false);
        $probe     = new DBConnectionClass($dbhost, $adminUser, $adminPwd, '');
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
    $probe = new DBConnectionClass($dbhost, $username, $password, $dbname);
    $code  = $probe->getErrorConnectionLevel();
    if ($code === 0) {
        $code = $probe->getErrorLevel();
    }
    if ($code > 0) {
        $msg = install_db_error_message($code, $ctx);

        return array('ok' => false, 'level' => 'error', 'message' => $msg['error'], 'field' => $msg['field']);
    }

    // Connected. Warn early if this prefix already has Shopclass tables — the
    // 1050 collision the real install would otherwise hit halfway through. The
    // LIKE pattern is escaped so a literal prefix can't act as a wildcard.
    $db = $probe->getOsclassDb();
    if ($db instanceof mysqli) {
        $like = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $tableprefix) . 't_preference';
        $res  = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($like) . "'");
        if ($res instanceof mysqli_result && $res->num_rows > 0) {
            return array(
                'ok'      => false,
                'level'   => 'warning',
                'message' => __('Connected, but this database already has Shopclass tables with that '
                    . 'prefix. Choose a different table prefix under More options, or use an empty database.'),
                'field'   => 'tableprefix',
            );
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
    $nonce = Session::newInstance()->_get('install_nonce');
    if (!$nonce) {
        $nonce = bin2hex(random_bytes(16));
        Session::newInstance()->_set('install_nonce', $nonce);
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
    $session = (string)Session::newInstance()->_get('install_nonce');

    return $token !== '' && $session !== '' && hash_equals($session, $token);
}


/**
 * insert/update preference allow_report_osclass
 *
 * @param $value
 */
function set_allow_report_osclass($value)
{
    $values = array(
        's_section' => 'osclass',
        's_name'    => 'allow_report_osclass',
        's_value'   => $value,
        'e_type'    => 'BOOLEAN'
    );

    Preference::newInstance()->insert($values);
}


/**
 * Install Shopclass database
 *
 * @return mixed Error messages of the installation
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

    if (Params::getParam('createdb') != '') {
        $createdb = true;
    }

    if ($createdb) {
        $adminuser = Params::getParam('admin_username');
        $adminpwd  = Params::getParam('admin_password', false, false);

        // Probe with an ad-hoc connection: it must never become the shared
        // singleton, so a wrong password here can't poison later queries.
        $adminInstance = new DBConnectionClass($dbhost, $adminuser, $adminpwd, '');
        $error_num   = $adminInstance->getErrorConnectionLevel();

        if ($error_num > 0) {
            return install_db_error_message($error_num, array('dbhost' => $dbhost, 'dbname' => $dbname));
        }

        $m_db = $adminInstance->getOsclassDb();
        $comm = new DBCommandClass($m_db);
        // Backtick-quote the database name (escaping any backtick) so a name with
        // a hyphen or other punctuation — common on shared hosting — is created
        // safely and can't break out of the identifier.
        $quotedDbName = '`' . str_replace('`', '``', $dbname) . '`';
        $comm->query(sprintf(
            "CREATE DATABASE IF NOT EXISTS %s DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'",
            $quotedDbName
        ));

        $error_num = $comm->getErrorLevel();

        if ($error_num > 0) {
            return install_db_error_message($error_num, array('dbhost' => $dbhost, 'dbname' => $dbname));
        }

        unset($dbInstance, $comm, $adminInstance);
    }

    // Ad-hoc probe of the real Shopclass credentials (still not the singleton).
    $dbInstance      = new DBConnectionClass($dbhost, $username, $password, $dbname);
    $error_num = $dbInstance->getErrorConnectionLevel();

    if ($error_num == 0) {
        $error_num = $dbInstance->getErrorLevel();
    }

    if ($error_num > 0) {
        return install_db_error_message($error_num, array('dbhost' => $dbhost, 'dbname' => $dbname));
    }

    // When the configuration comes from the environment there is no config.php
    // to write or check — the database settings are managed externally.
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
    $conn = DBConnectionClass::newInstance(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $c_db = $conn->getOsclassDb();
    $comm = new DBCommandClass($c_db);

    $sql = file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');
    $comm->importSQL($sql);

    $error_num = $comm->getErrorLevel();

    if ($error_num > 0) {
        return install_db_error_message($error_num, array('dbhost' => $dbhost, 'dbname' => $dbname));
    }

    // Schema is already at target state; record every migration as applied without running it.
    $runner = new mindstellar\migration\MigrationRunner($comm, ABS_PATH . 'oc-includes/osclass/installer/migrations');
    $runner->ensureLedger();
    $runner->baseline();

    $locales = osc_listLocales();
    $values  = array(
        'pk_c_code'         => $locales[osc_current_admin_locale()]['locale_code'],
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
        osc_db_table(DB_TABLE_PREFIX . 't_locale')->insert($values);
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

    $install_lang_sql = ABS_PATH . 'oc-content/languages/' . osc_current_admin_locale() . '/mail.sql';
    $default_lang_sql = ABS_PATH . 'oc-includes/osclass/installer/mail.sql';

    if (file_exists($install_lang_sql)) {
        $required_files[] = $install_lang_sql;
    } else {
        $required_files[] = $default_lang_sql;
    }

    $sql = '';
    foreach ($required_files as $file) {
        if (!file_exists($file)) {
            return array('error' => sprintf(__('The file %s doesn\'t exist'), $file));
        }

        $sql .= file_get_contents($file);
    }

    $comm->importSQL($sql);

    $error_num = $comm->getErrorLevel();

    if ($error_num > 0) {
        return install_db_error_message($error_num, array('dbhost' => $dbhost, 'dbname' => $dbname));
    }

    // Seed the installer's own preference rows through the parameterized API,
    // grouped in one transaction so a mid-write failure leaves none of them
    // behind. REPLACE INTO (matching Preference::replace) keeps a retry
    // idempotent instead of hitting a duplicate key.
    try {
        $prefTable   = DB_TABLE_PREFIX . 't_preference';
        $adminLocale = osc_current_admin_locale();
        osc_db_transaction(static function () use ($prefTable, $adminLocale) {
            $replace = "REPLACE INTO $prefTable (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)";
            osc_db_execute($replace, array('language', $adminLocale, 'osclass', 'STRING'));
            osc_db_execute($replace, array('admin_language', $adminLocale, 'osclass', 'STRING'));
            osc_db_execute($replace, array('csrf_name', 'CSRF' . mt_rand(0, mt_getrandmax()), 'osclass', 'STRING'));
        });
    } catch (\Throwable $e) {
        error_log('Shopclass install: seeding preferences failed: ' . $e->getMessage());

        return array(
            'error' => __('Setup could not finish writing its initial settings. Nothing partial was saved '
                . '— press the button to try again.')
        );
    }

    // Sample categories, a demo listing and the default page. These run on the
    // existing models (their per-locale child rows carry real domain logic), so
    // they stay on the legacy path and, crucially, are non-fatal: a site with no
    // sample content still installs cleanly. A failure is flagged for the finish
    // screen rather than aborting the install.
    try {
        oc_install_example_data();
    } catch (\Throwable $e) {
        error_log('Shopclass install: sample content could not be added: ' . $e->getMessage());
        Session::newInstance()->_set('install_sample_warning', 1);
    }

    if ($writesConfig) {
        copy_config_file($dbname, $username, $password, $dbhost, $tableprefix);
    }

    return false;
}


/**
 * Insert the example data (categories and emails) on all available locales
 *
 * @return mixed Error messages of the installation
 * @since 2.4
 */
function oc_install_example_data()
{
    require_once LIB_PATH . 'osclass/formatting.php';
    require LIB_PATH . 'osclass/installer/basic_data.php';
    require_once LIB_PATH . 'osclass/helpers/hSecurity.php';
    require_once LIB_PATH . 'osclass/helpers/hValidate.php';
    require_once LIB_PATH . 'osclass/helpers/hUsers.php';
    $mCat = Category::newInstance();

    if (!function_exists('osc_apply_filter')) {
        /**
         * @param $dummyfilter
         * @param $str
         *
         * @return mixed
         */
        function osc_apply_filter($dummyfilter, $str)
        {
            return $str;
        }
    }


    foreach ($categories as $category) {
        $fields['pk_i_id']           = $category['pk_i_id'];
        $fields['fk_i_parent_id']    = $category['fk_i_parent_id'];
        $fields['i_position']        = $category['i_position'];
        $fields['i_expiration_days'] = 0;
        $fields['b_enabled']         = 1;

        $aFieldsDescription[osc_current_admin_locale()]['s_name'] = $category['s_name'];

        $mCat->insert($fields, $aFieldsDescription);
    }

    $mItem = new ItemActions(true);

    foreach ($item as $k => $v) {
        if ($k === 'description' || $k === 'title') {
            Params::setParam($k, array(osc_current_admin_locale() => $v));
        } else {
            Params::setParam($k, $v);
        }
    }

    $mItem->prepareData(true);
    $mItem->add();

    Page::newInstance()->insert(
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
 * @return mixed Error messages of the installation
 * @since 1.2
 *
 */
function create_config_file($dbname, $username, $password, $dbhost, $tableprefix)
{
    $password    = addslashes($password);
    $abs_url     = get_absolute_url();
    $rel_url     = get_relative_url();
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

define('REL_WEB_URL', '$rel_url');

defined('WEB_PATH') or define('WEB_PATH', '$abs_url');

CONFIG;

    file_put_contents(ABS_PATH . 'config.php', $config_text);
}


/**
 * Create config from config-sample.php file
 *
 * @param $dbname
 * @param $username
 * @param $password
 * @param $dbhost
 * @param $tableprefix
 *
 * @since 1.2
 */
function copy_config_file($dbname, $username, $password, $dbhost, $tableprefix)
{
    // Prepare variables
    $password = addslashes($password);
    $abs_url = get_absolute_url();
    $rel_url = get_relative_url();
    
    // Load config sample
    $config_sample_path = ABS_PATH . 'config-sample.php';
    $config_sample = file($config_sample_path);
    if (!$config_sample) {
        // Handle file loading error
        return false;
    }

    // Define replacements
    $replacements = array(
        'database_name' => $dbname,
        'username' => $username,
        'password' => $password,
        'db_host' => $dbhost,
        'oc_' => $tableprefix,
        'rel_here' => $rel_url,
        'web_path_here' => $abs_url,
    );

    // Perform replacements
    foreach ($config_sample as &$line) {
        foreach ($replacements as $search => $replace) {
            $line = str_replace($search, $replace, $line);
        }
    }

    // Write to config.php
    $config_path = ABS_PATH . 'config.php';
    $write_success = file_put_contents($config_path, implode('', $config_sample));
    if (!$write_success) {
        // Handle write error
        return false;
    }

    // Set file permissions
    chmod($config_path, 0666);

    return true;
}


/**
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
        // Establish the shared connection, then ask through the parameterized
        // API. Any failure — no server, missing table, wrong credentials —
        // means "not installed", exactly as the previous raw query behaved.
        // The table prefix is a config constant, never request input.
        DBConnectionClass::newInstance(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $count = osc_db_scalar(
            'SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_preference WHERE s_name = ?',
            array('osclass_installed')
        );
    } catch (\Throwable $e) {
        return false;
    }

    return (int)$count === 1;
}


/**
 * @param $password
 *
 * @return array
 */
function finish_installation($password)
{
    require_once LIB_PATH . 'osclass/helpers/hPlugins.php';

    // Whether the owner opted in to search-engine pings on step 2 (short-lived
    // cookie). Read before the finalize so the pref row records the choice.
    $pingEngines = isset($_COOKIE['osclass_ping_engines']) && (int)$_COOKIE['osclass_ping_engines'] === 1;

    // Finalize in one transaction. The osclass_installed sentinel is written
    // LAST, so the row that is_osclass_installed() and upgrades key off can never
    // land beside a half-written finalize. REPLACE keeps a step-4 page refresh
    // from tripping a duplicate key; the row values are byte-identical to before.
    osc_db_transaction(static function () use ($pingEngines) {
        $prefTable = DB_TABLE_PREFIX . 't_preference';
        $replace   = "REPLACE INTO $prefTable (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)";
        osc_db_execute($replace, array('ping_search_engines', $pingEngines ? '1' : '0', 'osclass', 'BOOLEAN'));
        osc_db_execute($replace, array('osclass_installed', '1', 'osclass', 'BOOLEAN'));
    });

    // Network I/O never belongs inside a transaction: ping only after the
    // finalize has committed, and only if the owner opted in.
    if ($pingEngines) {
        install_ping_search_engines();
    }

    // Admin account for the credentials shown on the finish screen.
    $admin = osc_db_table(DB_TABLE_PREFIX . 't_admin')->where('pk_i_id', 1)->first();

    return array(
        's_email'    => $admin['s_email'] ?? '',
        'admin_user' => $admin['s_username'] ?? '',
        'password'   => $password,
    );
}


/**
 * Menus
 */
function display_database_config($form_data = null, $error = null)
{
    include_once 'installer/gui/install-database.php';
}


function display_target()
{
    include_once 'installer/gui/install-target.php';
}


/**
 * Notify the major search engines that the sitemap exists. Network I/O only —
 * the ping_search_engines preference is recorded by the finalize transaction in
 * finish_installation(). Best-effort: each request is isolated so a slow or dead
 * endpoint can never block the finish screen.
 */
function install_ping_search_engines()
{
    $sitemap = urlencode(osc_search_url(array('sFeed' => 'rss')));
    $targets = array(
        'http://www.google.com/webmasters/sitemaps/ping?sitemap=' . $sitemap,
        'http://www.bing.com/webmaster/ping.aspx?siteMap=' . $sitemap,
    );

    foreach ($targets as $target) {
        try {
            Utils::doRequest($target, array());
        } catch (\Throwable $e) {
            error_log('Shopclass install: search-engine ping failed: ' . $e->getMessage());
        }
    }
}


/**
 * @param $password
 */
function display_finish($password)
{
    include_once 'installer/gui/install-finish.php';
}


/**
 * @return array
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
    osc_db_transaction(static function () use ($adminUser, $adminEmail, $adminHash, $webTitle) {
        osc_db_table(DB_TABLE_PREFIX . 't_admin')->insert(array(
            's_name'     => 'Administrator',
            's_username' => $adminUser,
            's_password' => $adminHash,
            's_email'    => $adminEmail,
        ));

        $prefTable = DB_TABLE_PREFIX . 't_preference';
        $replace   = "REPLACE INTO $prefTable (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)";
        osc_db_execute($replace, array('pageTitle', $webTitle, 'osclass', 'STRING'));
        osc_db_execute($replace, array('contactEmail', $adminEmail, 'osclass', 'STRING'));
    });

    $body = sprintf(__('Hi %s,'), Params::getParam('webtitle')) . '<br/>';
    $body .= sprintf(__('Your Shopclass installation at %s is up and running.'
        . ' ' . 'You can access the administration panel with these details:'), WEB_PATH);
    $body .= '<br/>';
    $body .= '<ul>';
    $body .= '<li>' . sprintf(__('username: %s'), $admin) . '</li>';
    $body .= '<li>' . sprintf(__('password: %s'), $password) . '</li>';
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
    $mail->AltBody = $body;

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


/**
 * @return bool
 */
function install_locations()
{
    $location = Params::getParam('locationsql');
    if ($location) {
        $sql = osc_file_get_contents(osc_get_locations_sql_url($location));
        if ($sql) {
            $conn = DBConnectionClass::newInstance();
            $c_db = $conn->getOsclassDb();
            $comm = new DBCommandClass($c_db);
            $comm->query('SET FOREIGN_KEY_CHECKS = 0');
            $comm->importSQL($sql);
            $comm->query('SET FOREIGN_KEY_CHECKS = 1');

            return true;
        }
    }

    return false;
}
