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
 * A DATE and a DATEINTERVAL value posted with a listing come back on its edit form.
 *
 * The posted timestamps go through the same sanitising and Field::replace() writes the save
 * path uses, are read back the way the edit form reads them, and are rendered by FieldForm.
 *
 * Usage:  php tests/models/field-date-roundtrip.php     (standalone, own scratch database)
 *         php tests/run-models.php field-date-roundtrip (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_field_date_roundtrip');

require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';
if (!function_exists('osc_enqueue_script')) {
    function osc_enqueue_script($id)
    {
    }
}
if (!function_exists('osc_enqueue_style')) {
    function osc_enqueue_style($id)
    {
    }
}

$prefix = DB_TABLE_PREFIX;
$catId  = seed_category($admin, 'Events');
$itemId = seed_item($admin, $catId);

$addField = static function (string $slug, string $type) use ($admin, $prefix, $catId): int {
    seed_exec($admin, "INSERT INTO {$prefix}t_meta_fields (s_name, s_slug, e_type) VALUES (?, ?, ?)", 'sss', array(ucfirst($slug), $slug, $type));
    $id = (int) $admin->insert_id;
    seed_exec($admin, "INSERT INTO {$prefix}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($catId, $id));

    return $id;
};
$dateField  = $addField('opens', 'DATE');
$rangeField = $addField('runs', 'DATEINTERVAL');

$prevTz = date_default_timezone_get();
date_default_timezone_set('UTC');

// What the date inputs write: 2026-03-14 00:00, and 2026-04-01 to the end of 2026-04-03.
$posted = array(
    $dateField  => '1773446400',
    $rangeField => array('from' => '1775001600', 'to' => (string) (1775174400 + 86399)),
);

$model = Field::getInstance();
foreach ($posted as $fieldId => $value) {
    $type = $fieldId === $dateField ? 'DATE' : 'DATEINTERVAL';
    $model->replace($itemId, $fieldId, \mindstellar\form\builder\FieldValidator::sanitizeValue($type, $value));
}

harness_section('the posted timestamps are stored as sent');

$stored = array_column($model->findByCategoryItem($catId, $itemId), 's_value', 'pk_i_id');
pin('the date', '1773446400', $stored[(string) $dateField] ?? null);
pin(
    'the range, one row per end',
    array('from' => '1775001600', 'to' => (string) (1775174400 + 86399)),
    $model->getDateIntervalByPrimaryKey($itemId, $rangeField)
);

harness_section('the edit form shows them');

ob_start();
FieldForm::renderFieldList($model->findByCategoryItem($catId, $itemId));
$html = (string) ob_get_clean();

$tagOf = static function (string $id) use ($html): string {
    return preg_match('/<input[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m) ? $m[0] : '';
};

check('the hidden date input carries the timestamp', str_contains($tagOf('meta_opens'), 'value="1773446400"'), $tagOf('meta_opens'));
check('and posts under the same name', str_contains($tagOf('meta_opens'), 'name="meta[' . $dateField . ']"'), $tagOf('meta_opens'));
check('the date input shows the day', str_contains($tagOf('meta_opens_date'), 'value="2026-03-14"'), $tagOf('meta_opens_date'));
check('the range start shows its day', str_contains($tagOf('meta_runs_from_date'), 'value="2026-04-01"'), $tagOf('meta_runs_from_date'));
check('the range end shows its own day', str_contains($tagOf('meta_runs_to_date'), 'value="2026-04-03"'), $tagOf('meta_runs_to_date'));
check('the range ends post under the same names', str_contains($tagOf('meta_runs_from'), 'name="meta[' . $rangeField . '][from]"') && str_contains($tagOf('meta_runs_to'), 'name="meta[' . $rangeField . '][to]"'), $html);

date_default_timezone_set($prevTz);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
