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
 * The Submissions filter lists only forms set as a Page builder block (plus any other form
 * that still has submissions, flagged), and the Forms > Submissions sidebar entry shows only
 * when a block form or a submission exists.
 *
 * Usage:  php tests/models/form-submissions-visibility.php
 *         php tests/run-models.php form-submissions-visibility
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin  = scratchdb_session('osc_models_form_submissions_visibility');
$prefix = DB_TABLE_PREFIX;

use mindstellar\model\FormSubmission;

$group = static function (string $name, ?string $meta) use ($admin, $prefix): int {
    seed_exec($admin, "INSERT INTO {$prefix}t_meta_group (s_name, s_slug, s_meta) VALUES (?, ?, ?)", 'sss', array($name, $name, $meta));

    return (int) $admin->insert_id;
};
$names = static fn (array $rows): array => array_map(static fn (array $r): string => $r['s_name'] . (isset($r['not_block']) ? '*' : ''), $rows);

harness_section('menu entry');
FormSubmission::forgetMenu();
pin('hidden with no forms', false, FormSubmission::menuVisible());
$plain = $group('plain', null);
FormSubmission::forgetMenu();
pin('hidden with an ordinary form and no submissions', false, FormSubmission::menuVisible());
$block = $group('block', '{"placeable":1}');
FormSubmission::forgetMenu();
pin('shown once a form is a block', true, FormSubmission::menuVisible());
$admin->query("UPDATE {$prefix}t_meta_group SET s_meta = NULL WHERE pk_i_id = $block");
FormSubmission::forgetMenu();
pin('hidden again when the option goes off', false, FormSubmission::menuVisible());
pin('a created submission shows it at once', true, (new FormSubmission())->create($plain, 'page', 1, null, null, array()) > 0 && FormSubmission::menuVisible());

harness_section('filter list');
$admin->query("UPDATE {$prefix}t_meta_group SET s_meta = '{\"placeable\":1}' WHERE pk_i_id = $block");
$group('off', null);
$rows = FieldGroup::getInstance()->listAll();
pin('block form, and the off form with a submission flagged; a bare off form left out', array('plain*', 'block'), $names(FormSubmission::formsForFilter($rows)));

$admin->query("DELETE FROM {$prefix}t_form_submission");
pin('with no submissions only the block form remains', array('block'), $names(FormSubmission::formsForFilter($rows)));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
