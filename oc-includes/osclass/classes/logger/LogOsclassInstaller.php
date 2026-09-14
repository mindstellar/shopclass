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
 * Class LogOsclassInstaller
 */
class LogOsclassInstaller extends Logger
{
    private static $instance;

    private $os;
    private $component = 'INSTALLER';

    /**
     * Records the host operating system reported with each message.
     */
    public function __construct()
    {
        $this->os = PHP_OS;
    }

    /**
     * The shared installer logger.
     *
     * @return \LogOsclassInstaller
     */
    public static function newInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Log a message with the INFO level.
     *
     * @param string      $message
     * @param string|null $caller
     *
     * @return void
     */
    public function info($message = '', $caller = null)
    {
        $this->sendOsclass('INFO', $message, $caller);
    }

    /**
     * Ship one message to the remote installer log. Currently a no-op stub.
     *
     * @param string      $type    level name, e.g. 'INFO'
     * @param string      $message
     * @param string|null $caller
     *
     * @return bool
     * @todo Creating another target to receive logs.
     */
    private function sendOsclass($type, $message, $caller)
    {
        return true;
        /** TODO
         * osc_doRequest(
         * 'http://admin.osclass.org/logger.php',
         * array(
         * 'type' => $type
         * ,'component' => $this->component
         * ,'os' => $this->os
         * ,'message' => base64_encode($message)
         * ,'fileLine' => base64_encode($caller)
         * )
         * );
         *
         */
    }

    /**
     * Log a message with the WARN level.
     *
     * @param string      $message
     * @param string|null $caller
     *
     * @return void
     */
    public function warn($message = '', $caller = null)
    {
        $this->sendOsclass('WARN', $message, $caller);
    }

    /**
     * Log a message with the ERROR level.
     *
     * @param string      $message
     * @param string|null $caller
     *
     * @return void
     */
    public function error($message = '', $caller = null)
    {
        $this->sendOsclass('ERROR', $message, $caller);
    }

    /**
     * Log a message with the DEBUG level.
     *
     * @param string      $message
     * @param string|null $caller
     *
     * @return void
     */
    public function debug($message = '', $caller = null)
    {
        $this->sendOsclass('DEBUG', $message, $caller);
    }

    /**
     * Log a message object with the FATAL level including the caller.
     *
     * @param string      $message
     * @param string|null $caller
     *
     * @return void
     */
    public function fatal($message = '', $caller = null)
    {
        $this->sendOsclass('FATAL', $message, $caller);
    }
}

/* file end: ./oc-includes/osclass/logger/LogOsclassInstaller.php */
