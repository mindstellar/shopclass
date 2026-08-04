<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Command-line entry point for maintenance tasks. Refuses any non-CLI SAPI so
// the router's verbs can never be reached over HTTP, even if the file is web
// served.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This endpoint is command-line only.');
}

define('CLI', true);

$cliArgv = array_slice($argv, 1);

// The `install` verb must run before the app is configured/installed, where the
// normal oc-load.php bootstrap osc_die()s. It loads an installer-style bootstrap
// that self-provisions from the environment instead; every other verb needs the
// fully booted app.
if (($cliArgv[0] ?? '') === 'install') {
    require_once __DIR__ . '/oc-includes/osclass/cli-install-bootstrap.php';
} else {
    require_once __DIR__ . '/oc-load.php';
}

require_once __DIR__ . '/oc-includes/osclass/classes/cli/Cli.php';

exit(\mindstellar\cli\Cli::run($cliArgv));
