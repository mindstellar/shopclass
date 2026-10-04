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
 * The public form endpoint took any osc_form_id, so anyone could fill a listing field group
 * with submissions, and as often as they liked. Only forms flagged "available as a block" are
 * accepted now, and submissions per address are limited.
 *
 * DB-free.  Usage: php tests/form-submit-public.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function osc_register_widget($id, $spec)
{
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hForms.php';

harness_section('which forms the public may submit');
check('a placeable form', osc_form_is_public(array('pk_i_id' => 1, 's_meta' => '{"placeable":1}')));
check('a listing field group (no meta)', !osc_form_is_public(array('pk_i_id' => 2, 's_meta' => null)));
check('a field group with other meta only', !osc_form_is_public(array('pk_i_id' => 3, 's_meta' => '{"x":1}')));
check('placeable switched off', !osc_form_is_public(array('pk_i_id' => 4, 's_meta' => '{"placeable":0}')));
check('broken meta', !osc_form_is_public(array('pk_i_id' => 5, 's_meta' => '{oops')));
check('a missing form', !osc_form_is_public(array()));
check('a failed lookup', !osc_form_is_public(false));

harness_section('the submit endpoint uses it and is limited');
$src = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebForm.php');
check('only a public form is accepted', strpos($src, 'if (!osc_form_is_public($form)) {') !== false);
check('submissions are checked against the limit', strpos($src, "ActionThrottle::exceededFor('form_submit'") !== false);
check('a stored submission is counted', strpos($src, "ActionThrottle::record('form_submit')") !== false);
check(
    'the limit is checked before anything is stored',
    strpos($src, "exceededFor('form_submit'") < strpos($src, 'FormSubmission::newInstance()->create(')
);

exit(harness_result());
