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

pin('not built', array('state' => 'missing', 'text' => 'Not built'), DatabaseTools::facetStatus(array(
    'facet_ready' => false, 'facet_built_at' => '', 'facet_job_after' => null, 'facet_listings' => 10,
)));
pin('ready, with the date', array('state' => 'ready', 'text' => 'Ready, built 2026-10-21 08:00:00'), DatabaseTools::facetStatus(array(
    'facet_ready' => true, 'facet_built_at' => '2026-10-21 08:00:00', 'facet_job_after' => null, 'facet_listings' => 10,
)));
pin('building: the job cursor over the listing count', array('state' => 'building', 'text' => 'Building: 4 of 10 listings done'), DatabaseTools::facetStatus(array(
    'facet_ready' => false, 'facet_built_at' => '', 'facet_job_after' => 4, 'facet_listings' => 10,
)));
pin('building wins over ready on a rebuild', 'building', DatabaseTools::facetStatus(array(
    'facet_ready' => true, 'facet_built_at' => '2026-10-21 08:00:00', 'facet_job_after' => 0, 'facet_listings' => 10,
))['state']);
pin('building never shows more done than there are listings', 'Building: 10 of 10 listings done', DatabaseTools::facetStatus(array(
    'facet_ready' => false, 'facet_built_at' => '', 'facet_job_after' => 50, 'facet_listings' => 10,
))['text']);

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
$queue = $at === false ? false : strpos($post, 'FacetJobs::queueBuild()', $at);
check('facet_rebuild refuses on the demo, checks the token, then queues', $demo !== false && $token !== false && $queue !== false && $demo < $token && $token < $queue);
check('the template prints the status through osc_esc_html()', strpos($view, "osc_esc_html(\$facet['text'])") !== false);
check('the button asks first, then posts facet_rebuild', strpos($view, "'confirm' => '#db-facet-dialog'") !== false && strpos($view, "'facet_rebuild' => '1'") !== false);

harness_section('Queueing');

FacetJobs::queueBuild();
FacetJobs::queueBuild();
$count = (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "' AND s_status = 'pending'")->fetch_row()[0];
pin('two queued rebuilds leave one pending job', 1, $count);

harness_result();
