<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Defines the constants a site sets in config.php, so PHPStan knows them. Nothing runs.
define('ABS_PATH', dirname(__DIR__, 2) . '/');
define('DB_HOST', 'localhost');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_NAME', '');
define('DB_TABLE_PREFIX', 'oc_');
define('REL_WEB_URL', '/');
define('WEB_PATH', 'http://localhost/');
define('OSC_MEMORY_LIMIT', '4096M');

require ABS_PATH . 'oc-includes/osclass/default-constants.php';
