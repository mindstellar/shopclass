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
 * System info > Database, listing filter index: the status line in its three states, the
 * Rebuild button's guards, and that queueing a rebuild twice leaves one pending job.
 *
 * Usage: php tests/admin-facet-rebuild.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\admin\DatabaseTools;
use mindstellar\search\FacetIndex;
use mindstellar\search\FacetJobs;

$admin = api_admin_boot('osc_admin_facet_rebuild');
$p     = DB_TABLE_PREFIX;

harness_section('Status line');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
$now   = strtotime('2026-10-21 10:00:00');
$build = static fn (int $after, string $status = 'pending', string $created = '2026-10-21 10:00:00'): array => array(
    'type' => FacetIndex::JOB_BUILD, 'status' => $status, 'payload' => array('after' => $after), 'error' => '', 'created' => $created,
);
$status = static fn (array $env): array => DatabaseTools::facetStatus($env + array(
    'facet_ready' => false, 'facet_built_at' => '', 'facet_jobs' => array(), 'facet_listings' => 10, 'facet_auto_cron' => true, 'facet_now' => $now,
));

pin('not built', array('state' => 'missing', 'text' => 'Not built', 'note' => ''), $status(array()));
pin('ready, with the date', array('state' => 'ready', 'text' => 'Ready, built 2026-10-21 08:00:00', 'note' => ''), $status(array(
    'facet_ready' => true, 'facet_built_at' => '2026-10-21 08:00:00',
)));
pin('building: the job cursor over the listing count', array('state' => 'building', 'text' => 'Building: 4 of 10 listings done', 'note' => ''), $status(array(
    'facet_jobs' => array($build(4)),
)));
pin('building wins over ready on a rebuild', 'building', $status(array(
    'facet_ready' => true, 'facet_built_at' => '2026-10-21 08:00:00', 'facet_jobs' => array($build(0)),
))['state']);
pin('building never shows more done than there are listings', 'Building: 10 of 10 listings done', $status(array(
    'facet_jobs' => array($build(50)),
))['text']);
pin('a build that waits says how long', 'Queued 2 hours ago.', $status(array(
    'facet_jobs' => array($build(0, 'pending', '2026-10-21 08:00:00')),
))['note']);
pin('a running build does not', '', $status(array(
    'facet_jobs' => array($build(0, 'running', '2026-10-21 08:00:00')),
))['note']);
pin('with background jobs off it says what runs it', 'Background jobs are off, so it runs only when cron.php or the job worker runs.', $status(array(
    'facet_jobs' => array($build(0)), 'facet_auto_cron' => false,
))['note']);
$failed = array('type' => FacetIndex::JOB_BUILD, 'status' => 'error', 'payload' => array('after' => 3), 'error' => 'Lock wait timeout', 'created' => '2026-10-21 08:00:00');
pin('a build that failed for good says so, with its error', array(
    'state' => 'error', 'text' => 'The last build failed: Lock wait timeout', 'note' => 'Searches use the slower queries until a rebuild finishes.',
), $status(array('facet_jobs' => array($failed))));
pin('a failed rebuild over a ready index is a note', array('ready', 'The last build failed: Lock wait timeout'), array_values(array_intersect_key($status(array(
    'facet_ready' => true, 'facet_jobs' => array($failed),
)), array('state' => 1, 'note' => 1))));
pin('a queued build wins over an older failure', 'building', $status(array('facet_jobs' => array($build(0), $failed)))['state']);
pin('a one-field rebuild counts as building', array('building', 'Rebuilding a changed field: 6 of 10 listings done'), array_values(array_intersect_key($status(array(
    'facet_jobs' => array(array('type' => FacetIndex::JOB_FIELD, 'status' => 'pending', 'payload' => array('field' => 7, 'after' => 6), 'error' => '', 'created' => '2026-10-21 10:00:00')),
)), array('state' => 1, 'text' => 1))));
pin('values missing from a ready index ask for a rebuild', array('ready', '3 values of the newest listings are missing from it, likely written to the database directly. Rebuild it.'), array_values(array_intersect_key($status(array(
    'facet_ready' => true, 'facet_missing' => 3,
)), array('state' => 1, 'note' => 1))));
pin('a failed pack is not a failed build', 'missing', $status(array('facet_jobs' => array(array('type' => FacetIndex::JOB_PACK) + $failed)))['state']);
check('the template prints the note through osc_esc_html()', str_contains((string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/system-info/database.php'), "osc_esc_html(\$facet['note'])"));

harness_section('Fresh install');

$seed = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql');
preg_match("/\\('osclass', 'facet_built_at', ([^,]+), 'STRING'\\)/", $seed, $built);
check('a fresh install seeds the build time with the ready flag', str_contains($seed, "('osclass', 'facet_ready', '1', 'BOOLEAN')") && isset($built[1]));
check('as a date, as markReady() writes it', isset($built[1]) && (bool) preg_match('/^\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d$/', (string) $admin->query('SELECT ' . $built[1])->fetch_row()[0]));

harness_section('Guards');

$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminTools.php');
$view       = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/system-info/database.php');
preg_match('/private function databasePost\(.*?\n    \}\n/s', $controller, $m);
$post  = $m[0] ?? '';
$at    = strpos($post, "Params::getParam('facet_rebuild')");
$demo  = $at === false ? false : strpos($post, 'refuseOnDemo(', $at);
$token = $at === false ? false : strpos($post, 'osc_csrf_check()', $at);
$queue = $at === false ? false : strpos($post, "DatabaseTools::facetRebuild(\$env['pending'])", $at);
check('facet_rebuild refuses on the demo, checks the token, then queues', $demo !== false && $token !== false && $queue !== false && $demo < $token && $token < $queue);
check('the template prints the status through osc_esc_html()', strpos($view, "osc_esc_html(\$facet['text'])") !== false);
check('the button asks first, then posts facet_rebuild', strpos($view, "'confirm' => '#db-facet-dialog'") !== false && strpos($view, "'facet_rebuild' => '1'") !== false);

harness_section('Queueing');

$admin->query("DELETE FROM {$p}t_job_queue");
$count = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "' AND s_status = 'pending'")->fetch_row()[0];
pin('a pending migration refuses the rebuild', array('update', 0), array(DatabaseTools::facetRebuild(array('0099_next.php')), $count()));
pin('the first rebuild is queued', 'queued', DatabaseTools::facetRebuild(array()));
pin('a second one says it is already building', 'building', DatabaseTools::facetRebuild(array()));
pin('and leaves one pending job', 1, $count());
$admin->query("UPDATE {$p}t_job_queue SET s_status = 'running'");
pin('a running build is building too', 'building', DatabaseTools::facetRebuild(array()));
$admin->query("DELETE FROM {$p}t_job_queue");

harness_result();
