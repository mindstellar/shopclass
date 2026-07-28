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

namespace mindstellar\database;

use Exception;
use mysqli;

/**
 * Manages the physical mysqli connection: opens it (with a bounded connect
 * timeout), sets the charset (utf8mb4, falling back to utf8) and sql_mode, holds
 * the singleton handle, and reports connection diagnostics.
 *
 * This is the low-level connection layer. It is NOT the query API — for queries use
 * mindstellar\database\Connection (injection-safe, parameterized), for transactions
 * and the query builder use mindstellar\database\Db. Those wrappers sit on top of the
 * handle this class hands out via getHandle().
 *
 * The legacy global name DBConnectionClass subclasses this for backward compatibility;
 * plugins that call DBConnectionClass::newInstance() keep working unchanged.
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      5.3.0
 */
class ConnectionManager
{
    /**
     * Single shared instance.
     *
     * @var ConnectionManager
     */
    private static $instance;
    /** A list of incompatible SQL modes.
     *
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
     * @var string
     */
    private $dbHost;
    /**
     * Database TCP port, or null to use the mysqli default. Resolved from a
     * host:port string, an explicit constructor argument, or the DB_PORT
     * constant.
     *
     * @var int|null
     */
    private $dbPort;
    /**
     * Database name where it's installed Shopclass
     *
     * @var string
     */
    private $dbName;
    /**
     * Database user
     *
     * @var string
     */
    private $dbUser;
    /**
     * Database user password
     *
     * @var string
     */
    private $dbPassword;
    /**
     * Database connection object to Shopclass database
     *
     * @var mysqli
     */
    private $connId;

    /**
     * Database error number
     *
     * @var int
     */
    private $errorLevel = 0;
    /**
     * Database error description
     *
     * @var string
     */
    private $errorDesc = '';
    /**
     * Database connection error number
     *
     * @var int
     */
    private $connErrorLevel = 0;
    /**
     * Database connection error description
     *
     * @var string
     */
    private $connErrorDesc = '';

    /**
     * Initialize database connection
     *
     * @param string   $server   Host where the MySQL server lives. May include a
     *                            port as "host:port" (what users type in the
     *                            installer's Host field).
     * @param string   $user     MySQL user name
     * @param string   $password MySQL password
     * @param string   $database Default database to be used when performing queries
     * @param int|null $port     Explicit TCP port; overrides a host:port string.
     *                           Falls back to the DB_PORT constant, then the
     *                           mysqli default.
     */
    public function __construct(
        $server = DB_HOST,
        $user = DB_USER,
        $password = DB_PASSWORD,
        $database = DB_NAME,
        $port = null
    ) {
        // Accept a "host:port" server string (a single colon, so IPv6 literals
        // are left untouched). An explicit $port argument wins over that, and a
        // DB_PORT constant is the last fallback before the mysqli default.
        // Note: with host 'localhost' mysqli uses a socket and ignores the port;
        // use 127.0.0.1 to force TCP on a custom port.
        if (substr_count((string)$server, ':') === 1 && preg_match('/^(.+):(\d+)$/', (string)$server, $m)) {
            $server         = $m[1];
            $this->dbPort   = (int)$m[2];
        }
        if ($port !== null && $port !== '') {
            $this->dbPort = (int)$port;
        }
        if ($this->dbPort === null && defined('DB_PORT') && DB_PORT !== '') {
            $this->dbPort = (int)DB_PORT;
        }

        $this->dbHost     = $server;
        $this->dbName     = $database;
        $this->dbUser     = $user;
        $this->dbPassword = $password;
        $this->connectToOsclassDb();
    }

    /**
     * Connect to Shopclass database
     *
     * @return boolean It returns true if the connection has been successful or false if not
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

        $this->setCharset('utf8mb4');


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
        static $reportSet = false;
        if (!$reportSet) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $reportSet = true;
        }
        try {
            $this->connId = mysqli_init();
            if (!$this->connId instanceof mysqli) {
                return false;
            }
            // Bound the TCP connect so a black-holed database host cannot tie up
            // a PHP worker indefinitely (this matters most for the installer's
            // "Test connection" probe, which dials an arbitrary host:port).
            $this->connId->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
            $this->connId->real_connect(
                $this->dbHost,
                $this->dbUser,
                $this->dbPassword,
                '',
                $this->dbPort !== null ? $this->dbPort : 0
            );
        } catch (Exception $e) {
            $this->errorDesc = $e->getMessage();
            $this->errorLevel = $e->getCode();
            $this->connErrorLevel = $e->getCode();
            $this->connErrorDesc = $e->getMessage();
            // Never keep a half-initialized handle around: mysqli_init()
            // succeeded but real_connect() failed, so getHandle() must return
            // null (as it did when a failed `new mysqli` threw) rather than an
            // object that fatals with "mysqli object is not fully initialized".
            $this->connId = null;
            return false;
        }

        // Check for connection errors
        if ($this->connId->connect_errno) {
            $this->errorConnection();
            $this->errorReport();  // Set error information
            $this->connId = null;
            return false;
        }
        $this->setSQLMode();

        return true;
    }

    /**
     * Set connection error num error and connection error description
     */
    private function errorConnection()
    {

        $this->connErrorLevel = $this->connId->connect_errno;
        $this->connErrorDesc  = $this->connId->connect_error;
    }

    /**
     * Set sql_mode
     *
     * By default this reads the server's session sql_mode and strips a list of
     * strict modes ($incompatible_modes: NO_ZERO_DATE, ONLY_FULL_GROUP_BY,
     * STRICT_TRANS_TABLES, STRICT_ALL_TABLES, TRADITIONAL) before re-applying the
     * reduced mode, loosening the connection to tolerate zero dates, over-length
     * inserts and non-aggregated GROUP BY.
     *
     * Define the optional constant OSC_DB_STRICT_MODE (truthy) to opt out of that
     * loosening: when set, the server's own sql_mode is left exactly as configured
     * (early return, connection untouched) so the server's modern strict defaults
     * stand. It defaults OFF, so every install that does not define it keeps the
     * historic behaviour byte-for-byte.
     *
     * Before enabling, operators should audit for runtime risks: INSERT/UPDATE
     * truncation from over-length or out-of-range values, and non-aggregated
     * columns in GROUP BY queries. The bundled schema (struct.sql) carries no
     * '0000-00-00' zero-date defaults, so it is strict-safe on its own; the risk
     * is in runtime data and queries, not the schema.
     *
     * @param array $modes
     */
    private function setSQLMode($modes = [])
    {
        if (defined('OSC_DB_STRICT_MODE') && OSC_DB_STRICT_MODE) {
            return;
        }
        if (empty($modes)) {
            try {
                $res = $this->connId->query('SELECT @@SESSION.sql_mode');
            } catch (Exception $e) {
                $this->errorReport();

                return;
            }

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
        try {
            $this->connId->query("SET SESSION sql_mode='$modes_str'");
        } catch (Exception $e) {
            $this->errorReport();
        }
    }

    /**
     * Release the database connection
     * Return true on success and false on failure
     *
     * @return boolean
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
        // During installation the installer renders its own database errors, so
        // return and let the caller handle the failure inline.
        if (defined('OSC_INSTALLING')) {
            return;
        }

        // Otherwise the site is up but can't reach its database. Show a clean,
        // useful "database unavailable" page (and log it) instead of letting a
        // later query fatal with a raw error. The real driver error is passed
        // as debug-only detail.
        if (class_exists('\\mindstellar\\logger\\OsclassErrors')) {
            $detail = $this->connErrorDesc ?: ($this->errorDesc ?: $message);
            \mindstellar\logger\OsclassErrors::newInstance()->renderDbError((string)$detail);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(503);
        }
        echo 'The site is temporarily unable to reach its database. Please try again shortly.';
        exit(1);
    }

    /**
     * Set charset of the database passed per parameter.
     *
     * Attempts the requested charset and, if that fails, falls back to plain
     * 'utf8' so a server without utf8mb4 support still connects. Non-fatal: any
     * failure is reported but never aborts the connection.
     *
     * @param string $charset The charset to be set
     */
    private function setCharset($charset)
    {
        try {
            if ($this->connId->set_charset($charset) === false && $charset !== 'utf8') {
                $this->connId->set_charset('utf8');
            }
        } catch (Exception $e) {
            $this->errorReport();
            if ($charset !== 'utf8') {
                try {
                    $this->connId->set_charset('utf8');
                } catch (Exception $e2) {
                    $this->errorReport();
                }
            }
        }
    }

    /**
     * Select Database set as $this->dbName
     *
     * @return boolean It returns true if the database has been selected successfully or false if not
     */
    private function selectDb()
    {
        if ($this->connId->connect_errno) {
            return false;
        }

        try {
            return $this->connId->select_db($this->dbName);
        } catch (Exception $e) {
            $this->errorReport();

            return false;
        }
    }

    /**
     * Return the shared connection manager, creating it on first use.
     *
     * The shared instance is a DBConnectionClass (the compat subclass) so that a
     * single physical connection backs both entry points: new code calling
     * ConnectionManager::newInstance() and legacy/plugin code calling
     * DBConnectionClass::newInstance() get the same object. Instantiating the
     * subclass here is the one deliberate parent-knows-child coupling that keeps
     * the old public name a first-class, fully-working alias on one connection.
     *
     * @param string   $server   Host name where it's located the mysql server
     * @param string   $user     MySQL user name
     * @param string   $password MySQL password
     * @param string   $database Default database to be used when performing queries
     * @param int|null $port
     *
     * @return ConnectionManager
     */
    public static function newInstance(
        $server = DB_HOST,
        $user = DB_USER,
        $password = DB_PASSWORD,
        $database = DB_NAME,
        $port = null
    ) {
        if (!self::$instance instanceof self) {
            self::$instance = new \DBConnectionClass($server, $user, $password, $database, $port);
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
     */
    private function debug($printFrontend = true)
    {
        $log = \LogDatabase::newInstance();

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
     * @return int
     */
    public function getErrorConnectionLevel()
    {
        return $this->connErrorLevel;
    }

    /**
     * Return the mysqli connection error description
     *
     * @return string
     */
    public function getErrorConnectionDesc()
    {
        return $this->connErrorDesc;
    }

    /**
     * Return the mysqli error number
     *
     * @return int
     */
    public function getErrorLevel()
    {
        return $this->errorLevel;
    }

    /**
     * Return the mysqli error description
     *
     * @return string
     */
    public function getErrorDesc()
    {
        return $this->errorDesc;
    }

    /**
     * The raw mysqli handle, or false when no connection is established.
     *
     * This is the sanctioned low-level accessor for the query wrappers
     * (mindstellar\database\Connection / Db) and for driver-level operations they
     * do not cover. Application code building queries should use those wrappers
     * rather than the handle directly.
     *
     * @return mysqli|false
     */
    public function getHandle()
    {
        if ($this->connId) {
            return $this->connId;
        }

        return false;
    }

    /**
     * It reconnects to Shopclass database. First, it releases the database link connection and it connects again
     */
    private function reconnectOsclassDb()
    {
        $this->releaseDb();
        $this->connectToOsclassDb();
    }
}

/* file end: ./oc-includes/osclass/classes/database/ConnectionManager.php */
