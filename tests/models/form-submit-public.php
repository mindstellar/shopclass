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
 * with submissions, as often as they liked. Listing field groups are refused now, every other
 * form is still accepted, and submissions per address are limited.
 *
 * Usage:  php tests/models/form-submit-public.php     (standalone, own scratch database)
 *         php tests/run-models.php form-submit-public (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_form_submit_public');

require_once __DIR__ . '/../lib/action-standins.php';
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
if (!function_exists('osc_register_widget')) {
    function osc_register_widget($id, $spec)
    {
    }
}
foreach (array('hMessages', 'hForms', 'hFields', 'hSecurity') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
}

/** Thrown in place of the exit() a real redirect ends the request with. */
class FormRedirect extends RuntimeException
{
}

/** The real controller, with a redirect that returns control to the test. */
class TestWebForm extends CWebForm
{
    public function redirectTo($url, $code = null)
    {
        throw new FormRedirect((string) $url);
    }
}

$prefix = DB_TABLE_PREFIX;
$catId  = seed_category($admin, 'Bicycles');

/** A group with one optional text field; returns its id. */
$group = static function (string $name, ?string $meta, bool $bound) use ($admin, $prefix, $catId): int {
    seed_exec($admin, "INSERT INTO {$prefix}t_meta_group (s_name, s_slug, s_meta) VALUES (?, ?, ?)", 'sss', array($name, $name, $meta));
    $id = (int) $admin->insert_id;
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_meta_fields (s_name, s_slug, e_type, fk_i_group_id) VALUES (?, ?, 'TEXT', ?)",
        'ssi',
        array($name . ' note', $name . '-note', $id)
    );
    $fieldId = (int) $admin->insert_id;
    seed_exec($admin, "INSERT INTO {$prefix}t_meta_group_fields (fk_i_group_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($id, $fieldId));
    if ($bound) {
        seed_exec($admin, "INSERT INTO {$prefix}t_meta_group_categories (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($id, $catId));
    }

    return $id;
};

$listingGroup = $group('listing', null, true);
$blockOnCats  = $group('block', '{"placeable":1}', true);
$codeForm     = $group('code', null, false);

$_SERVER['REMOTE_ADDR'] = '198.51.100.20';

/** Post the form and return the flash message the visitor is sent back with. */
$submit = static function (int $formId): string {
    $csrf = new \mindstellar\Csrf();
    $_POST = $_REQUEST = array(
        'page' => 'form', 'action' => 'submit', 'osc_form_id' => (string) $formId,
        'CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue(),
    );
    Params::init();
    Session::newInstance()->_dropMessage('pubMessages');
    $form   = (new ReflectionClass('TestWebForm'))->newInstanceWithoutConstructor();
    $action = new ReflectionProperty('BaseModel', 'action');
    $action->setAccessible(true);
    $action->setValue($form, 'submit');
    try {
        $form->doModel();
    } catch (FormRedirect $e) {
    }
    $messages = Session::newInstance()->_getMessage('pubMessages');

    return is_array($messages) ? implode(' ', array_column($messages, 'msg')) : (string) $messages;
};
$stored = static function (int $formId) use ($admin, $prefix): int {
    return (int) $admin->query("SELECT COUNT(*) FROM {$prefix}t_form_submission WHERE fk_i_group_id = $formId")->fetch_row()[0];
};

harness_section('which forms the public may submit');
pin('a listing field group is refused', 'That form is no longer available.', $submit($listingGroup));
pin('...and nothing is stored', 0, $stored($listingGroup));
pin('a form marked as a block is taken, even with categories', 'Thank you! Your submission has been received.', $submit($blockOnCats));
pin('a form a plugin renders from code is taken', 'Thank you! Your submission has been received.', $submit($codeForm));
pin('...and stored', 1, $stored($codeForm));
pin('a missing form is refused', 'That form is no longer available.', $submit(999999));

harness_section('submissions per address are limited');
// Two were taken above; eight more make ten from this address in the hour.
for ($i = 0; $i < 8; $i++) {
    $submit($codeForm);
}
pin('ten in the hour are taken', 9, $stored($codeForm));
pin('the eleventh is refused', 'Too many tries from your connection. Please try again later.', $submit($codeForm));
pin('...and not stored', 9, $stored($codeForm));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
