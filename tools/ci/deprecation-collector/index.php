<?php
/*
Plugin Name: Deprecation Collector
Plugin URI: https://github.com/mindstellar/shopclass
Description: CI-only instrumentation plugin. Records every deprecated function, hook/filter, and file core fires during a smoke-install run.
Version: 1.0.0
Author: Mindstellar
Author URI: https://mindstellar.com
Short Name: deprecation-collector
*/

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Runtime half of the deprecated-API check (docs/MARKET.md §6.3). `Deprecate`
 * already fires `d_function_run`, `d_hook_run`, and `d_file_included` every time
 * a deprecated symbol is used (oc-includes/osclass/classes/utility/Deprecate.php)
 * — this plugin only listens and records, it adds no instrumentation of its own.
 *
 * tools/ci/smoke-install.sh installs and enables this alongside the package under
 * test, then folds the log it writes into the same warning annotations the
 * static scan (tools/ci/deprecation-scan.php) produces, so a call core cannot see
 * statically (behind a variable, built dynamically) is still caught.
 *
 * Never installed on a real site — it exists only inside the smoke-install
 * container for the lifetime of one CI run.
 */

/**
 * Where the collector appends its findings, one JSON object per line. Overridable
 * so the harness driving the container can point it at a path it will read back
 * after the run; defaults under oc-content/downloads, which core already keeps
 * writable at runtime.
 *
 * @return string
 */
function deprecation_collector_log_path()
{
    $override = getenv('OSC_DEPRECATION_LOG');
    if ($override !== false && $override !== '') {
        return $override;
    }

    if (defined('CONTENT_PATH')) {
        return CONTENT_PATH . 'downloads/deprecation-log.jsonl';
    }

    return sys_get_temp_dir() . '/deprecation-log.jsonl';
}

/**
 * Walks a backtrace looking for the first frame that belongs to the package under
 * test rather than to core itself, or to this collector. Every hop through
 * `Plugins::runHook()` and the `Deprecate` helpers lives under oc-includes/,
 * including the deprecated symbol's own declaration (e.g. a `@deprecated`
 * function's body) — so skipping every oc-includes/ frame, regardless of how many
 * private helpers are involved, lands on the plugin or theme file that actually
 * triggered the deprecated call. The innermost frame or two are this collector's
 * own functions (the site of the `debug_backtrace()` call, and the hook callback
 * that invoked it), which do not live under oc-includes/ either — those are
 * skipped explicitly by file identity so they cannot be mistaken for the caller.
 * Falls back to the deepest frame available when nothing else appears
 * (deprecated core calling deprecated core, unrelated to any package).
 *
 * @param array<int, array<string, mixed>> $backtrace
 *
 * @return array{file: ?string, line: ?int}
 */
function deprecation_collector_find_caller(array $backtrace)
{
    $last = ['file' => null, 'line' => null];

    foreach ($backtrace as $frame) {
        if (!isset($frame['file'])) {
            continue;
        }
        $last = ['file' => $frame['file'], 'line' => $frame['line'] ?? null];
        $normalised = str_replace('\\', '/', $frame['file']);
        if (strpos($normalised, '/oc-includes/') === false && $frame['file'] !== __FILE__) {
            return $last;
        }
    }

    return $last;
}

/**
 * Appends one finding as a JSON line. Failures to write are swallowed — a full
 * disk or unwritable path must not turn CI instrumentation into a fatal error in
 * the very install run it is supposed to be observing.
 *
 * @param string      $kind
 * @param string      $symbol
 * @param string|null $version
 * @param string|null $replacement
 * @param string|null $message
 *
 * @return void
 */
function deprecation_collector_record($kind, $symbol, $version, $replacement, $message)
{
    $caller = deprecation_collector_find_caller(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    $since = $version !== '' ? $version : null;
    $replacement = ($replacement !== null && $replacement !== '') ? $replacement : null;

    if ($message === null || $message === '') {
        $message = sprintf('`%s` is deprecated', $symbol) . ($since ? " since {$since}" : '') . ' (seen at runtime during smoke install).'
            . ($replacement ? " Use `{$replacement}` instead." : '');
    }

    $line = json_encode([
        'kind'        => $kind,
        'symbol'      => $symbol,
        'since'       => $since,
        'replacement' => $replacement,
        'message'     => $message,
        'file'        => $caller['file'],
        'line'        => $caller['line'],
    ], JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        return;
    }

    @file_put_contents(deprecation_collector_log_path(), $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * @param string      $function
 * @param string|null $replacement
 * @param string      $version
 *
 * @return void
 */
function deprecation_collector_on_function_run($function, $replacement, $version)
{
    deprecation_collector_record('function', $function, $version, $replacement, null);
}

/**
 * @param string      $hook
 * @param string|null $replacement
 * @param string      $version
 * @param string|null $message
 *
 * @return void
 */
function deprecation_collector_on_hook_run($hook, $replacement, $version, $message)
{
    deprecation_collector_record('hook', $hook, $version, $replacement, $message);
}

/**
 * @param string      $file
 * @param string|null $replacement
 * @param string      $version
 * @param string      $message
 *
 * @return void
 */
function deprecation_collector_on_file_included($file, $replacement, $version, $message)
{
    deprecation_collector_record('file', $file, $version, $replacement, $message);
}

/**
 * Install callback: nothing to provision, this plugin owns no tables or
 * preferences.
 *
 * @return void
 */
function deprecation_collector_install()
{
}

osc_register_plugin(osc_plugin_path(__FILE__), 'deprecation_collector_install');

osc_add_hook('d_function_run', 'deprecation_collector_on_function_run');
osc_add_hook('d_hook_run', 'deprecation_collector_on_hook_run');
osc_add_hook('d_file_included', 'deprecation_collector_on_file_included');
