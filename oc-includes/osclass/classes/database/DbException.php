<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

use RuntimeException;

/**
 * Class DbException
 *
 * Typed exception raised by the parameterized query layer (Connection). Carries
 * only a generic message; the real SQL/mysqli detail is logged server-side so it
 * never reaches an end user through display_errors.
 *
 * The exception code is the driver's error number where the failure came from
 * the driver, and 0 otherwise. A number leaks nothing on its own, and callers
 * that need to tell failures apart -- the installer distinguishes "cannot reach
 * the server" from "access denied" from "unknown database" -- map it to their
 * own wording rather than showing anything from the database.
 *
 * @package mindstellar\database
 */
class DbException extends RuntimeException
{
}

/* file end: ./oc-includes/osclass/classes/database/DbException.php */
