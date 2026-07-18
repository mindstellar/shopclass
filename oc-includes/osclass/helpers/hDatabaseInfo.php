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
 * Helper Database Info
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

use mindstellar\utility\Deprecate;

/**
 * Gets database name
 *
 * @return string
 * @deprecated 4.0.0
 */
function osc_db_name()
{
    Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'DB_NAME constant');

    return DB_NAME;
}


/**
 * Gets database host
 *
 * @return string
 * @deprecated 4.0.0
 */
function osc_db_host()
{
    Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'DB_HOST constant');

    return DB_HOST;
}


/**
 * Gets database user
 *
 * @return string
 * @deprecated 4.0.0
 */
function osc_db_user()
{
    Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'DB_USER constant');

    return DB_USER;
}


/**
 * Gets database password
 *
 * @return string
 * @deprecated 4.0.0
 */
function osc_db_password()
{
    Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'DB_PASSWORD constant');

    return DB_PASSWORD;
}
