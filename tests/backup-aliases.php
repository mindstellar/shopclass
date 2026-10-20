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
 * The backup and billing classes shipped under their old names stay reachable for plugins.
 *
 * DB-free.  Usage:  php tests/backup-aliases.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/compatibility.php';

use mindstellar\backup\BackupException;
use mindstellar\backup\BackupService;

pin('BackupManager is BackupService', BackupService::class, (new ReflectionClass('mindstellar\backup\BackupManager'))->getName());
pin('BackupFailure is BackupException', BackupException::class, (new ReflectionClass('mindstellar\backup\BackupFailure'))->getName());
pin('a catch on the old name still catches', true, (static function (): bool {
    try {
        throw new BackupException('x', 'test');
    } catch (\mindstellar\backup\BackupFailure $e) {
        return true;
    }
})());

foreach (array(
    'Packages'     => 'PackageStore',
    'Orders'       => 'OrderStore',
    'Entitlements' => 'EntitlementStore',
    'ItemUpgrades' => 'ItemUpgradeStore',
) as $old => $new) {
    pin("billing\\$old exists", true, class_exists('mindstellar\\billing\\' . $old));
    pin("billing\\$old is $new", 'mindstellar\\billing\\' . $new, (new ReflectionClass('mindstellar\\billing\\' . $old))->getName());
}

foreach (array('FormService', 'FieldValidator', 'FormContextRegistry') as $name) {
    pin("forms\\$name is form\\builder\\$name", 'mindstellar\\form\\builder\\' . $name, (new ReflectionClass('mindstellar\\forms\\' . $name))->getName());
}

harness_section('renamed classes load only when asked for');
check('a new class is not loaded by the alias list', !class_exists('mindstellar\\admin\\form\\SpamSettingsScreen', false));
foreach (array('Advanced', 'Billing', 'Comment', 'KeywordBlock', 'LatestSearch', 'MailServer', 'Main', 'Media', 'Permalink', 'Sitemap', 'Spam', 'Storage') as $name) {
    pin("{$name}SettingsForm is {$name}SettingsScreen", 'mindstellar\\admin\\form\\' . $name . 'SettingsScreen', (new ReflectionClass('mindstellar\\admin\\form\\' . $name . 'SettingsForm'))->getName());
}

exit(harness_result());
