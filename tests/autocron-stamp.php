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
 * Pins where auto-cron keeps its throttle stamp: in uploads/ when PHP can write there, else in
 * the temp folder. A stamp that cannot be written would run cron on every page.
 *
 * DB-free.  Usage:  php tests/autocron-stamp.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function osc_add_hook(...$args)
{
}

require_once __DIR__ . '/../oc-includes/osclass/helpers/hJobs.php';

$uploads = sys_get_temp_dir() . '/osc-autocron-stamp-' . bin2hex(random_bytes(4)) . '/';
mkdir($uploads);

harness_section('auto-cron stamp');
pin('a writable uploads folder holds it', $uploads . '.autocron_tick', _osc_auto_cron_stamp($uploads));
pin('the jobs throttle has its own stamp', $uploads . '.autocron_jobs', _osc_auto_cron_stamp($uploads, 'jobs'));

harness_section('auto-cron throttles');
function osc_uploads_path()
{
    return $GLOBALS['uploads'];
}
check('the default cache is the per-request one, so the stamps throttle', \mindstellar\cache\CacheManager::getInstance() instanceof \mindstellar\cache\MemoryCache);
pin('the full run and the jobs run each take their first slot', array(true, true), array(_osc_auto_cron_due('tick', 300), _osc_auto_cron_due('jobs', 60)));
pin('...and not again inside their window', array(false, false), array(_osc_auto_cron_due('tick', 300), _osc_auto_cron_due('jobs', 60)));
touch($uploads . '.autocron_jobs', time() - 61);
clearstatcache();
pin('a minute on, jobs may run while the full run still waits', array(false, true), array(_osc_auto_cron_due('tick', 300), _osc_auto_cron_due('jobs', 60)));
unlink($uploads . '.autocron_tick');
unlink($uploads . '.autocron_jobs');

harness_section('auto-cron stamp, read-only');

touch($uploads . '.autocron_tick');
chmod($uploads, 0555);
clearstatcache();
pin('a read-only folder keeps a stamp it can still write', $uploads . '.autocron_tick', _osc_auto_cron_stamp($uploads));
chmod($uploads, 0755);
unlink($uploads . '.autocron_tick');

chmod($uploads, 0555);
clearstatcache();
if (is_writable($uploads)) {
    check('a read-only folder can be tested (not as root)', true);
} else {
    $fallback = _osc_auto_cron_stamp($uploads);
    check('a read-only uploads folder moves it to the temp folder', strpos($fallback, sys_get_temp_dir()) === 0 && strpos($fallback, $uploads) !== 0, $fallback);
    check('...where it can be written', touch($fallback));
    @unlink($fallback);
    check('another site gets its own', _osc_auto_cron_stamp('/srv/other/uploads/') !== $fallback);
}
chmod($uploads, 0755);
rmdir($uploads);

harness_section('auto-cron throttles on a shared cache');

/** A shared cache that keeps a request copy of what it read, and counts trips to the server. */
final class AutocronSharedCache implements \mindstellar\cache\CacheDriver
{
    public array $server = array();
    public array $copy   = array();
    public int $trips    = 0;

    public function add($key, $data, $expire = 0)
    {
        return false;
    }
    public function set($key, $data, $expire = 0)
    {
        ++$this->trips;
        $this->server[$key] = $this->copy[$key] = $data;

        return true;
    }
    public function get($key, &$found = null)
    {
        if (array_key_exists($key, $this->copy)) {
            $found = true;

            return $this->copy[$key];
        }
        ++$this->trips;
        $found = array_key_exists($key, $this->server);
        if ($found) {
            $this->copy[$key] = $this->server[$key];
        }

        return $found ? $this->server[$key] : false;
    }
    public function getMany(array $keys): array
    {
        ++$this->trips;
        $out = array();
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->server)) {
                $out[$key] = $this->copy[$key] = $this->server[$key];
            }
        }

        return $out;
    }
    public function delete($key)
    {
        unset($this->server[$key], $this->copy[$key]);

        return true;
    }
    public function flush()
    {
        $this->server = $this->copy = array();

        return true;
    }
    public function stats()
    {
    }
    public function _get_cache()
    {
        return 'test';
    }
    public static function is_supported()
    {
        return true;
    }
    public function __destruct()
    {
    }
}

require_once __DIR__ . '/lib/cache-swap.php';
$shared = new AutocronSharedCache();
test_cache_swap($shared);
$page = static function () use ($shared): array {
    $shared->copy  = array();
    $shared->trips = 0;
    _osc_auto_cron_prefetch();
    $due = array(_osc_auto_cron_due('tick', 300), _osc_auto_cron_due('jobs', 60));

    return array($due, $shared->trips);
};
pin('the first page takes both slots', array(true, true), $page()[0]);
pin('a page inside both windows reads them in one trip and runs nothing', array(array(false, false), 1), $page());
unset($shared->server['osclass_autocron_jobs']);
pin('a minute on, jobs may run while the full run still waits', array(false, true), $page()[0]);
test_cache_swap(null);

exit(harness_result());

/* file end: ./tests/autocron-stamp.php */
