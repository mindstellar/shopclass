<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/**
 * The country, region and city parts of the Location field in the fields and dropdown styles.
 * Included by place-field.php, which holds the root, the hint and the shared tail.
 */

/** @var array<string,mixed> $placeOpts */
/** @var array<string,mixed> $placeValue */
/** @var bool $placeStrict */
/** @var bool $placeMulti */
/** @var string $placeStyle */
/** @var array<string,mixed> $placeLists */
/** @var array<string,string> $placeTexts */
/** @var callable $placeEsc */
/** @var callable $placeSvg */
/** @var string $placeId */
/** @var callable $placeHidden */

$levelErrors   = (array) ($placeOpts['errors'] ?? array());
$levelRequired = (array) ($placeOpts['required_levels'] ?? array());
$levelReqCls   = $placeEsc(trim('oe-place__req ' . (string) ($placeOpts['req_class'] ?? '')));
$levelErrCls   = $placeEsc(trim('oe-field-error ' . (string) ($placeOpts['error_class'] ?? '')));
$levelPartCls  = $placeEsc(trim('oe-place__part ' . (string) ($placeOpts['part_class'] ?? '')));
$levelNames    = array('country' => _m('Country'), 'region' => _m('Region'), 'city' => _m('City'));
$levelCountry  = (string) $placeValue['country_code'];
$levelRegionId = (int) $placeValue['region_id'];
$levelCityId   = (int) $placeValue['city_id'];
$levelRegions  = (array) ($placeLists['regions'] ?? array());
$levelCities   = (array) ($placeLists['cities'] ?? array());
$levelPin      = str_replace('<svg ', '<svg class="oe-place__pin" ', $placeSvg('<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>', 16, '2'));
$levelX        = $placeSvg('<path d="M6 6l12 12M18 6 6 18"/>', 14, '2.4');

// Each level drawn: a select (name, rows, current, disabled), a suggest box or a plain text box (text), and the hidden id that goes with a box.
$levels = array();
if ($placeMulti) {
    $levels['country'] = array('control' => 'select', 'name' => 'countryId', 'rows' => (array) ($placeLists['countries'] ?? array()), 'current' => $levelCountry, 'disabled' => false);
}
if ($placeStyle === 'fields') {
    $levels['region'] = array('control' => 'box', 'text' => (string) $placeValue['region_name'], 'id' => array('regionId', $levelRegionId));
    $levels['city']   = array('control' => 'box', 'text' => (string) $placeValue['city_name'], 'id' => array('cityId', $levelCityId));
} else {
    $levelRegionText  = !$placeStrict && $levelCountry !== '' && $levelRegions === array();
    $levelCityText    = !$placeStrict && ($levelRegionText || ($levelRegionId > 0 && $levelCities === array()));
    $levels['region'] = $levelRegionText
        ? array('control' => 'text', 'text' => (string) $placeValue['region_name'], 'id' => array('regionId', 0))
        : array('control' => 'select', 'name' => 'regionId', 'rows' => $levelRegions, 'current' => $levelRegionId > 0 ? (string) $levelRegionId : '', 'disabled' => $levelRegions === array());
    $levels['city']   = $levelCityText
        ? array('control' => 'text', 'text' => (string) $placeValue['city_name'], 'id' => array('cityId', 0))
        : array('control' => 'select', 'name' => 'cityId', 'rows' => $levelCities, 'current' => $levelCityId > 0 ? (string) $levelCityId : '', 'disabled' => $levelCities === array());
}

if ((string) ($placeOpts['label'] ?? '') !== '') {
    echo '<span class="oe-place__legend" id="' . $placeId . '-label">' . $placeEsc((string) $placeOpts['label']) . '</span>';
}
if (!$placeMulti) {
    echo $placeHidden('countryId', $levelCountry);
}

foreach ($levels as $level => $control) {
    $id    = $placeId . '-' . $level;
    $error = (string) ($levelErrors[$level] ?? '');
    $aria  = $error !== '' ? ' aria-invalid="true" aria-describedby="' . $id . '-error ' . $placeId . '-hint"' : ' aria-describedby="' . $placeId . '-hint"';
    echo '<div class="' . $levelPartCls . '" data-level="' . $level . '"><label for="' . $id . '">' . $placeEsc($levelNames[$level])
        . ((bool) ($levelRequired[$level] ?? !empty($placeOpts['required'])) ? '<span class="' . $levelReqCls . '" aria-hidden="true">*</span>' : '') . '</label>';
    if ($control['control'] === 'select') {
        echo '<select class="oe-place__select" id="' . $id . '" name="' . $control['name'] . '"' . ($control['disabled'] ? ' disabled' : '') . $aria . '>'
            . '<option value="">' . $placeEsc($placeTexts['select-' . $level]) . '</option>';
        foreach ($control['rows'] as $row) {
            $value = (string) ($row['code'] ?? $row['id']);
            echo '<option value="' . $placeEsc($value) . '"' . ($value === $control['current'] && $value !== '' ? ' selected' : '') . '>' . $placeEsc((string) $row['name']) . '</option>';
        }
        echo '</select>';
    } elseif ($control['control'] === 'box') {
        echo '<div class="oe-place__box">' . $levelPin
            . '<input type="text" class="oe-place__input" id="' . $id . '" name="' . $level . '" value="' . $placeEsc($control['text']) . '" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . $id . '-list"'
            . $aria . ' autocomplete="off" spellcheck="false">'
            . '<div class="oe-place__end"><span class="oe-place__spin" hidden aria-hidden="true"></span>'
            . '<button type="button" class="oe-place__clear" aria-label="' . $placeEsc(_m('Clear location')) . '"' . ($control['text'] !== '' ? '' : ' hidden') . '>' . $levelX . '</button></div>'
            . '<ul class="osc-ac-list" id="' . $id . '-list" role="listbox" aria-label="' . $placeEsc(_m('Places')) . '" hidden></ul></div>';
    } else {
        echo '<input type="text" class="oe-place__text" id="' . $id . '" name="' . $level . '" value="' . $placeEsc($control['text']) . '"' . $aria . '>';
    }
    if (isset($control['id'])) {
        echo $placeHidden($control['id'][0], $control['id'][1] > 0 ? (string) $control['id'][1] : '');
    }
    echo '<p class="' . $levelErrCls . '" id="' . $id . '-error"' . ($error !== '' ? '' : ' hidden') . '>' . $placeEsc($error) . '</p></div>';
}
