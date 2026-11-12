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
 * The Location field, printed by PlaceField::render(). The script clones its icons from the
 * template, so each is drawn here only.
 */

/** @var array<string,mixed> $placeOpts */
/** @var array<string,mixed> $placeValue */
/** @var bool $placeStrict */
/** @var bool $placeMulti */
/** @var string $placeError */
/** @var array<string,string> $placeTexts */

$placeEsc    = static fn (string $s): string => osc_esc_html($s);
$placeId     = $placeEsc((string) ($placeOpts['id'] ?? 'place'));
$placePicked = (int) ($placeValue['city_id'] ?? 0) > 0;
$placeText   = (string) $placeValue['text'];
$placeOrigin = $placePicked ? (string) ($placeValue['origin'] ?: 'list') : '';
$placeSvg    = static fn (string $path, int $size, string $width, string $attrs = ''): string => '<svg width="' . $size . '" height="' . $size
    . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $width . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $attrs . '>' . $path . '</svg>';
$placeTick   = $placeSvg('<path d="m5 12.5 4.5 4.5L19 7"/>', 14, '2.6', ' data-icon="tick"');
$placeData   = '';
foreach ($placeTexts as $placeKey => $placeString) {
    if (!str_starts_with($placeKey, 'hint-')) {
        $placeData .= ' data-text-' . $placeKey . '="' . $placeEsc($placeString) . '"';
    }
}
$placeClass  = trim('oe-place ' . (string) ($placeOpts['class'] ?? '') . ($placePicked ? ' is-picked' : ''));
$placePlain  = !empty($placeOpts['plain_ids']);
$placeHidden = static fn (string $name, string $value): string => '<input type="hidden" name="' . $name . '" id="' . ($placePlain ? $name : $placeId . '-' . $name) . '" value="' . $placeEsc($value) . '">';
?>
<div class="<?php echo $placeEsc($placeClass); ?>" data-osc-place data-strict="<?php echo $placeStrict ? '1' : '0'; ?>" data-min="2" data-limit="8" data-countries="<?php echo $placeMulti ? 'n' : '1'; ?>"<?php
    echo $placeOrigin !== '' ? ' data-origin="' . $placeEsc($placeOrigin) . '"' : ''; ?><?php echo $placeData; ?>>
    <label for="<?php echo $placeId; ?>"><?php echo $placeEsc((string) ($placeOpts['label'] ?? '') !== '' ? (string) $placeOpts['label'] : _m('Location'));
    if (!empty($placeOpts['required'])) { ?><span class="<?php echo $placeEsc(trim('oe-place__req ' . (string) ($placeOpts['req_class'] ?? ''))); ?>" aria-hidden="true">*</span><?php } ?></label>
    <div class="oe-place__box">
        <?php echo str_replace('<svg ', '<svg class="oe-place__pin" ', $placeSvg('<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>', 16, '2')); ?>
        <input type="text" class="oe-place__input" id="<?php echo $placeId; ?>" name="city" value="<?php echo $placeEsc($placeText); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo $placeId; ?>-list"<?php
            echo $placeError !== '' ? ' aria-invalid="true"' : ''; ?> aria-describedby="<?php echo ($placeError !== '' ? $placeId . '-error ' : '') . $placeId . '-hint ' . $placeId . '-note'; ?>" autocomplete="off" spellcheck="false">
        <div class="oe-place__end">
            <span class="oe-place__spin" hidden aria-hidden="true"></span>
            <button type="button" class="oe-place__clear" aria-label="<?php echo $placeEsc(_m('Clear location')); ?>"<?php echo $placeText !== '' ? '' : ' hidden'; ?>><?php
                echo $placeSvg('<path d="M6 6l12 12M18 6 6 18"/>', 14, '2.4'); ?></button>
        </div>
        <ul class="osc-ac-list" id="<?php echo $placeId; ?>-list" role="listbox" aria-label="<?php echo $placeEsc(_m('Places')); ?>" hidden></ul>
    </div>
    <p class="<?php echo $placeEsc(trim('oe-place__hint ' . (string) ($placeOpts['hint_class'] ?? ''))); ?>" id="<?php echo $placeId; ?>-hint"><?php echo $placeEsc($placeTexts[$placeStrict ? 'hint-on' : 'hint-off']); ?></p>
    <?php if ($placePicked) { ?>
        <p class="oe-place__note oe-place__note--picked" id="<?php echo $placeId; ?>-note"><?php echo $placeTick; ?><span><?php echo $placeEsc($placeTexts['note-' . $placeOrigin] ?? $placeTexts['note-list']); ?></span></p>
    <?php } elseif (!$placeStrict && $placeText !== '') { ?>
        <p class="oe-place__note" id="<?php echo $placeId; ?>-note"><span><?php echo $placeEsc($placeTexts['note-typed']); ?></span></p>
    <?php } else { ?>
        <p class="oe-place__note" id="<?php echo $placeId; ?>-note" hidden></p>
    <?php } ?>
    <p class="<?php echo $placeEsc(trim('oe-field-error ' . (string) ($placeOpts['error_class'] ?? ''))); ?>" id="<?php echo $placeId; ?>-error"<?php echo $placeError !== '' ? '' : ' hidden'; ?>><?php echo $placeEsc($placeError); ?></p>
    <?php if (is_array($placeValue['saved'] ?? null)) { ?>
        <button type="button" class="oe-place__saved" data-place="<?php echo $placeEsc(json_encode($placeValue['saved'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); ?>"><?php
            echo $placeSvg('<path d="M4 11 12 4l8 7M6 9.5V20h12V9.5"/>', 14, '2') . $placeEsc(_m('Use my saved place')); ?> <span><?php echo $placeEsc((string) $placeValue['saved']['label']); ?></span></button>
    <?php } else { ?>
        <button type="button" class="oe-place__saved" hidden></button>
    <?php } ?>
    <?php echo $placeHidden('cityId', $placePicked ? (string) $placeValue['city_id'] : '')
        . $placeHidden('regionId', $placePicked && (int) $placeValue['region_id'] > 0 ? (string) $placeValue['region_id'] : '')
        . $placeHidden('countryId', $placePicked ? (string) $placeValue['country_code'] : '')
        . $placeHidden('region', (string) $placeValue['region_text'])
        . $placeHidden('country', (string) $placeValue['country_text']); ?>
    <div class="oe-place__live" role="status" aria-live="polite"></div>
    <template class="oe-place__icons"><?php echo $placeTick . $placeSvg('<path d="M12 8v5M12 16h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>', 15, '2', ' data-icon="alert"'); ?></template>
</div>
