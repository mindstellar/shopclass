<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
 * Get the requirements to install Osclass
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
            'solution'    => sprintf(__('At least PHP %s (PHP %s or higher recommended) is required to run Osclass. '
                . 'You may talk with your hosting to upgrade your PHP version.'), 7.2, 7.3)
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
            'solution'    => __('<code>config-sample.php</code> file is required, you should re-download Osclass.')
        );
    }

    return $array;
}


/**
 * Check if some of the requirements to install Osclass are correct or not
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
 * Install Osclass database
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

    if (Params::getParam('createdb') != '') {
        $createdb = true;
    }

    if ($createdb) {
        $adminuser = Params::getParam('admin_username');
        $adminpwd  = Params::getParam('admin_password', false, false);

        $adminInstance = new DBConnectionClass($dbhost, $adminuser, $adminpwd, '');
        $error_num   = $adminInstance->getErrorConnectionLevel();

        if ($error_num > 0) {
            switch ($error_num) {
                case 1049:
                    return array(
                        'error' => __("The database doesn't exist. You should check the \"Create DB\" "
                            . 'checkbox and fill in a username and password with the right privileges')
                    );
                case 1045:
                    return array('error' => __('Cannot connect to the database. Check if the user has privileges.'));
                case 1044:
                    return array(
                        'error' => __('Cannot connect to the database. Check if the username and password are correct.')
                    );
                case 2002:
                case 2005:
                    return array('error' => __("Can't resolve MySQL host. Check if the host is correct."));
                default:
                    return array(
                        'error' => sprintf(__('Cannot connect to the database. Error number: %s'), $error_num),
                    );
            }
        }

        $m_db = $adminInstance->getOsclassDb();
        $comm = new DBCommandClass($m_db);
        $comm->query(sprintf(
            "CREATE DATABASE IF NOT EXISTS %s DEFAULT CHARACTER SET 'UTF8' COLLATE 'UTF8_GENERAL_CI'",
            $dbname
        ));

        $error_num = $comm->getErrorLevel();

        if ($error_num > 0) {
            if (in_array($error_num, array(1006, 1044, 1045))) {
                return array(
                    'error' => __("Can't create the database. Check if the admin username "
                        . 'and password are correct.')
                );
            }

            return array(
                'error' => sprintf(__("Can't create the database. Error number: %s"), $error_num)
            );
        }

        unset($dbInstance, $comm, $adminInstance);
    }

    $dbInstance      = new DBConnectionClass($dbhost, $username, $password, $dbname);
    $error_num = $dbInstance->getErrorConnectionLevel();

    if ($error_num == 0) {
        $error_num = $dbInstance->getErrorLevel();
    }

    if ($error_num > 0) {
        switch ($error_num) {
            case 1049:
                return array(
                    'error' => __("The database doesn't exist. You should check the \"Create DB\" "
                        . "checkbox and fill in a username and password with the right privileges")
                );
                break;
            case 1045:
                return array('error' => __('Cannot connect to the database. Check if the user has privileges.'));
                break;
            case 1044:
                return array(
                    'error' => __('Cannot connect to the database. Check if the username and password '
                        . 'are correct.')
                );
                break;
            case 2002:
            case 2005:
                return array('error' => __("Can't resolve MySQL host. Check if the host is correct."));
                break;
            default:
                return array(
                    'error' => sprintf(
                        __('Cannot connect to the database. Check if you host, username, password, '
                            . 'database. Error number: %s'),
                        $error_num
                    )
                );
                break;
        }
    }

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

    define_install_constants($dbhost, $dbname, $username, $password, $tableprefix);

    $sql = file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');

    $conn = new DBConnectionClass(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $c_db = $conn->getOsclassDb();
    $comm = new DBCommandClass($c_db);
    $comm->importSQL($sql);

    $error_num = $comm->getErrorLevel();

    if ($error_num > 0) {
        if ($error_num === 1050) {
            return array(
                'error' => __('There are tables with the same name in the database. '
                    . 'Change the table prefix or the database and try again.')
            );
        }

        return array(
            'error' => sprintf(
                __("Can't create the database structure. Error number: %s"),
                $error_num
            )
        );
    }

    // Schema is already at target state; record every migration as applied without running it.
    $runner = new mindstellar\migration\MigrationRunner($comm, ABS_PATH . 'oc-includes/osclass/installer/migrations');
    $runner->ensureLedger();
    $runner->baseline();

    $localeManager = OSCLocale::newInstance();

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
    $localeManager->insert($values);

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
        if ($error_num === 1471) {
            return array(
                'error' => __("Can't insert basic configuration. "
                    . "This user has no privileges to 'INSERT' into the database.")
            );
        }

        return array(
            'error' => sprintf(
                __("Can't insert basic configuration. Error number: %s"),
                $error_num
            )
        );
    }

    osc_set_preference('language', osc_current_admin_locale());
    osc_set_preference('admin_language', osc_current_admin_locale());
    osc_set_preference('csrf_name', 'CSRF' . mt_rand(0, mt_getrandmax()));

    oc_install_example_data();
    copy_config_file($dbname, $username, $password, $dbhost, $tableprefix);

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
 * The base MySQL settings of Osclass
 */

/** MySQL database name for Osclass */
define('DB_NAME', '$dbname');

/** MySQL database username */
define('DB_USER', '$username');

/** MySQL database password */
define('DB_PASSWORD', '$password');

/** MySQL hostname */
define('DB_HOST', '$dbhost');

/** Database Table prefix */
define('DB_TABLE_PREFIX', '$tableprefix');

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
    if (!file_exists(ABS_PATH . 'config.php')) {
        return false;
    }

    require_once ABS_PATH . 'config.php';

    $conn = new DBConnectionClass(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $c_db = $conn->getOsclassDb();
    $comm = new DBCommandClass($c_db);
    $rs   = $comm->query(sprintf(
        "SELECT * FROM %st_preference WHERE s_name = 'osclass_installed'",
        DB_TABLE_PREFIX
    ));

    if ($rs == false) {
        return false;
    }

    if ($rs->numRows() != 1) {
        return false;
    }

    return true;
}


/**
 * @param $password
 *
 * @return array
 */
function finish_installation($password)
{
    require_once LIB_PATH . 'osclass/helpers/hPlugins.php';

    $data = array();

    $mAdmin = new Admin();

    $mPreference = Preference::newInstance();
    $mPreference->insert(
        array(
            's_section' => 'osclass',
            's_name'    => 'osclass_installed',
            's_value'   => '1',
            'e_type'    => 'BOOLEAN'
        )
    );

    $admin = $mAdmin->findByPrimaryKey(1);

    $data['s_email']    = $admin['s_email'];
    $data['admin_user'] = $admin['s_username'];
    $data['password']   = $password;

    return $data;
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
 * @param $bool
 *
 */
function ping_search_engines($bool)
{
    $mPreference = Preference::newInstance();
    if ($bool == 1) {
        $mPreference->insert(
            array(
                's_section' => 'osclass',
                's_name'    => 'ping_search_engines',
                's_value'   => '1',
                'e_type'    => 'BOOLEAN'
            )
        );
        // GOOGLE
        Utils::doRequest('http://www.google.com/webmasters/sitemaps/ping?sitemap='
            . urlencode(osc_search_url(array('sFeed' => 'rss'))), array());
        // BING
        Utils::doRequest('http://www.bing.com/webmaster/ping.aspx?siteMap='
            . urlencode(osc_search_url(array('sFeed' => 'rss'))), array());
        // YAHOO!
        Utils::doRequest('http://search.yahooapis.com/SiteExplorerService/V1/ping?sitemap='
            . urlencode(osc_search_url(array('sFeed' => 'rss'))), array());
    } else {
        $mPreference->insert(
            array(
                's_section' => 'osclass',
                's_name'    => 'ping_search_engines',
                's_value'   => '0',
                'e_type'    => 'BOOLEAN'
            )
        );
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
    Admin::newInstance()->insert(
        array(
            's_name'     => 'Administrator',
            's_username' => $admin,
            's_password' => osc_hash_password($password),
            's_email'    => Params::getParam('email')
        )
    );

    $mPreference = Preference::newInstance();
    $mPreference->insert(
        array(
            's_section' => 'osclass',
            's_name'    => 'pageTitle',
            's_value'   => Params::getParam('webtitle'),
            'e_type'    => 'STRING'
        )
    );

    $mPreference->insert(
        array(
            's_section' => 'osclass',
            's_name'    => 'contactEmail',
            's_value'   => Params::getParam('email'),
            'e_type'    => 'STRING'
        )
    );

    $body = sprintf(__('Hi %s,'), Params::getParam('webtitle')) . '<br/>';
    $body .= sprintf(__('Your Osclass installation at %s is up and running.'
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
    $body .= __('The <a href="https://github.com/mindstellar/osclass">Osclass</a> team');

    $sitename = strtolower(Params::getServerParam('SERVER_NAME'));
    if (0 === strpos($sitename, 'www.')) {
        $sitename = substr($sitename, 4);
    }

    $mail           = new PHPMailer(true);
    $mail->CharSet  = 'utf-8';
    $mail->Host     = 'localhost';
    $mail->From     = 'osclass@' . $sitename;
    $mail->FromName = 'Osclass';
    $mail->Subject  = 'Osclass successfully installed!';
    $mail->addAddress(Params::getParam('email'), 'Osclass administrator');
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
