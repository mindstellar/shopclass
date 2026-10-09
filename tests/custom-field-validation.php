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
 * Custom-field values are checked by FieldValidator alone. The listing form (web and API)
 * and the form builder each keep their own error shape, and a URL field takes http(s) only.
 *
 * Usage: php tests/custom-field-validation.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

function _m($key)
{
    return $key;
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';

use mindstellar\form\builder\FieldValidator;
use mindstellar\listing\ListingValidator;

$field = static fn (int $id, string $slug, string $type, array $extra = array()): array => $extra + array(
    'pk_i_id'    => $id,
    's_slug'     => $slug,
    's_name'     => ucfirst($slug),
    'e_type'     => $type,
    'b_required' => 0,
    's_options'  => '',
);

$fields = array(
    $field(1, 'colour', 'TEXT', array('b_required' => 1)),
    $field(2, 'fuel', 'DROPDOWN', array('s_options' => 'diesel,petrol')),
    $field(3, 'site', 'URL'),
    $field(4, 'towbar', 'CHECKBOX'),
    $field(5, 'axles', 'NUMBER', array('rules' => array('show_when' => array('field' => 'fuel', 'op' => 'eq', 'value' => 'diesel')))),
    $field(6, 'note', 'TEXT', array('rules' => array('required_when' => array('field' => 'fuel', 'op' => 'eq', 'value' => 'petrol')))),
);

harness_section('the listing form keeps its pointer, code and message');

$meta   = array(2 => 'petrol', 3 => 'http-x://a.b/', 5 => '6', 99 => 'stray');
$errors = (new ListingValidator())->meta($fields, $meta);
pin('errors in field order, one per field', array(
    array('pointer' => '/custom_fields/1', 'code' => 'required', 'message' => 'Colour is required.'),
    array('pointer' => '/custom_fields/3', 'code' => 'invalid', 'message' => 'Site is invalid.'),
    array('pointer' => '/custom_fields/6', 'code' => 'required', 'message' => 'Note is required.'),
), $errors);
pin('an unknown id and a field hidden by its rule are dropped; an unticked checkbox is 0', array(2 => 'petrol', 3 => 'http-x://a.b/', 4 => 0), $meta);

$meta = array(1 => '', 2 => 'diesel', 5 => '3');
(new ListingValidator())->meta($fields, $meta);
pin('an emptied value stays, so an edit clears it', '', $meta[1] ?? null);
pin('a shown field keeps its value', '3', (string) ($meta[5] ?? ''));

$meta = array(1 => 'red');
pin('a category with no fields takes no values', array(), (new ListingValidator())->meta(array(), $meta));
pin('...and drops them', array(), $meta);

harness_section('the form builder keeps its message list');

$result = FieldValidator::process($fields, array(2 => 'petrol', 3 => 'example.com', 5 => '6'));
pin('errors are messages', array('Colour is required.', 'Note is required.'), $result['errors']);
pin('values hold only what is set and shown', array(2 => 'petrol', 3 => 'https://example.com', 4 => 0), $result['values']);

harness_section('a URL field takes http and https only');

foreach (array('javascript://comment%0Aalert(1)', 'data://text/html,x', 'http-x://a.b/') as $url) {
    $r = FieldValidator::check(array($field(3, 'site', 'URL')), array(3 => $url));
    pin('refused: ' . $url, array(array('field' => 3, 'code' => 'invalid', 'message' => 'Site is invalid.')), $r['errors']);
}
$r = FieldValidator::check(array($field(3, 'site', 'URL')), array(3 => 'https://example.com/a'));
pin('an https address passes', array(), $r['errors']);

harness_section('date ranges');

pin('both ends are cast', array('from' => 10, 'to' => 20), FieldValidator::sanitizeValue('DATEINTERVAL', array('from' => '10', 'to' => '20', 'x' => '1')));
pin('an emptied end stays empty', array('from' => '', 'to' => 20), FieldValidator::sanitizeValue('DATEINTERVAL', array('from' => '', 'to' => '20')));
pin('a non-array is no range', array(), FieldValidator::sanitizeValue('DATEINTERVAL', 'x'));

exit(harness_result());
