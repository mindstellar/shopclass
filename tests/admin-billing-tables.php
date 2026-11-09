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
 * The billing lists and small report tables are drawn by osc_admin_table(), not hand-written
 * thead blocks, and keep their hooks for styling. Reads the template sources, so it is DB-free.
 *
 * Usage:  php tests/admin-billing-tables.php
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/harness.php';

$theme = dirname(__DIR__) . '/oc-admin/themes/modern/';

// file => osc_admin_table() calls expected, strings the call must still carry.
$files = array(
    'billing/index.php'     => array(1, array("'billing-orders'", "'stack' => true")),
    'billing/documents.php' => array(1, array("'billing-documents'", "'stack' => true")),
    'billing/credits.php'   => array(1, array("'stack' => true")),
    'billing/packages.php'  => array(1, array("'stack' => true")),
    'settings/billing.php'  => array(1, array("'stack' => true")),
    'settings/sitemap.php'  => array(1, array("'stack' => true")),
    'users/frm.php'         => array(1, array("'user-alerts'", "'stack' => true", "'wrap' => 'actions'")),
    'tools/cleanup.php'     => array(1, array("min-width:30rem")),
    'tools/backup.php'      => array(1, array("'backup-list'", "backup-when")),
    'stats/users.php'       => array(1, array()),
    'stats/comments.php'    => array(1, array("col-title")),
);

foreach ($files as $file => $expect) {
    harness_section($file);
    $source = (string)file_get_contents($theme . $file);
    check('calls osc_admin_table() once', substr_count($source, 'osc_admin_table(') === $expect[0], (string)substr_count($source, 'osc_admin_table('));
    check('has no hand-written <thead', !str_contains($source, '<thead'));
    check('has no cellpadding', !str_contains($source, 'cellpadding'));
    foreach ($expect[1] as $needle) {
        check('keeps ' . $needle, str_contains($source, $needle));
    }
}

foreach (array('billing/index.php', 'billing/packages.php') as $file) {
    $source = (string)file_get_contents($theme . $file);
    $ok     = preg_match("/osc_admin_table\\(\\s*array\\(\\s*'status'|\\\$columns\\s*=\\s*array\\(\\s*'status'/", $source) === 1;
    check($file . ': the first column is status', $ok);
}

exit(harness_result());
