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

exit(harness_result());

/* file end: ./tests/autocron-stamp.php */
