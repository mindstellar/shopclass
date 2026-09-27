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
 * Pins the Cleanup rule for avatars left behind by deleted users: it counts and
 * removes only user-owned t_resource rows whose user is gone and that are older than
 * the rule's age, and it unlinks their local files.
 *
 * Usage:  php tests/models/cleanup.php          (standalone, own scratch database)
 *         php tests/run-models.php cleanup      (as part of the suite)
 */

if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return ABS_PATH;
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($dummy = false)
    {
        return 'http://localhost/';
    }
}
if (!function_exists('osc_cache_get')) {
    // The same request-lifetime array cache tests/models/user.php stands in with.
    $GLOBALS['__user_test_cache'] = array();
    function osc_cache_get($key, &$found = null)
    {
        $found = array_key_exists($key, $GLOBALS['__user_test_cache']);

        return $found ? $GLOBALS['__user_test_cache'][$key] : false;
    }
    function osc_cache_set($key, $value, $ttl = 0)
    {
        $GLOBALS['__user_test_cache'][$key] = $value;

        return true;
    }
    function osc_cache_delete($key)
    {
        unset($GLOBALS['__user_test_cache'][$key]);

        return true;
    }
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 0);
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin  = scratchdb_session('osc_models_cleanup');
$prefix = DB_TABLE_PREFIX;
$engine = Cleanup::newInstance();

$dir = 'tests/tmp-cleanup-' . getmypid() . '/';
@mkdir(ABS_PATH . $dir, 0777, true);

$seedResource = static function (string $type, int $owner, string $created) use ($admin, $prefix, $dir): int {
    $id = seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_resource (s_owner_type, i_owner_id, s_name, s_extension, s_path, s_storage, dt_created)
         VALUES (?, ?, 'avatar', 'jpg', ?, 'local', ?)",
        'siss',
        array($type, $owner, $dir, $created)
    );
    file_put_contents(ABS_PATH . $dir . $id . '.jpg', 'x');

    return $id;
};
$exists = static function (int $id) use ($admin, $prefix): bool {
    return (int)$admin->query("SELECT COUNT(*) c FROM {$prefix}t_resource WHERE pk_i_id = $id")->fetch_assoc()['c'] === 1;
};

$live  = seed_user($admin, 'live', 'live@example.test');
$old   = date('Y-m-d H:i:s', time() - 60 * 24 * 3600);
$young = date('Y-m-d H:i:s');

$orphanA   = $seedResource('user', 900001, $old);
$orphanB   = $seedResource('user', 900002, $old);
$ownedOld  = $seedResource('user', $live, $old);
$orphanNew = $seedResource('user', 900003, $young);
$pageOld   = $seedResource('page', 900004, $old);

harness_section('Cleanup: orphan avatars rule');

check('the rule is registered', in_array('orphan_avatars', Cleanup::RULES, true));
check('it is a resource rule', Cleanup::isResourceRule('orphan_avatars'));
check('it is not a user rule', !Cleanup::isUserRule('orphan_avatars'));
pin('it counts only old user avatars whose user is gone', 2, $engine->countFor('orphan_avatars', 30));

$batch = $engine->candidates('orphan_avatars', 30, 1);
pin('a batch honours its size', 1, count($batch));
check('a candidate is a whole resource row', isset($batch[0]['s_path'], $batch[0]['s_storage'], $batch[0]['s_extension']));

pin('a purge of batch 1 removes one row', 1, $engine->purge('orphan_avatars', 30, 1));
pin('a second purge removes the other', 1, $engine->purge('orphan_avatars', 30, 10));
pin('nothing is left to match', 0, $engine->countFor('orphan_avatars', 30));
pin('another purge removes nothing', 0, $engine->purge('orphan_avatars', 30, 10));

check('the orphan rows are gone', !$exists($orphanA) && !$exists($orphanB));
check('their files are gone', !file_exists(ABS_PATH . $dir . $orphanA . '.jpg') && !file_exists(ABS_PATH . $dir . $orphanB . '.jpg'));
check('a live user\'s avatar is kept', $exists($ownedOld) && file_exists(ABS_PATH . $dir . $ownedOld . '.jpg'));
check('an orphan younger than the rule\'s age is kept', $exists($orphanNew));
check('another owner type is kept', $exists($pageOld));

foreach (glob(ABS_PATH . $dir . '*') ?: array() as $file) {
    @unlink($file);
}
@rmdir(ABS_PATH . $dir);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/cleanup.php */
