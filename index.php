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

define('CLI', PHP_SAPI === 'cli');

require_once __DIR__ . '/oc-load.php';

// The ?page= routes live in mindstellar\routing\PageRoutes.
\mindstellar\routing\FrontController::run(CLI);

/* file end: ./index.php */
