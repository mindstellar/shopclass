<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * The API and system-info tables go through osc_admin_table(): no hand-written header, and the
 * status column comes first where a table has one.
 */

$base  = dirname(__DIR__) . '/oc-admin/themes/modern/';
$files = array(
    'settings/api.php'                => 3,
    'tools/system-info/database.php'  => 3,
    'tools/system-info/jobs.php'      => 3,
    'tools/system-info/security.php'  => 1,
);

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $fail += $ok ? 0 : 1;
};

foreach ($files as $file => $tables) {
    $src = (string) file_get_contents($base . $file);
    $check(!str_contains($src, '<thead'), "$file has no <thead");
    $check(!str_contains($src, '<table'), "$file has no <table");
    $check(substr_count($src, 'osc_admin_table(') === $tables, "$file calls osc_admin_table() $tables times");
}

$api = (string) file_get_contents($base . 'settings/api.php');
foreach (array('api-keys-table', 'api-webhooks-table', 'api-webhook-deliveries') as $class) {
    $check(str_contains($api, "'class' => '$class'"), "api.php keeps $class");
}
$check(substr_count($api, "'stack' => true") === 3, 'api.php tables stay stacked on phones');
$check(substr_count($api, "'wrap' => 'actions'") === 2, 'api keys and webhooks keep the actions wrapper');
$check(str_contains($api, "'status'    => array('label' => __('Status'), 'class' => 'col-status')"), 'api keys: status first');

exit($fail === 0 ? 0 : 1);
