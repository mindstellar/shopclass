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
 * An after-commit callback that ends the request (exit, as a redirect does) does not drop the
 * callbacks queued after it: they run at shutdown, each once.
 * Usage: php tests/db-after-commit-exit.php
 */

if (($argv[1] ?? '') === 'child') {
    require_once __DIR__ . '/lib/harness.php';
    require_once __DIR__ . '/lib/api-admin-kit.php';
    api_admin_boot('osc_db_after_commit_exit');
    $out = $argv[2];
    \mindstellar\database\Db::transaction(static function () use ($out): void {
        \mindstellar\database\Db::afterCommit(static fn () => file_put_contents($out, "one\n", FILE_APPEND));
        \mindstellar\database\Db::afterCommit(static function () use ($out): void {
            file_put_contents($out, "exit\n", FILE_APPEND);
            exit(0);
        });
        \mindstellar\database\Db::afterCommit(static fn () => file_put_contents($out, "three\n", FILE_APPEND));
    });
    file_put_contents($out, "after\n", FILE_APPEND);
    exit(0);
}

require_once __DIR__ . '/lib/harness.php';

$out = tempnam(sys_get_temp_dir(), 'osc-ac-');
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($out) . ' >/dev/null 2>&1', $code);
$ran = (string) file_get_contents($out);
@unlink($out);

pin('the child ran', 0, $code);
pin('callbacks after the one that exits still run, each once', "one\nexit\nthree\n", $ran);

exit(harness_result());
