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
    osc_register_script('admin-widget-fields', osc_asset_url_versioned(osc_current_admin_theme_js_url('widget-fields.js')), array('admin-osc'));
    osc_register_script('admin-customise', osc_asset_url_versioned(osc_current_admin_theme_js_url('customise.js')), array('admin-osc', 'admin-widget-fields'));
    osc_enqueue_script('tiny_mce');
    osc_enqueue_script('admin-editor');
    osc_enqueue_script('admin-customise');
}

osc_current_admin_theme_path('parts/header.php');

if (!is_array($customiser)) {
    osc_current_admin_theme_path('parts/footer.php');

    return;
}

$czPageMode = ($customiser['mode'] ?? 'theme') === 'page';
$czTarget   = $czPageMode ? (array) $customiser['target'] : array();
$czPageId   = (int) ($czTarget['id'] ?? 0);
$czAdmin    = osc_admin_base_url(true) . '?page=appearance&action=';
$czCsrf     = '&' . osc_csrf_token_url();
$czLocation = (string) array_key_first($customiser['locations']);
// A theme with settings only has no sections to list.
$czSections = $customiser['locations'] !== array();
$czThemeName = (string) ($customiser['theme_name'] ?? osc_theme());
$czRows      = $czSections ? $customiser['locations'][$czLocation]['rows'] : array();
$czPreview   = $czSections ? $customiser['locations'][$czLocation]['preview_url'] : osc_customiser_preview_url();
if ($czPageMode) {
    $czPageUrl = osc_admin_base_url(true) . '?page=pages&action=blocks_%s_post&id=' . $czPageId . $czCsrf;
    $czUrls    = array(
        'draft'   => sprintf($czPageUrl, 'draft'),
        'publish' => sprintf($czPageUrl, 'publish'),
        'discard' => sprintf($czPageUrl, 'discard'),
    );
} else {
    $czUrls = array(
        'draft'   => $czAdmin . 'customise_draft_post' . $czCsrf,
        'publish' => $czAdmin . 'customise_publish_post' . $czCsrf,
        'discard' => $czAdmin . 'customise_discard_post' . $czCsrf,
    );
}

// A type without its own icon gets a plain section one, so no row or tile has an empty icon slot.
foreach ($customiser['types'] as $czTypeId => $czType) {
    if ((string) ($czType['icon'] ?? '') === '') {
        $customiser['types'][$czTypeId]['icon'] = 'layout-text-window';
    }
}

// The tiles, by group; a type this admin may not author gets none.
$czGroups = array(
    'layout'   => __('Layout'),
    'content'  => __('Content'),
    'advanced' => __('Advanced'),
);
$czTiles = array_fill_keys(array_keys($czGroups), array());
foreach ($customiser['types'] as $czTypeId => $czType) {
    if (empty($czType['locked'])) {
        $czTiles[$czType['group'] ?? 'content'][$czTypeId] = $czType;
    }
}
$czTiles = array_filter($czTiles);

$czState = array(
    'mode'        => $czPageMode ? 'page' : 'theme',
    'target'      => $czPageMode ? $czTarget : null,
    'moderator'   => !empty($customiser['moderator']),
    'locations'   => (object) $customiser['locations'],
    'preview_url' => $czPreview,
    'types'       => $customiser['types'],
    'live'        => $customiser['live'],
    'updated_at'  => $customiser['updated_at'],
    'urls'        => $czUrls,
    'i18n'        => array(
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
        'removeText'  => $czPageMode ? __('Remove block') : __('Remove'),
        'noFields'    => $czPageMode ? __('This block has no settings.') : __('This section has no settings.'),
        'expand'      => __('Expand'),
        'noRows'      => $czPageMode ? __('This page has no blocks yet.') : __('No sections yet. Add one below.'),
        'pickRow'     => $czPageMode ? __('Pick a block to edit it.') : __('Pick a section to edit it.'),
        'hidden'      => __('Hidden'),
        'back'        => $czPageMode ? __('All blocks') : __('All sections'),
        'legacyName'  => __('Custom HTML'),
        'lockSuper'   => __('Only a super admin can change this block. You can see it and move it, but not edit it.'),
        'lockLegacy'  => __('This is older custom HTML. It cannot be edited here.'),
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
<div class="cz<?php echo $czPageMode ? ' cz-page' : ''; ?>" id="cz">
    <aside class="cz-side" aria-label="<?php echo osc_esc_html($czPageMode ? __('Block editor') : __('Customiser')); ?>">
      <div class="cz-views">
        <div class="cz-view" id="cz-list-view">
            <div class="cz-top">
                <?php if ($czPageMode) { ?>
                    <div class="cz-top-links">
                        <a class="cz-back" id="cz-page-back" href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=pages'); ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i><?php _e('Back to pages'); ?></a>
                        <a class="cz-settings-link" id="cz-page-settings" href="<?php echo osc_esc_html($czTarget['edit_url']); ?>"><i class="bi bi-gear" aria-hidden="true"></i><?php _e('Page settings'); ?></a>
                    </div>
                    <h1 class="cz-title"><?php echo osc_esc_html(sprintf(__('Edit blocks: %s'), $czTarget['title'])); ?></h1>
                <?php } else { ?>
                    <h1 class="cz-title"><?php echo osc_esc_html(sprintf(__('Customise %s'), $czThemeName)); ?></h1>
                    <label class="visually-hidden" for="cz-page"><?php _e('Page'); ?></label>
                    <select id="cz-page" class="form-select form-select-sm"<?php echo count($customiser['locations']) < 2 ? ' disabled' : ''; ?><?php echo $czSections ? '' : ' hidden'; ?>>
                        <?php foreach ($customiser['locations'] as $czSlug => $czLoc) { ?>
                            <option value="<?php echo osc_esc_html($czSlug); ?>"><?php echo osc_esc_html($czLoc['label']); ?></option>
                        <?php } ?>
                    </select>
                <?php } ?>
            </div>

            <?php if (!empty($customiser['stale'])) { ?>
                <p class="cz-note cz-stale" id="cz-stale"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span><?php echo osc_esc_html($czPageMode
                    ? __('The blocks on this page were changed elsewhere after your draft began. Publishing replaces those changes; Discard starts again from what is live.')
                    : __('These sections were changed elsewhere, such as on the Widgets screen, after your draft began. Publishing replaces those changes; Discard starts again from what is live.')); ?></span></p>
            <?php } ?>

            <div class="cz-list">
              <div class="cz-sections"<?php echo $czSections ? '' : ' hidden'; ?>>
                <h2 class="cz-h" id="cz-rows-title"><?php echo osc_esc_html($czPageMode ? __('Page blocks') : __('Page sections')); ?></h2>
                <p class="visually-hidden" id="cz-grip-help"><?php echo osc_esc_html($czPageMode ? __('Press the up or down arrow key to move this block.') : __('Press the up or down arrow key to move this section.')); ?></p>
                <ol class="cz-rows" id="cz-rows" aria-labelledby="cz-rows-title"></ol>
                <p class="cz-empty" id="cz-empty"<?php echo $czRows === array() ? '' : ' hidden'; ?>><?php echo osc_esc_html($czState['i18n']['noRows']); ?></p>
                <p class="cz-note" id="cz-other" hidden
                   data-text="<?php echo osc_esc_html($czPageMode ? __('Blocks here that this page does not offer: %d. Publishing removes them.') : __('Widgets here that are not sections of this page: %d. Publishing removes them.')); ?>"></p>
                <button type="button" class="cz-add" id="cz-add" aria-expanded="false" aria-controls="cz-tiles">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i><?php echo osc_esc_html($czPageMode ? __('Add block') : __('Add section')); ?>
                </button>
                <div class="cz-pick" id="cz-tiles" hidden>
                    <?php foreach ($czTiles as $czGroup => $czGroupTypes) { ?>
                        <div class="cz-tile-group" data-group="<?php echo osc_esc_html($czGroup); ?>">
                            <?php if (count($czTiles) > 1) { ?>
                                <h3 class="cz-pick-h"><?php echo osc_esc_html($czGroups[$czGroup]); ?></h3>
                            <?php } ?>
                            <div class="cz-tiles">
                                <?php foreach ($czGroupTypes as $czTypeId => $czType) { ?>
                                    <button type="button" class="cz-tile<?php echo $czGroup === 'advanced' ? ' is-wide' : ''; ?>" data-add="<?php echo osc_esc_html($czTypeId); ?>"<?php echo $czType['description'] !== '' ? ' title="' . osc_esc_html($czType['description']) . '"' : ''; ?>>
                                        <?php if ($czType['icon'] !== '') { ?><i class="bi bi-<?php echo osc_esc_html($czType['icon']); ?>" aria-hidden="true"></i><?php } ?>
                                        <span><?php echo osc_esc_html($czType['label']); ?></span>
                                    </button>
                                <?php } ?>
                            </div>
                            <?php if ($czGroup === 'advanced') { ?>
                                <p class="cz-warn" id="cz-code-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span><?php _e('Custom Code runs as written on your site. Only super admins see it.'); ?></span></p>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <div class="cz-pick-x"><button type="button" class="btn btn-dim btn-sm" id="cz-tiles-close"><?php _e('Cancel'); ?></button></div>
                </div>
              </div>
              <?php if ($customiser['look'] !== array()) { ?>
                <button type="button" class="cz-look-open" id="cz-look-open">
                    <span><?php _e('Look'); ?></span>
                    <i class="bi bi-chevron-right chev" aria-hidden="true"></i>
                </button>
              <?php } ?>
            </div>
        </div>

        <div class="cz-view is-out-fwd" id="cz-field-view" inert>
            <div class="cz-head">
                <button type="button" class="cz-back" id="cz-back"><i class="bi bi-chevron-left" aria-hidden="true"></i><span id="cz-back-text"><?php echo osc_esc_html($czState['i18n']['back']); ?></span></button>
                <h2 class="cz-panel-title" id="cz-section-title"></h2>
                <span class="cz-type-line" id="cz-section-type"></span>
            </div>
            <div class="cz-body">
                <section class="cz-section" id="cz-section" aria-labelledby="cz-section-title">
                    <p class="cz-desc" id="cz-section-desc"></p>
                    <p class="cz-lock" id="cz-lock-note" hidden><i class="bi bi-lock" aria-hidden="true"></i><span></span></p>
                    <div class="cz-field">
                        <label class="form-sublabel" for="cz-name"><?php _e('Name in this list (optional)'); ?></label>
                        <input type="text" class="form-control form-control-sm" id="cz-name" maxlength="40" autocomplete="off" />
                    </div>
                    <?php if ($czPageMode && is_array($customiser['page_text'] ?? null)) { ?>
                        <div class="cz-page-text" id="cz-page-text" hidden>
                            <?php osc_admin_field(array(
                                'type'           => 'richtext',
                                'id'             => 'cz-page-text-field',
                                'name'           => 'page_text',
                                'translate_name' => 'page_text[%s]',
                                'label'          => __('Page text'),
                                'layout'         => 'stacked',
                                'translate'      => true,
                                'locales'        => $customiser['page_text']['locales'],
                                'value'          => $customiser['page_text']['values'],
                                'preset'         => 'full',
                                'height'         => 300,
                                'media'          => true,
                                'upload_url'     => adminLibraryUploadUrl(),
                            )); ?>
                        </div>
                    <?php } ?>
                    <div class="cz-fields" id="cz-section-fields"></div>
                    <div class="cz-foot">
                        <button type="button" class="cz-remove" id="cz-remove"><i class="bi bi-trash" aria-hidden="true"></i><span><?php echo osc_esc_html($czState['i18n']['removeText']); ?></span></button>
                    </div>
                </section>
                <?php if ($customiser['look'] !== array()) { ?>
                <section class="cz-look" id="cz-look" aria-labelledby="cz-look-title" hidden>
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
        </div>
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
                        src="<?php echo osc_esc_html($czPreview); ?>"
                        referrerpolicy="same-origin"></iframe>
            </div>
        </div>
    </div>
</div>

<template id="cz-type-fields">
    <?php require __DIR__ . '/parts/widget-fields.php'; ?>
</template>

<?php
osc_admin_confirm_dialog(array(
    'id'         => 'cz-discard-dialog',
    'form'       => false,
    'attrs'      => array('aria-labelledby' => 'cz-discard-title'),
    'title_id'   => 'cz-discard-title',
    'title'      => __('Discard your changes?'),
    'text'       => __('Your draft is thrown away and the editor goes back to what is live on the site.'),
    'cancel'     => __('Keep editing'),
    'confirm'    => __('Discard'),
    'confirm_id' => 'cz-discard-confirm',
));
osc_admin_confirm_dialog(array(
    'id'         => 'cz-stale-dialog',
    'form'       => false,
    'attrs'      => array('aria-labelledby' => 'cz-stale-title'),
    'title_id'   => 'cz-stale-title',
    'title'      => __('Replace changes made elsewhere?'),
    'text'       => $czPageMode
        ? __('The blocks on this page were changed elsewhere after your draft began. Publishing replaces those changes with your draft.')
        : __('These sections were changed elsewhere, such as on the Widgets screen, after your draft began. Publishing replaces those changes with your draft.'),
    'cancel'     => __('Keep editing'),
    'confirm'    => __('Publish anyway'),
    'confirm_id' => 'cz-stale-confirm',
)); ?>

<dialog id="cz-expand" class="osc-dialog osc-dialog-wide cz-expand" aria-labelledby="cz-expand-title">
    <div class="osc-dialog-body">
        <p class="osc-dialog-title" id="cz-expand-title"></p>
        <div id="cz-expand-editor"></div>
    </div>
    <div class="osc-dialog-actions">
        <button type="button" class="btn btn-primary btn-sm" data-osc-dialog-close><?php _e('Done'); ?></button>
    </div>
</dialog>

<script type="application/json" id="cz-state"><?php echo $czJson; ?></script>
<?php
osc_current_admin_theme_path('parts/media-picker.php');
osc_current_admin_theme_path('parts/footer.php');
