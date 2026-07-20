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
 * @package mindstellar\database
 */
class DbException extends RuntimeException
{
}

/* file end: ./oc-includes/osclass/classes/database/DbException.php */
