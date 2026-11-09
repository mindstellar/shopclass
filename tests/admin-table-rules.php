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
 * Table rules for the admin templates: no hand-copied <thead> outside a short allow list, and
 * a Status column comes first (or second, after the bulk checkbox) in every helper-drawn table.
 *
 * Usage:  php tests/admin-table-rules.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$root = ABS_PATH . 'oc-admin/themes/modern/';

// Templates that may still carry their own <thead>, with the reason.
$allow = array(
    'categories/index.php'           => 'tree table',
    'items/frm.php'                  => 'variant editor',
    'settings/locations/list.php'    => 'phase 4',
    'settings/locations/data.php'    => 'phase 4',
    'settings/locations/preview.php' => 'phase 4',
    'billing/order.php'              => 'phase 4',
    'billing/wallet.php'             => 'phase 4',
    'parts/market.php'               => 'rows filled by JS',
);

$files = array();
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php' && !str_contains($f->getPathname(), '/scss/')) {
        $files[substr($f->getPathname(), strlen($root))] = (string)file_get_contents($f->getPathname());
    }
}
ksort($files);

harness_section('no copied <thead>');
$stray = array();
foreach ($files as $rel => $src) {
    if (str_contains($src, '<thead') && !isset($allow[$rel])) {
        $stray[] = $rel;
    }
}
check('no template outside the allow list has <thead', $stray === array(), implode(', ', $stray));
foreach ($allow as $rel => $why) {
    check('allow-list entry still exists and still needs it: ' . $rel . ' (' . $why . ')', isset($files[$rel]) && str_contains($files[$rel], '<thead'));
}

harness_section('status column position');
$checked = 0;
$tables  = 0;
foreach ($files as $rel => $src) {
    if (!preg_match('/osc_admin_(data_)?table\(/', $src)) {
        continue;
    }
    $tables++;
    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        if (!preg_match("/^(\s*)'status'\s*=>\s*(array\('label(_html)?'|__\()/", $line, $m)) {
            continue;
        }
        $checked++;
        $indent = strlen($m[1]);
        $before = array();
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!preg_match("/^(\s*)'([\w-]+)'\s*=>/", $lines[$j], $k)) {
                if (trim($lines[$j]) !== '' && strlen($lines[$j]) - strlen(ltrim($lines[$j])) < $indent) {
                    break;
                }
                continue;
            }
            $ind = strlen($k[1]);
            if ($ind < $indent) {
                break;
            }
            if ($ind === $indent) {
                $before[] = $k[2];
            }
        }
        check($rel . ':' . ($i + 1) . ' status is first, or second after bulkactions', $before === array() || $before === array('bulkactions'), implode(',', array_reverse($before)));
    }
}
check('the rule saw table templates', $tables >= 25, (string)$tables);
check('the rule saw status column definitions', $checked >= 7, (string)$checked);

exit(harness_result());
