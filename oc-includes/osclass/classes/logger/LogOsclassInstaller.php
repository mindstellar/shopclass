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

/**
 * The installer's logger. Its messages used to go to osclass.org; they now go nowhere, and the
 * class stays so plugins that call it keep working.
 */
class LogOsclassInstaller extends Logger
{
    private static $instance;

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
    }
}

/* file end: ./oc-includes/osclass/logger/LogOsclassInstaller.php */
