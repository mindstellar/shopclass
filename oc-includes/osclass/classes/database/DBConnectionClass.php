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
 * Database connection object
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      2.3
 */
class DBConnectionClass
{
    /**
     * DBConnectionClass should be instanced one, so it's DBConnectionClass object is set
     *
     * @access private
     * @since  2.3
     * @var DBConnectionClass
     */
    private static $instance;
    /** A list of incompatible SQL modes.
     *
     * @since  2.3
     * @access protected
     * @var array
     */
    protected $incompatible_modes = array(
        'NO_ZERO_DATE',
        'ONLY_FULL_GROUP_BY',
        'STRICT_TRANS_TABLES',
        'STRICT_ALL_TABLES',
        'TRADITIONAL'
    );
    /**
     * Host name or IP address where it is located the database
     *
     * @access private
     * @since  2.3
     * @var string
     */
    private $dbHost;
    /**
     * Database name where it's installed Shopclass
     *
     * @access private
     * @since  2.3
     * @var string
     */
    private $dbName;
    /**
     * Database user
     *
     * @access private
     * @since  2.3
     * @var string
     */
    private $dbUser;
    /**
     * Database user password
     *
     * @access private
     * @since  2.3
     * @var string
     */
    private $dbPassword;
    /**
     * Database connection object to Shopclass database
     *
     * @access private
     * @since  2.3
     * @var mysqli
     */
    private $connId;

    /**
     * Database error number
     *
     * @access private
     * @since  2.3
     * @var int
     */
    private $errorLevel = 0;
    /**
     * Database error description
     *
     * @access private
     * @since  2.3
     * @var string
     */
    private $errorDesc = '';
    /**
     * Database connection error number
     *
     * @access private
     * @since  2.3
     * @var int
     */
    private $connErrorLevel = 0;
    /**
     * Database connection error description
     *
     * @access private
     * @since  2.3
     * @var string
     */
    private $connErrorDesc = 0;

    /**
     * Initialize database connection
     *
     * @param string $server   Host name where it's located the mysql server
     * @param string $user     MySQL user name
     * @param string $password MySQL password
     * @param string $database Default database to be used when performing queries
     */
    public function __construct($server = DB_HOST, $user = DB_USER, $password = DB_PASSWORD, $database = DB_NAME)
    {
        $this->dbHost     = $server;
        $this->dbName     = $database;
        $this->dbUser     = $user;
        $this->dbPassword = $password;
        $this->connectToOsclassDb();
    }

    /**
     * Connect to Shopclass database
     *
     * @access public
     * @return boolean It returns true if the connection has been successful or false if not
     * @since  2.3
     */
    public function connectToOsclassDb()
    {
        $conn = $this->connectToDb();

        if ($conn === false) {
            $this->handleDbError(
                'Shopclass &raquo; Error',
                'Shopclass database server is not available. <a href="https://github.com/mindstellar/shopclass/discussions">Need more help?</a></p>'
            );
            return false;
        }

        $this->setCharset('utf8');


        if (!$this->dbName) {
            return true;
        }

        $selectDb = $this->selectDb();
        if ($selectDb === false) {
            $this->errorReport();
            $this->releaseDb();
            $this->handleDbError(
                'Shopclass &raquo; Error',
                'Shopclass database is not available. <a href="https://github.com/mindstellar/shopclass/discussions">Need more help?</a></p>'
            );
        }

        return true;
    }

    /**
     * Connect to the database
     *
     * @return boolean It returns true if the connection
     */
    private function connectToDb()
    {
        try {
            $this->connId = new mysqli($this->dbHost, $this->dbUser, $this->dbPassword);
        } catch (Exception $e) {
            $this->errorDesc = $e->getMessage();
            $this->errorLevel = $e->getCode();
            $this->connErrorLevel = $e->getCode();
            $this->connErrorDesc = $e->getMessage();
            return false;
        }

        // Check for connection errors
        if ($this->connId->connect_errno) {
            $this->errorConnection();
            $this->errorReport();  // Set error information
            return false;
        }
        $this->setSQLMode();

        return true;
    }

    /**
     * Set connection error num error and connection error description
     *
     * @access private
     * @since  2.3
     */
    private function errorConnection()
    {

        $this->connErrorLevel = $this->connId->connect_errno;
        $this->connErrorDesc  = $this->connId->connect_error;
    }

    /**
     * Set sql_mode
     *
     * @param array $modes
     */
    private function setSQLMode($modes = [])
    {
        if (empty($modes)) {
            $res = $this->connId->query('SELECT @@SESSION.sql_mode');

            if (empty($res)) {
                return;
            }

            $modes_array = $res->fetch_array();
            if (empty($modes_array[0])) {
                return;
            }
            $modes_str = $modes_array[0];

            if (empty($modes_str)) {
                return;
            }

            $modes = explode(',', $modes_str);
        }

        $modes              = array_change_key_case($modes, CASE_UPPER);
        $incompatible_modes = $this->incompatible_modes;
        foreach ($modes as $i => $mode) {
            if (in_array($mode, $incompatible_modes)) {
                unset($modes[$i]);
            }
        }

        $modes_str = implode(',', $modes);
        $this->connId->query("SET SESSION sql_mode='$modes_str'");
    }

    /**
     * Release the database connection
     * Return true on success and false on failure
     *
     * @access private
     * @return boolean
     * @since  2.3
     */
    private function releaseDb()
    {
        if (!$this->connId) {
            return true;
        }
        $release = $this->connId->close();
        if (!$release) {
            $this->errorReport();
        }

        return $release;
    }

    /**
     * Set error num error and error description
     *
     * @access private
     * @since  2.3
     */
    public function errorReport()
    {

        $this->errorLevel = $this->connId->errno;
        $this->errorDesc  = $this->connId->error;
    }

    /**
     * This handle database error and show error page with given title,message.
     *
     * @param $title
     * @param $message
     */
    private function handleDbError($title, $message)
    {
        if (defined('OSC_INSTALLING') && OSC_INSTALLING !== 1) {
            osc_die($title, $message);
        }
    }

    /**
     * Set charset of the database passed per parameter
     *
     * @param string $charset The charset to be set
     * @param mysqli $connId  Database link connector
     *
     * @since  2.3
     * @access private
     */
    private function setCharset($charset)
    {
        $this->connId->set_charset($charset);
    }

    /**
     * Select Database set as $this->dbName
     *
     * @access private
     * @return boolean It returns true if the database has been selected successfully or false if not
     * @since  2.3
     */
    private function selectDb()
    {
        if ($this->connId->connect_errno) {
            return false;
        }

        return $this->connId->select_db($this->dbName);
    }

    /**
     * It creates a new DBConnection object class or if it has been created before, it
     * returns the previous object
     *
     * @access public
     *
     * @param string $server   Host name where it's located the mysql server
     * @param string $user     MySQL user name
     * @param string $password MySQL password
     * @param string $database Default database to be used when performing queries
     *
     * @return DBConnectionClass
     * @since  2.3
     */
    public static function newInstance($server = DB_HOST, $user = DB_USER, $password = DB_PASSWORD, $database = DB_NAME)
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self($server, $user, $password, $database);
        }

        return self::$instance;
    }

    /**
     * Connection destructor and print debug
     */
    public function __destruct()
    {
        if (function_exists('osc_is_admin_user_logged_in') && (OSC_DEBUG_DB && osc_is_admin_user_logged_in())) {
            $this->debug();
        }
    }

    /**
     * Prints the database debug if it's necessary
     *
     * @param bool $printFrontend
     *
     * @return bool
     * @since  2.3
     * @access private
     *
     */
    private function debug($printFrontend = true)
    {
        $log = LogDatabase::newInstance();

        if (OSC_DEBUG_DB_EXPLAIN) {
            $log->writeExplainMessages();
        }

        if (!OSC_DEBUG_DB) {
            return false;
        }

        if (defined('IS_AJAX') && !OSC_DEBUG_DB_LOG) {
            return false;
        }

        if (OSC_DEBUG_DB_LOG) {
            $log->writeMessages();
        } elseif ($printFrontend) {
            $log->printMessages();
        } else {
            return false;
        }

        unset($log);

        return true;
    }

    /**
     * Return the mysqli connection error number
     *
     * @access public
     * @return int
     * @since  2.3
     */
    public function getErrorConnectionLevel()
    {
        return $this->connErrorLevel;
    }

    /**
     * Return the mysqli connection error description
     *
     * @access public
     * @return string
     * @since  2.3
     */
    public function getErrorConnectionDesc()
    {
        return $this->connErrorDesc;
    }

    /**
     * Return the mysqli error number
     *
     * @access public
     * @return int
     * @since  2.3
     */
    public function getErrorLevel()
    {
        return $this->errorLevel;
    }

    /**
     * Return the mysqli error description
     *
     * @access public
     * @return string
     * @since  2.3
     */
    public function getErrorDesc()
    {
        return $this->errorDesc;
    }

    /**
     * Placeholder method for compatibility
     *
     * @sugession use getDb() method
     * @access    public
     * @since     2.3
     */
    public function getOsclassDb()
    {
        if ($this->connId) {
            return $this->connId;
        }

        return false;
    }

    /**
     * It reconnects to Shopclass database. First, it releases the database link connection and it connects again
     *
     * @access private
     * @since  2.3
     */
    private function reconnectOsclassDb()
    {
        $this->releaseDb();
        $this->connectToOsclassDb();
    }
}

/* file end: ./oc-includes/osclass/classes/database/DBConnectionClass.php */
