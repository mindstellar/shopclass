<?php if (!defined('OC_ADMIN')) {
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

use mindstellar\admin\ui\Field;

$customiser = __get('customiser');

osc_admin_page(array('title' => __('Customise')));
osc_add_filter('admin_body_class', static function ($classes) {
    return array_merge(is_array($classes) ? $classes : array(), array('osc-customise-page'));
});
if (is_array($customiser)) {
    osc_register_script('admin-customise', osc_asset_url_versioned(osc_current_admin_theme_js_url('customise.js')), array('admin-osc'));
    osc_enqueue_script('admin-customise');
}

osc_current_admin_theme_path('parts/header.php');

if (!is_array($customiser)) {
    osc_current_admin_theme_path('parts/footer.php');

    return;
}

$czAdmin    = osc_admin_base_url(true) . '?page=appearance&action=';
$czCsrf     = '&' . osc_csrf_token_url();
$czLocation = (string) array_key_first($customiser['locations']);
$czThemeName = (string) ($customiser['theme_name'] ?? osc_theme());

$czState = array(
    'locations'  => $customiser['locations'],
    'types'      => $customiser['types'],
    'live'       => $customiser['live'],
    'updated_at' => $customiser['updated_at'],
    'urls'       => array(
        'draft'   => $czAdmin . 'customise_draft_post' . $czCsrf,
        'publish' => $czAdmin . 'customise_publish_post' . $czCsrf,
        'discard' => $czAdmin . 'customise_discard_post' . $czCsrf,
    ),
    'i18n'       => array(
        'saving'      => __('Saving…'),
        'saved'       => __('Draft saved just now'),
        'kept'        => __('You have unpublished changes'),
        'clean'       => __('Everything is published'),
        'retrying'    => __('Could not save, retrying'),
        'published'   => __('Published'),
        'publishedOk' => __('Your changes are live.'),
        'publishing'  => __('Publishing…'),
        'failed'      => __('Something went wrong. Try again.'),
        'moveUp'      => __('Move %s up'),
        'moveDown'    => __('Move %s down'),
        'move'        => __('Reorder %s with the arrow keys'),
        'hide'        => __('Hide %s'),
        'show'        => __('Show %s'),
        'remove'      => __('Remove %s'),
        'removeConfirm' => __('Press again to remove %s'),
        'removeShort' => __('Remove?'),
        'noFields'    => __('This section has no settings.'),
        'noRows'      => __('No sections yet. Add one below.'),
        'pickRow'     => __('Pick a section to edit it.'),
        'hidden'      => __('Hidden'),
    ),
);
$czJson = json_encode($czState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// The section types every location may hold, for the shared field markup.
$widgetTypes = array();
foreach ($customiser['types'] as $czTypeId => $czType) {
    $czSpec = \mindstellar\widgets\WidgetRegistry::getInstance()->get($czTypeId);
    if ($czSpec !== null) {
        $widgetTypes[$czTypeId] = $czSpec;
    }
}
$currentTypeId       = '';
$currentWidgetConfig = array();
?>
<div class="cz" id="cz">
    <aside class="cz-side" aria-label="<?php echo osc_esc_html(__('Customiser')); ?>">
        <div class="cz-top">
            <h1 class="cz-title"><?php echo osc_esc_html(sprintf(__('Customise %s'), $czThemeName)); ?></h1>
            <label class="visually-hidden" for="cz-page"><?php _e('Page'); ?></label>
            <select id="cz-page" class="form-select form-select-sm"<?php echo count($customiser['locations']) < 2 ? ' disabled' : ''; ?>>
                <?php foreach ($customiser['locations'] as $czSlug => $czLoc) { ?>
                    <option value="<?php echo osc_esc_html($czSlug); ?>"><?php echo osc_esc_html($czLoc['label']); ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="cz-list">
            <h2 class="cz-h" id="cz-rows-title"><?php _e('Page sections'); ?></h2>
            <p class="visually-hidden" id="cz-grip-help"><?php _e('Press the up or down arrow key to move this section.'); ?></p>
            <?php if (!empty($customiser['stale'])) { ?>
                <p class="cz-note" id="cz-stale"><?php _e('These sections were changed elsewhere, such as on the Widgets screen, after your draft began. Publishing replaces those changes; Discard starts again from what is live.'); ?></p>
            <?php } ?>
            <ol class="cz-rows" id="cz-rows" aria-labelledby="cz-rows-title"></ol>
            <p class="cz-note" id="cz-other" hidden
               data-text-one="<?php echo osc_esc_html(__('1 widget here is not a section this page offers. Publishing removes it.')); ?>"
               data-text-many="<?php echo osc_esc_html(__('%d widgets here are not sections this page offers. Publishing removes them.')); ?>"></p>
            <button type="button" class="cz-add" id="cz-add" aria-expanded="false" aria-controls="cz-types">
                <i class="bi bi-plus-lg" aria-hidden="true"></i><?php _e('Add section'); ?>
            </button>
            <ul class="cz-types" id="cz-types" hidden>
                <?php foreach ($customiser['types'] as $czTypeId => $czType) { ?>
                    <li data-type="<?php echo osc_esc_html($czTypeId); ?>">
                        <button type="button" class="cz-type" data-add="<?php echo osc_esc_html($czTypeId); ?>">
                            <span class="cz-type-name"><?php echo osc_esc_html($czType['label']); ?></span>
                            <?php if ($czType['description'] !== '') { ?>
                                <span class="cz-type-desc"><?php echo osc_esc_html($czType['description']); ?></span>
                            <?php } ?>
                        </button>
                    </li>
                <?php } ?>
            </ul>
        </div>

        <div class="cz-panel">
            <section class="cz-section" id="cz-section" aria-labelledby="cz-section-title">
                <h2 class="cz-panel-title" id="cz-section-title"></h2>
                <div class="cz-fields" id="cz-section-fields"></div>
            </section>

            <?php if ($customiser['look'] !== array()) { ?>
                <section class="cz-look" id="cz-look" aria-labelledby="cz-look-title">
                    <h2 class="cz-panel-title" id="cz-look-title"><?php _e('Look'); ?></h2>
                    <?php foreach ($customiser['look'] as $czName => $czLook) {
                        $czField = $czLook['field'];
                        $czType  = (string) $czField['type'];
                        $czId    = 'cz-look-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $czName);
                        $czLabel = (string) ($czField['label'] ?? $czName);
                        $czValue = is_scalar($czLook['value']) ? (string) $czLook['value'] : '';
                        $czSwatches = $czType === 'select' ? ($customiser['swatches'][$czName] ?? array()) : array();
                        ?>
                        <div class="cz-field" data-look="<?php echo osc_esc_html($czName); ?>" data-look-type="<?php echo osc_esc_html($czType); ?>">
                            <?php if ($czType === 'checkbox') { ?>
                                <label class="field-choice">
                                    <input type="checkbox" id="<?php echo osc_esc_html($czId); ?>" name="<?php echo osc_esc_html($czName); ?>" value="1"<?php echo $czValue !== '' && $czValue !== '0' ? ' checked' : ''; ?> />
                                    <span><?php echo osc_esc_html($czLabel); ?></span>
                                </label>
                            <?php } elseif ($czSwatches !== array()) { ?>
                                <span class="form-sublabel" id="<?php echo osc_esc_html($czId); ?>-label"><?php echo osc_esc_html($czLabel); ?></span>
                                <div class="cz-swatches" role="group" aria-labelledby="<?php echo osc_esc_html($czId); ?>-label">
                                    <?php foreach ((array) ($czField['options'] ?? array()) as $czOption => $czOptionLabel) {
                                        if (!isset($czSwatches[$czOption])) {
                                            continue;
                                        } ?>
                                        <button type="button" class="cz-swatch" data-value="<?php echo osc_esc_html($czOption); ?>"
                                                aria-pressed="<?php echo (string) $czOption === $czValue ? 'true' : 'false'; ?>"
                                                aria-label="<?php echo osc_esc_html($czOptionLabel); ?>" title="<?php echo osc_esc_html($czOptionLabel); ?>"
                                                style="background-color: <?php echo osc_esc_html($czSwatches[$czOption]); ?>"></button>
                                    <?php } ?>
                                </div>
                                <div class="visually-hidden">
                                    <?php Field::control('select', $czId, array(
                                        'name'    => $czName,
                                        'value'   => $czValue,
                                        'options' => $czField['options'] ?? array(),
                                        'attrs'   => array('tabindex' => '-1', 'aria-labelledby' => $czId . '-label'),
                                    )); ?>
                                </div>
                            <?php } else { ?>
                                <label class="form-sublabel" for="<?php echo osc_esc_html($czId); ?>"><?php echo osc_esc_html($czLabel); ?></label>
                                <?php Field::control($czType, $czId, array_merge($czField, array(
                                    'name'        => $czName,
                                    'value'       => $czValue,
                                    'row'         => false,
                                    'required'    => false,
                                    'preview_url' => $czLook['preview_url'],
                                    'remove_name' => $czName . '_remove',
                                ))); ?>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </section>
            <?php } ?>
        </div>
    </aside>

    <div class="cz-main">
        <div class="cz-bar">
            <div class="cz-seg" role="group" aria-label="<?php echo osc_esc_html(__('Preview size')); ?>">
                <button type="button" data-device="desktop" aria-pressed="true"><?php _e('Desktop'); ?></button>
                <button type="button" data-device="phone" aria-pressed="false"><?php _e('Phone'); ?></button>
            </div>
            <span class="cz-status" id="cz-status" role="status" aria-live="polite"></span>
            <div class="cz-actions">
                <button type="button" class="btn btn-dim btn-sm" id="cz-discard" data-osc-dialog-open="#cz-discard-dialog"><?php _e('Discard'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="cz-publish"><?php _e('Publish'); ?></button>
            </div>
        </div>
        <div class="cz-canvas">
            <div class="cz-frame" id="cz-frame">
                <iframe id="cz-preview" title="<?php echo osc_esc_html(__('Preview of your site')); ?>"
                        src="<?php echo osc_esc_html($customiser['locations'][$czLocation]['preview_url']); ?>"
                        referrerpolicy="same-origin"></iframe>
            </div>
        </div>
    </div>
</div>

<template id="cz-type-fields">
    <?php require __DIR__ . '/parts/widget-fields.php'; ?>
</template>

<dialog id="cz-discard-dialog" class="osc-dialog osc-dialog-danger" aria-labelledby="cz-discard-title">
    <div class="osc-dialog-body">
        <p class="osc-dialog-title" id="cz-discard-title"><?php _e('Discard your changes?'); ?></p>
        <p><?php _e('Your draft is thrown away and the editor goes back to what is live on the site.'); ?></p>
    </div>
    <div class="osc-dialog-actions">
        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Keep editing'); ?></button>
        <button type="button" class="btn btn-red btn-sm" id="cz-discard-confirm"><?php _e('Discard'); ?></button>
    </div>
</dialog>

<dialog id="cz-stale-dialog" class="osc-dialog osc-dialog-danger" aria-labelledby="cz-stale-title">
    <div class="osc-dialog-body">
        <p class="osc-dialog-title" id="cz-stale-title"><?php _e('Replace changes made elsewhere?'); ?></p>
        <p><?php _e('These sections were changed elsewhere, such as on the Widgets screen, after your draft began. Publishing replaces those changes with your draft.'); ?></p>
    </div>
    <div class="osc-dialog-actions">
        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Keep editing'); ?></button>
        <button type="button" class="btn btn-red btn-sm" id="cz-stale-confirm"><?php _e('Publish anyway'); ?></button>
    </div>
</dialog>

<script type="application/json" id="cz-state"><?php echo $czJson; ?></script>
<?php
osc_current_admin_theme_path('parts/media-picker.php');
osc_current_admin_theme_path('parts/footer.php');
