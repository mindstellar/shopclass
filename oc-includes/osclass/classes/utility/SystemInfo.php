<?php
/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\utility;

use DBConnectionClass;
use Preference;

/**
 * Get useful system info about osclass installation
 *
 */
class SystemInfo
{
    /**
     * @var string
     */
    private $osc_debug_db_explain;

    /**
     * @var string
     */
    private $osc_debug_db_log;

    /**
     * @var string
     */
    private $osc_debug_db;

    /**
     * @var string
     */
    private $osc_debug_log;

    /**
     * @var string
     */
    private $osc_debug;

    /**
     * @var string
     */
    private $osclass_themes_url;
    /**
     * @var string
     */
    private $osclass_themes_path;
    /**
     * @var string
     */
    private $osclass_plugins_url;
    /**
     * @var string
     */
    private $osclass_plugins_path;
    /**
     * @var string
     */
    private $osclass_uploads_url;
    /**
     * @var string
     */
    private $osclass_uploads_path;
    /**
     * @var string
     */
    private $osclass_content_url;
    /**
     * @var string
     */
    private $osclass_content_path;
    /**
     * @var string
     */
    private $osclass_home_url;
    /**
     * @var string
     */
    private $osclass_version;
    /**
     * @var string
     */
    private $osclass_preference_size;
    /**
     * @var int
     */
    private $osclass_preference_count;
    /**
     * @var string
     */
    private $db_table_prefix;
    /**
     * @var string
     */
    private $db_user;
    /**
     * @var string
     */
    private $db_name;
    /**
     * @var string
     */
    private $db_host;
    /**
     * @var string
     */
    private $db_serverinfo;
    /**
     * @var bool
     */
    private $php_imagick_support;
    /**
     * @var bool
     */
    private $php_gd_freetype_support;
    /**
     * @var bool
     */
    private $php_gd_support;
    /**
     * @var bool
     */
    private $php_curl_support;
    /**
     * @var false|string
     */
    private $php_post_max_size;
    /**
     * @var false|string
     */
    private $php_max_upload_size;
    /**
     * @var false|string
     */
    private $php_max_execution_time;
    /**
     * @var float
     */
    private $php_memory_usage_percent;
    /**
     * @var float
     */
    private $php_memory_usage;
    /**
     * @var false|string
     */
    private $php_memory_limit;
    /**
     * @var false|string
     */
    private $php_version;
    /**
     * @var mixed
     */
    private $php_server_software;
    /**
     * @var string
     */
    private $browser_platform;
    /**
     * @var mixed|string
     */
    private $browser_version;
    /**
     * @var string
     */
    private $browser;

    /**
     * @var string
     */
    private $php_os_architecture;
    /**
     * @var string
     */
    private $php_os;

    /**
     * @var false|string
     */
    private $allow_url_fopen;
    /**
     * @var mixed
     */
    private $browser_user_agent;

    /**
     * Return System Info as Array
     *
     * @return array
     */
    public function getSystemInfoArr()
    : array
    {
        $systemInfoArr             = array();
        $systemInfoArr['osclass']  = $this->getOsclassInfoArr();
        $systemInfoArr['php']      = $this->getPhpInfoArr();
        $systemInfoArr['database'] = $this->getDbInfoArr();
        $systemInfoArr['browser']  = $this->getBrowserInfoArr();


        return $systemInfoArr;
    }

    /**
     * Return Oslcass info in an array
     *
     * @return array
     */
    private function getOsclassInfoArr()
    : array
    {
        $this->setOsclassInfo();
        $osclassInfoArr                    = array();
        $osclassInfoArr['osclass_version'] = $this->osclass_version;

        $osclassInfoArr['preference_count'] = $this->osclass_preference_count;
        $osclassInfoArr['preference_size']  = $this->osclass_preference_size;

        $osclassInfoArr['website_url'] = $this->osclass_home_url;

        $osclassInfoArr['content_path'] = $this->osclass_content_path;
        $osclassInfoArr['content_url']  = $this->osclass_content_url;

        $osclassInfoArr['uploads_path'] = $this->osclass_uploads_path;
        $osclassInfoArr['uploads_url']  = $this->osclass_uploads_url;

        $osclassInfoArr['themes_path'] = $this->osclass_themes_path;
        $osclassInfoArr['themes_url']  = $this->osclass_themes_url;

        $osclassInfoArr['plugins_path'] = $this->osclass_plugins_path;
        $osclassInfoArr['plugins_url']  = $this->osclass_plugins_url;

        $osclassInfoArr['debug']            = $this->osc_debug;
        $osclassInfoArr['debug_log']        = $this->osc_debug_log;
        $osclassInfoArr['debug_db']         = $this->osc_debug_db;
        $osclassInfoArr['debug_db_log']     = $this->osc_debug_db_log;
        $osclassInfoArr['debug_db_explain'] = $this->osc_debug_db_explain;

        return $osclassInfoArr;
    }

    public function setOsclassInfo()
    : self
    {
        $all_preferences_serialized = serialize(Preference::newInstance()->listAll());
        $all_preference_bytes       = round(mb_strlen($all_preferences_serialized, '8bit') / 1024, 2);

        $this->osclass_preference_count = count(Preference::newInstance()->listAll());
        $this->osclass_preference_size  = $all_preference_bytes . 'KB';

        $this->osclass_version      = osc_version();
        $this->osclass_home_url     = osc_base_url();
        $this->osclass_content_path = osc_content_path();
        $this->osclass_content_url  = osc_base_url() . basename(osc_content_path());
        $this->osclass_uploads_path = osc_uploads_path();
        $this->osclass_uploads_url  = osc_base_url() . basename(osc_content_path()) . '/' . basename(osc_uploads_path());
        $this->osclass_plugins_path = osc_plugins_path();
        $this->osclass_plugins_url  = osc_base_url() . basename(osc_content_path()) . '/' . basename(osc_plugins_path());
        $this->osclass_themes_path  = osc_themes_path();
        $this->osclass_themes_url   = osc_base_url() . basename(osc_content_path()) . '/' . basename(osc_themes_path());

        if (defined('OSC_DEBUG')) {
            if (OSC_DEBUG) {
                $this->osc_debug = __('Enabled');
            } else {
                $this->osc_debug = __('Disabled');
            }
        } else {
            $this->osc_debug = __('Not set');
        }

        if (defined('OSC_DEBUG_LOG')) {
            if (OSC_DEBUG_LOG) {
                $this->osc_debug_log = __('Enabled');
            } else {
                $this->osc_debug_log = __('Disabled');
            }
        } else {
            $this->osc_debug_log = __('Not set');
        }

        if (defined('OSC_DEBUG_DB')) {
            if (OSC_DEBUG_DB) {
                $this->osc_debug_db = __('Enabled');
            } else {
                $this->osc_debug_db = __('Disabled');
            }
        } else {
            $this->osc_debug_db = __('Not set');
        }

        if (defined('OSC_DEBUG_DB_LOG')) {
            if (OSC_DEBUG_DB_LOG) {
                $this->osc_debug_db_log = __('Enabled');
            } else {
                $this->osc_debug_db_log = __('Disabled');
            }
        } else {
            $this->osc_debug_db_log = __('Not set');
        }

        if (defined('OSC_DEBUG_DB_EXPLAIN')) {
            if (OSC_DEBUG_DB_EXPLAIN) {
                $this->osc_debug_db_explain = __('Enabled');
            } else {
                $this->osc_debug_db_explain = __('Disabled');
            }
        } else {
            $this->osc_debug_db_explain = __('Not set');
        }

        return $this;
    }

    /**
     * Return Important PHP info in an array
     *
     * @return array
     */
    private function getPhpInfoArr()
    : array
    {
        $this->setPhpInfo();
        $phpInfoArr                         = array();
        $phpInfoArr['php_os']               = $this->php_os;
        $phpInfoArr['os_architecture']      = $this->php_os_architecture;
        $phpInfoArr['server_software']      = $this->php_server_software;
        $phpInfoArr['version']              = $this->php_version;
        $phpInfoArr['allow_url_fopen']      = $this->allow_url_fopen;
        $phpInfoArr['memory_limit']         = $this->php_memory_limit;
        $phpInfoArr['memory_usage']         = $this->php_memory_usage;
        $phpInfoArr['memory_usage_percent'] = $this->php_memory_usage_percent;
        $phpInfoArr['max_execution_time']   = $this->php_max_execution_time;
        $phpInfoArr['max_upload_size']      = $this->php_max_upload_size;
        $phpInfoArr['post_max_size']        = $this->php_post_max_size;
        $phpInfoArr['curl_support']         = $this->php_curl_support;
        $phpInfoArr['gd_support']           = $this->php_gd_support;
        $phpInfoArr['gd_freetype_support']  = $this->php_gd_freetype_support;
        $phpInfoArr['imagick_support']      = $this->php_imagick_support;

        return $phpInfoArr;
    }

    public function setPhpInfo()
    : self
    {
        $this->php_os                   = PHP_OS;
        $this->php_os_architecture      = php_uname('m');
        $this->php_server_software      = $_SERVER['SERVER_SOFTWARE'];
        $this->php_version              = PHP_VERSION;
        $this->allow_url_fopen          = (ini_get('allow_url_fopen')) ? 'allowed' : 'disabled';
        $this->php_memory_limit         = ini_get('memory_limit');
        $this->php_memory_usage         = round(memory_get_usage() / 1024 / 1024, 2) . 'M';
        $this->php_memory_usage_percent = round(((int)$this->php_memory_usage / (int)$this->php_memory_limit) * 100, 2) . ' %';
        $this->php_max_execution_time   = ini_get('max_execution_time');
        $this->php_max_upload_size      = ini_get('upload_max_filesize');
        $this->php_post_max_size        = ini_get('post_max_size');

        //set php curl support
        $this->php_curl_support = function_exists('curl_init');
        //php gd support
        $this->php_gd_support = extension_loaded('gd');
        //php gd freetype support
        $this->php_gd_freetype_support = extension_loaded('gd') && function_exists('imagettftext');
        //php imagick support
        $this->php_imagick_support = extension_loaded('imagick');

        return $this;
    }

    /**
     * Return Osclass Database Info in an array
     *
     * @return array
     */
    private function getDbInfoArr()
    : array
    {
        $this->setDbInfo();
        $dbInfoArr                 = array();
        $dbInfoArr['server_info']  = $this->db_serverinfo;
        $dbInfoArr['host']         = $this->db_host;
        $dbInfoArr['name']         = $this->db_name;
        $dbInfoArr['user']         = $this->db_user;
        $dbInfoArr['table_prefix'] = $this->db_table_prefix;

        return $dbInfoArr;
    }

    public function setDbInfo()
    : self
    {
        $db                    = DBConnectionClass::newInstance();
        $this->db_serverinfo   = $db->getOsclassDb()->get_server_info();
        $this->db_host         = DB_HOST;
        $this->db_name         = DB_NAME;
        $this->db_user         = DB_USER;
        $this->db_table_prefix = DB_TABLE_PREFIX;

        return $this;
    }

    /**
     * Return current browser info as array
     * It guess by analyzing user_agent string
     * Don't rely on the result.
     *
     * @return array
     */
    private function getBrowserInfoArr()
    : array
    {
        $this->setBrowserInfo();
        $browserInfoArr                      = array();
        $browserInfoArr['name']              = $this->browser;
        $browserInfoArr['version']           = $this->browser_version;
        $browserInfoArr['platform']          = $this->browser_platform;
        $browserInfoArr['user_agent_string'] = $this->browser_user_agent;

        return $browserInfoArr;
    }

    public function setBrowserInfo()
    : self
    {
        $userAgent            = $_SERVER['HTTP_USER_AGENT'];
        $browser              = array();
        $browser['browser']   = '';
        $browser['version']   = '';
        $browser['platform']  = '';
        $browser['userAgent'] = $userAgent;

        // browser
        if (preg_match('/MSIE\s([^\s|;]+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Internet Explorer';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Firefox\/(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Firefox';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Chrome\/(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Chrome';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Safari\/(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Safari';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Opera[\s|\/](\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Opera';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/OPR\/(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Opera';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Konqueror\/(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Konqueror';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Mozilla\/(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Mozilla';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Netscape(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Netscape';
            $browser['version'] = $regs[1];
        } elseif (preg_match('/Edge\/(\S+)/', $userAgent, $regs)) {
            $browser['browser'] = 'Edge';
            $browser['version'] = $regs[1];
        } else {
            $browser['browser'] = 'Other';
            $browser['version'] = '0';
        }

        // platform
        if (false !== strpos($userAgent, "Win")) {
            $browser['platform'] = 'Windows';
        } elseif (false !== strpos($userAgent, "Mac")) {
            $browser['platform'] = 'Mac';
        } elseif (false !== strpos($userAgent, "Linux")) {
            $browser['platform'] = 'Linux';
        } elseif (false !== strpos($userAgent, "X11")) {
            $browser['platform'] = 'UNIX';
        } elseif (false !== strpos($userAgent, "CYGWIN")) {
            $browser['platform'] = 'Cygwin';
        } elseif (false !== strpos($userAgent, "FreeBSD")) {
            $browser['platform'] = 'FreeBSD';
        } elseif (false !== strpos($userAgent, "Windows")) {
            $browser['platform'] = 'Windows';
        } elseif (false !== strpos($userAgent, "UNIX")) {
            $browser['platform'] = 'UNIX';
        } elseif (false !== strpos($userAgent, "Android")) {
            $browser['platform'] = 'Android';
        } elseif (false !== strpos($userAgent, "iPhone")) {
            $browser['platform'] = 'iPhone';
        } else {
            $browser['platform'] = 'Other';
        }

        $this->browser            = $browser['browser'];
        $this->browser_version    = $browser['version'];
        $this->browser_platform   = $browser['platform'];
        $this->browser_user_agent = $userAgent;

        return $this;
    }

    /**
     * get full php info as string
     *
     * @return false|string
     */
    public function getPHPInfoAllToStr()
    {
        ob_start();
        phpinfo();
        $phpinfo = ob_get_clean();
        $phpinfo = preg_replace('%^.*<body>(.*)</body>.*$%ms', '$1', $phpinfo);
        $string = "
        <style type='text/css'>
            #phpinfo {}
            #phpinfo pre {margin: 0; font-family: monospace;}
            #phpinfo a:link {color: #009; text-decoration: none; background-color: #fff;}
            #phpinfo a:hover {text-decoration: underline;}
            #phpinfo table {border-collapse: collapse; border: 0; width: 934px; box-shadow: 1px 2px 3px #ccc;}
            #phpinfo .center {text-align: center;}
            #phpinfo .center table {margin: 1em auto; text-align: left;}
            #phpinfo .center th {text-align: center !important;}
            #phpinfo td, th {font-size: .85rem; vertical-align: baseline; padding: 4px 5px;}
            #phpinfo h1 {font-size: 1.4rem;}
            #phpinfo h2 {font-size: 1.2rem;}
            #phpinfo .p {text-align: left;}
            #phpinfo .e {background-color: #ccf; width: 300px; font-weight: bold;}
            #phpinfo .h {background-color: #99c; font-weight: bold; border-radius: 4px;}
            #phpinfo .v {background-color: #ddd; max-width: 300px; overflow-x: auto; word-wrap: break-word;}
            #phpinfo .v i {color: #999;}
            #phpinfo img {float: right; border: 0;}
            #phpinfo hr {width: 934px; background-color: #ccc; border: 0; height: 1px;}
        </style>
        <div id='phpinfo'>
            $phpinfo
        </div>
        ";
        return $string;
    }
}
