<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the `cli_commands` filter: a plugin adds its own oc-cli.php commands, is handed the
 * parsed options, returns the exit code, and cannot take over a core command's name.
 *
 * DB-free.  Usage:  php tests/cli-commands-filter.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '9.9.9');

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['__seen'] = null;

function osc_apply_filter($name, $value = null, ...$args)
{
    if ($name !== 'cli_commands') {
        return $value;
    }

    return $value + array(
        'demo:echo'  => array(
            'summary'  => 'Echo the options back',
            'callback' => static function (array $args): int {
                $GLOBALS['__seen'] = $args;

                return 7;
            },
        ),
        // A core name: must not replace the core command.
        'version'    => array('summary' => 'Hijacked', 'callback' => static fn (array $a): int => 42),
        'demo:broken' => array('summary' => 'No callback'),
        'demo:string' => 'not an array',
    );
}

function osc_version()
{
    return OSCLASS_VERSION;
}

require_once ABS_PATH . 'oc-includes/osclass/classes/cli/Cli.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** Run the CLI and hand back its exit code and what it printed. */
function cli(array $argv): array
{
    ob_start();
    $code = \mindstellar\cli\Cli::run($argv);

    return array($code, ob_get_clean());
}

harness_section('a plugin command');

[$code] = cli(array('demo:echo', '--source=3', '--dry-run', 'extra'));
pin('runs and its return value is the exit code', 7, $code);
pin(
    'it is handed the parsed options',
    array('source' => '3', 'dry-run' => true, '_' => array('extra')),
    $GLOBALS['__seen']
);

harness_section('what a plugin cannot do');

// cmdVersion writes to STDOUT directly, so only the exit code is observable here.
[$code] = cli(array('version'));
pin('a core command keeps its own handler', 0, $code);
[$code] = cli(array('demo:broken'));
pin('an entry with no callback is not a command', 2, $code);
[$code] = cli(array('demo:string'));
pin('an entry that is not an array is not a command', 2, $code);

exit(harness_result());
