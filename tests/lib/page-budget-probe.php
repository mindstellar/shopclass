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
 * Loaded before every request of the test server tests/page-budget.php starts. Points the site
 * at the test theme and, when the request ends, appends what it cost to PAGE_BUDGET_OUT as one
 * JSON line: files included, statements sent to the database, commands sent to the cache.
 */

if (getenv('PAGE_BUDGET_THEMES')) {
    define('THEMES_PATH', rtrim((string) getenv('PAGE_BUDGET_THEMES'), '/') . '/');
}

register_shutdown_function(static function (): void {
    $out = (string) getenv('PAGE_BUDGET_OUT');
    if ($out === '') {
        return;
    }
    $queries = 0;
    if (class_exists('mindstellar\\database\\ConnectionManager', false)) {
        $shared = new ReflectionProperty(\mindstellar\database\ConnectionManager::class, 'instance');
        $shared->setAccessible(true);
        $manager = $shared->getValue();
        $handle  = $manager === null ? false : $manager->getHandle();
        if ($handle instanceof mysqli) {
            $row     = $handle->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc();
            $queries = (int) ($row['Value'] ?? 0) - 1;
        }
    }
    $trips = 0;
    if (class_exists('mindstellar\\cache\\CacheManager', false)) {
        $cache = \mindstellar\cache\CacheManager::getInstance();
        $trips = $cache instanceof \mindstellar\cache\RedisCache ? $cache->trips : 0;
    }
    // Polyfills load or not by PHP version, so they are left out and every PHP counts the same.
    $files = array_values(array_filter(
        array_map(static fn (string $f): string => str_replace(ABS_PATH, '', $f), array_slice(get_included_files(), 1)),
        static fn (string $f): bool => strpos($f, '/symfony/polyfill-') === false
    ));
    file_put_contents($out, json_encode(array(
        'uri'     => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'status'  => http_response_code(),
        'files'   => $files,
        'queries' => $queries,
        'trips'   => $trips,
    ), JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
});
