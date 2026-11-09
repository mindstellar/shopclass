<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * System info, upgrade, backup, locations tool and API screens sit in the shared body, and the
 * old per-screen margins are gone from their stylesheets.
 *
 * Usage:  php tests/admin-info-structure.php
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/oc-admin/themes/modern/';
$read = static fn (string $f): string => (string)file_get_contents($root . $f);

$fail  = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $label . "\n";
    $fail += $ok ? 0 : 1;
};

$files = array_merge(
    array('tools/system-info.php', 'tools/upgrade.php', 'tools/backup.php', 'tools/locations.php', 'upgrade/index.php', 'settings/api.php'),
    array_map(static fn (string $p): string => 'tools/system-info/' . basename($p), glob($root . 'tools/system-info/*.php') ?: array())
);

foreach ($files as $f) {
    $s = $read($f);
    $check(!str_contains($s, 'sysinfo-group') && !str_contains($s, 'whatsnew-heading'), "$f has no sysinfo-group or whatsnew-heading");
}

foreach (array('tools/system-info.php', 'tools/upgrade.php', 'tools/backup.php', 'tools/locations.php', 'upgrade/index.php', 'settings/api.php') as $f) {
    $s = $read($f);
    $check(substr_count($s, 'osc_admin_body_open(') === 1 && substr_count($s, 'osc_admin_body_close(') === 1, "$f opens and closes one body");
}

$check(str_contains($read('upgrade/index.php'), "'id' => 'backup-settings'"), 'upgrade body keeps id backup-settings');
$check(str_contains($read('upgrade/index.php'), "'class'      => 'whatsnew'"), "What's new is a section");
$check(!str_contains($read('settings/api.php'), 'osc_admin_form_section('), 'api Deliveries is a section');

$css = $read('scss/pages-system-info.scss') . $read('scss/pages-upgrade.scss') . $read('scss/pages-backup.scss');
$check(substr_count($css, 'var(--osc-read-width)') === 2, 'reading width comes from the token, once per screen');
$check(!str_contains($css, '56rem'), 'no hard-coded reading width');
$check(!preg_match('/\.sysinfo-aside \{[^}]*margin/', $css), '.sysinfo-aside has no margin');
$check(!preg_match('/\.sysinfo-details \{[^}]*margin-block-start/', $css), '.sysinfo-details has no margin');
$check(!str_contains($css, '.sysinfo-help-group + .sysinfo-help-group'), 'help group divider uses :not(:first-child)');
$check(!preg_match('/\.backup-callout \{[^}]*margin/', $css), '.backup-callout has no margin');
$check(!preg_match('/\.whatsnew \{[^}]*margin/', $css) && !preg_match('/\.upgrade-running \{[^}]*margin/', $css), 'whatsnew and upgrade-running have no margin');
$check(!str_contains($css, '.osc-verdict'), 'upgrade-tool verdict margin rule is gone');

exit($fail === 0 ? 0 : 1);
