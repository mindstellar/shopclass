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
 * Class logger
 */
abstract class Logger
{

    /**
     * Log a message with the INFO level.
     *
     * @param string $message
     *
     * @param null   $caller
     *
     */
    abstract public function info($message = '', $caller = null);

    /**
     * Log a message with the WARN level.
     *
     * @param string $message
     *
     * @param null   $caller
     *
     */
    abstract public function warn($message = '', $caller = null);

    /**
     * Log a message with the ERROR level.
     *
     * @param string $message
     *
     * @param null   $caller
     */
    abstract public function error($message = '', $caller = null);

    /**
     * Log a message with the DEBUG level.
     *
     * @param string $message
     * @param null   $caller
     */
    abstract public function debug($message = '', $caller = null);
}

/* file end: ./oc-includes/osclass/logger/logger.php */
