<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Docker configuration. Mounted read-only over /application/config.php in the
 * php-fpm service so the container is configured entirely from the environment
 * (docker-compose), without ever touching a config.php on the host. The
 * defaults match the compose file; override them with the SHOPCLASS_DATABASE_*
 * variables.
 */

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

// The database configuration is external (environment), so the installer must
// not write a config.php.
define('OSC_CONFIG_FROM_ENV', true);

define('DB_HOST', getenv('DB_HOST') ?: 'mariadb');
define('DB_NAME', getenv('DB_NAME') ?: 'shopclass');
define('DB_USER', getenv('DB_USER') ?: 'shopclass');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'shopclass');
define('DB_TABLE_PREFIX', getenv('DB_TABLE_PREFIX') ?: 'oc_');
