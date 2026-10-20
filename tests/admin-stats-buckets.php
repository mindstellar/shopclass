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
 * The admin stats charts: the 11 points each chart draws for a day, week or month view, the
 * first date it reads, and how the rows fill the points and give the largest count.
 *
 * DB-free.  Usage: php tests/admin-stats-buckets.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminStats.php';

$call = static function (string $method, ...$args) {
    $m = new ReflectionMethod('CAdminStats', $method);
    $m->setAccessible(true);

    return $m->invoke(null, ...$args);
};
$now = mktime(12, 0, 0, 3, 5, 2026);

harness_section('the period');
pin('week and month are kept', array('week', 'month'), array($call('period', 'week'), $call('period', 'month')));
pin('anything else is a day', array('day', 'day', 'day'), array($call('period', ''), $call('period', 'year'), $call('period', array('week'))));

harness_section('the first date read');
pin('a day view reads 10 days back', '2026-02-23', $call('since', 'day', 'Y-m-d', $now));
pin('a week view reads 70 days back', '2025-12-25 00:00:00', $call('since', 'week', 'Y-m-d H:i:s', $now));
pin('a month view reads 10 months back', '2025-05-05', $call('since', 'month', 'Y-m-d', $now));

harness_section('the points');
pin('11 days, oldest first', array(
    '2026-02-23', '2026-02-24', '2026-02-25', '2026-02-26', '2026-02-27', '2026-02-28',
    '2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05',
), array_keys($call('buckets', 'day', 0, $now)));
pin('11 weeks by number, ending this week', range(0, 10), array_keys($call('buckets', 'week', 0, $now)));
pin('11 months by name, ending this month', array(
    'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March',
), array_keys($call('buckets', 'month', 0, $now)));
pin('each point starts at the zero given', array('views' => 0), $call('buckets', 'day', array('views' => 0), $now)['2026-03-05']);

harness_section('filling the points');
[$points, $max] = $call('fill', $call('buckets', 'day', 0, $now), array(
    array('d_date' => '2026-03-01', 'num' => '4'),
    array('d_date' => '2026-03-04', 'num' => '9'),
    array('d_date' => '2026-03-05', 'num' => '2'),
));
pin('a row sets its point', array('4', '9', '2', 0), array($points['2026-03-01'], $points['2026-03-04'], $points['2026-03-05'], $points['2026-02-23']));
pin('the largest count is the max, not the last', '9', $max);
pin('no rows: max 0', 0, $call('fill', array(), array())[1]);

exit(harness_result());
