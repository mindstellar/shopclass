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
 * After an update replaces the files, the database step runs in a new request or process.
 * The old one still holds the old class map, which points at files that may be gone.
 *
 * Usage: php tests/upgrade-db-own-request.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

$body = static function (string $class, string $method): string {
    $m     = new ReflectionMethod($class, $method);
    $lines = file((string) $m->getFileName());

    return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
};

$ajax = $body(mindstellar\admin\ajax\UpdateAjax::class, 'replaceFiles');
pin('the admin update replaces the files', true, str_contains($ajax, '->doUpgrade()'));
pin('the admin update does not run the database step', false, str_contains($ajax, 'upgradeDB'));
pin('the admin update sends the screen to the database page', true, str_contains($ajax, "'?page=upgrade'"));
foreach (['upgrade', 'reinstall'] as $action) {
    pin("$action goes through replaceFiles", true, str_contains($body(mindstellar\admin\ajax\UpdateAjax::class, $action), 'self::replaceFiles('));
}

$screen = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/upgrade.php');
pin('the update screen follows next', true, str_contains($screen, 'window.location.assign(json.next)'));

$dbScreen = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/upgrade/index.php');
pin('the database page shows the new version in the footer', true, (bool) preg_match(
    "/if \\(!failed && data\\.version\\) \\{[^}]*querySelector\\('\\.admin-footer-version strong'\\)[^}]*\\{ footerVersion\\.textContent = 'Shopclass ' \\+ data\\.version; \\}/",
    $dbScreen
));
$cli = $body(mindstellar\cli\Cli::class, 'cmdCoreUpdate');
pin('core:update does not migrate in its own process', false, str_contains($cli, 'cmdDbUpgrade('));
pin('core:update starts db:upgrade as a new process', true, str_contains($cli, "runChild(['db:upgrade'])"));

exit(harness_result());
