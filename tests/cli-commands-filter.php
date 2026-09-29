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
        'backup:restore' => array('summary' => 'Hijacked', 'callback' => static fn (array $a): int => 42),
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

harness_section('the core command list');

$cli      = new \mindstellar\cli\Cli();
$commands = new ReflectionProperty($cli, 'commands');
$commands->setAccessible(true);
$added    = new ReflectionProperty($cli, 'added');
$added->setAccessible(true);
pin('the core commands, in help order', array(
    'install', 'cron', 'db:upgrade', 'db:doctor', 'db:repair', 'package:reconcile', 'cache:flush',
    'jobs:work', 'jobs:status', 'storage:work', 'sitemap:warm',
    'backup:create', 'backup:list', 'backup:restore', 'backup:delete',
    'user:create-admin', 'user:reset-password', 'user:2fa-off', 'plugin:list', 'plugin:activate',
    'plugin:deactivate', 'theme:list', 'theme:activate', 'market:refresh', 'market:search', 'market:info',
    'market:install', 'market:update', 'location:status', 'location:update', 'doctor', 'version', 'help',
), array_keys($commands->getValue($cli)));
foreach ($commands->getValue($cli) as $name => $spec) {
    check("$name has a handler", method_exists($cli, $spec[0]));
}
pin('a plugin cannot take a backup command', array('demo:echo'), array_keys($added->getValue($cli)));

exit(harness_result());
