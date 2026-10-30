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

use mindstellar\menu\MenuItems;
use mindstellar\menu\MenuService;

$menuEditor = __get('menuEditor');

osc_admin_page(array(
    'section' => __('Appearance'),
    'title'   => __('Menus'),
    'help'    => __('Build the links your theme shows in its header and footer, or in a Menu widget.'),
));
if (is_array($menuEditor)) {
    osc_register_script('admin-menus', osc_asset_url_versioned(osc_current_admin_theme_js_url('menus.js')), array('admin-osc'));
    osc_enqueue_script('admin-menus');
}

osc_current_admin_theme_path('parts/header.php');

if (!is_array($menuEditor)) {
    osc_current_admin_theme_path('parts/footer.php');

    return;
}

$meId      = (string) $menuEditor['id'];
$meName    = (string) ($menuEditor['menus'][$meId] ?? $meId);
$meLocales = array();
foreach ((array) OSCLocale::getInstance()->listAllEnabled() as $meLocale) {
    $meLocales[] = array('code' => (string) $meLocale['pk_c_code'], 'name' => (string) $meLocale['s_name']);
}
if ($menuEditor['saved']) {
    $meStatus = __('Saved. Visitors see this menu.');
} elseif ($meId === 'main') {
    $meStatus = __('Automatic: your top categories. Save to use this list instead.');
} elseif ($meId === 'footer') {
    $meStatus = __('Automatic: the pages you show as links. Save to use this list instead.');
} else {
    $meStatus = __('Not saved yet.');
}

$mePlace = is_array($menuEditor['place']) ? $menuEditor['place'] : null;

$meState = array(
    'place'      => $mePlace,
    'items'      => $menuEditor['items'],
    'draft'      => !empty($menuEditor['draft']),
    'categories' => $menuEditor['categories'],
    'pages'      => $menuEditor['pages'],
    'locales'    => $meLocales,
    'default'    => osc_language(),
    'maxDepth'   => MenuItems::MAX_DEPTH,
    'maxItems'   => MenuItems::MAX_ITEMS,
    'i18n'       => array(
        'category'   => __('Category'),
        'page'       => __('Page'),
        'link'       => __('Link'),
        'missing'    => __('Missing: remove it'),
        'move'       => __('Move %s with the arrow keys'),
        'indent'     => __('Move %s under the item above'),
        'outdent'    => __('Move %s out one level'),
        'edit'       => __('Edit %s'),
        'remove'     => __('Remove %s'),
        'label'      => __('Label'),
        'labelIn'    => __('Label (%s)'),
        'labelHint'  => __('Leave empty to use its own name.'),
        'url'        => __('Address'),
        'unsaved'    => __('Unsaved changes'),
        'full'       => __('A menu can hold at most %d items.'),
        'needLink'   => __('A link needs a label and an address.'),
        'badAddress' => __('A link needs an address that starts with http://, https:// or /.'),
        'already'    => __('"%s" is already in this menu.'),
        'leave'      => __('You have unsaved changes to this menu.'),
        'hidden1'    => __('1 item is deeper than your theme shows here and will not show.'),
        'hiddenN'    => __('%d items are deeper than your theme shows here and will not show.'),
    ),
);
$meJson = json_encode($meState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$meCats = array();
foreach ($menuEditor['categories'] as $meCat) {
    $meCats[$meCat['id']] = $meCat;
}
$meDepth = static function (array $cat) use ($meCats): int {
    $depth = 0;
    while ($cat['parent'] > 0 && isset($meCats[$cat['parent']]) && $depth < 10) {
        $cat = $meCats[$cat['parent']];
        $depth++;
    }

    return $depth;
};
?>
<div class="menus-page" id="menus-page">
    <?php osc_admin_page_head(__('Menus')); ?>

    <div class="widgets-builder-grid">
        <div class="widgets-col">
            <form class="menu-pick nocsrf" method="get" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
                <input type="hidden" name="page" value="appearance" />
                <input type="hidden" name="action" value="menus" />
                <label class="form-sublabel" for="menu-pick"><?php _e('Menu to edit'); ?></label>
                <select id="menu-pick" name="menu" class="field-select">
                    <?php foreach ($menuEditor['menus'] as $mePickId => $mePickName) { ?>
                        <option value="<?php echo osc_esc_html($mePickId); ?>"<?php echo $mePickId === $meId ? ' selected' : ''; ?>><?php echo osc_esc_html($mePickName); ?></option>
                    <?php } ?>
                </select>
                <button type="submit" class="btn btn-dim btn-sm" id="menu-pick-go"><?php _e('Open'); ?></button>
            </form>

            <form id="menu-form" method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
                <input type="hidden" name="page" value="appearance" />
                <input type="hidden" name="action" value="menu_save_post" />
                <input type="hidden" name="menu" value="<?php echo osc_esc_html($meId); ?>" />
                <input type="hidden" name="items" id="menu-items-json" value="" />

                <div class="form-card menu-card">
                    <div class="form-card-head">
                        <div class="form-card-heading">
                            <span class="form-card-title"><?php echo osc_esc_html($meName); ?></span>
                            <span class="form-card-cats"><?php echo osc_esc_html($meStatus); ?></span>
                        </div>
                        <span class="form-card-actions">
                            <span class="form-card-status" id="menu-status" role="status" aria-live="polite"></span>
                        </span>
                    </div>
                    <?php if ($mePlace !== null) { ?>
                        <p class="menu-help" id="menu-place"><?php echo osc_esc_html(sprintf(__('Your theme shows this menu in: %1$s (%2$s).'), $mePlace['label'], sprintf(_n('%d level', '%d levels', $mePlace['depth']), $mePlace['depth']))); ?></p>
                        <?php $meBeyond = MenuService::itemsBeyond((array) $menuEditor['items'], $mePlace['depth']); ?>
                        <p class="menu-warn" id="menu-place-warn" role="status"<?php echo $meBeyond === 0 ? ' hidden' : ''; ?>><?php echo $meBeyond === 0 ? '' : osc_esc_html(sprintf($meBeyond === 1 ? $meState['i18n']['hidden1'] : $meState['i18n']['hiddenN'], $meBeyond)); ?></p>
                    <?php } elseif (empty($menuEditor['places'])) { ?>
                        <p class="menu-help" id="menu-no-places"><?php _e('Your theme does not show saved menus by itself. Add the Menu widget to a section to show one.'); ?></p>
                    <?php } ?>
                    <p class="visually-hidden" id="menu-grip-help"><?php _e('Up and down arrows move the item. Right arrow puts it under the item above, left arrow moves it out.'); ?></p>
                    <ol class="menu-items" id="menu-items" aria-label="<?php echo osc_esc_html(sprintf(__('Items in %s'), $meName)); ?>"></ol>
                    <p class="form-empty" id="menu-empty" hidden><?php _e('No items yet. Add some from the list on the right.'); ?></p>
                </div>

                <div class="menu-actions">
                    <button type="submit" class="btn btn-submit btn-sm" id="menu-save"><?php _e('Save menu'); ?></button>
                    <?php if ($menuEditor['core'] && $menuEditor['saved']) { ?>
                        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-open="#menuDeleteDialog"><?php _e('Use the automatic menu'); ?></button>
                    <?php } elseif (!$menuEditor['core']) { ?>
                        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-open="#menuDeleteDialog"><?php _e('Delete menu'); ?></button>
                    <?php } ?>
                </div>
            </form>
        </div>

        <aside class="widgets-palette-col menu-add" aria-labelledby="menu-add-title">
            <div class="col-head">
                <h3 class="col-title" id="menu-add-title"><?php _e('Add items'); ?></h3>
            </div>
            <p class="col-hint"><?php _e('New items go to the end of the menu. Drag them into place, or use the arrow keys on the handle.'); ?></p>

            <div class="form-card menu-add-card">
                <label class="form-sublabel" for="menu-add-category"><?php _e('Category'); ?></label>
                <div class="menu-add-row">
                    <select id="menu-add-category" class="field-select">
                        <?php foreach ($menuEditor['categories'] as $meCat) { ?>
                            <option value="<?php echo (int) $meCat['id']; ?>"><?php echo osc_esc_html(str_repeat('— ', $meDepth($meCat)) . $meCat['name']); ?></option>
                        <?php } ?>
                    </select>
                    <button type="button" class="btn btn-dim btn-sm" data-menu-add="category"<?php echo $menuEditor['categories'] === array() ? ' disabled' : ''; ?>><?php _e('Add'); ?></button>
                </div>

                <label class="form-sublabel" for="menu-add-page"><?php _e('Page'); ?></label>
                <div class="menu-add-row">
                    <select id="menu-add-page" class="field-select">
                        <?php foreach ($menuEditor['pages'] as $mePage) { ?>
                            <option value="<?php echo (int) $mePage['id']; ?>"><?php echo osc_esc_html($mePage['name']); ?></option>
                        <?php } ?>
                    </select>
                    <button type="button" class="btn btn-dim btn-sm" data-menu-add="page"<?php echo $menuEditor['pages'] === array() ? ' disabled' : ''; ?>><?php _e('Add'); ?></button>
                </div>

                <span class="form-sublabel"><?php _e('Link'); ?></span>
                <label class="visually-hidden" for="menu-add-link-label"><?php _e('Link label'); ?></label>
                <input type="text" id="menu-add-link-label" class="input-text" maxlength="<?php echo MenuItems::MAX_LABEL; ?>" placeholder="<?php echo osc_esc_html(__('Label')); ?>" />
                <div class="menu-add-row">
                    <label class="visually-hidden" for="menu-add-link-url"><?php _e('Link address'); ?></label>
                    <input type="text" id="menu-add-link-url" class="input-text" inputmode="url" placeholder="<?php echo osc_esc_html(__('https://… or /path')); ?>" />
                    <button type="button" class="btn btn-dim btn-sm" data-menu-add="link"><?php _e('Add'); ?></button>
                </div>
                <p class="menu-add-error" id="menu-add-error" role="alert" hidden></p>
            </div>

            <div class="col-head">
                <h3 class="col-title"><?php _e('New menu'); ?></h3>
            </div>
            <form class="form-card menu-new" method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
                <input type="hidden" name="page" value="appearance" />
                <input type="hidden" name="action" value="menu_save_post" />
                <input type="hidden" name="create" value="1" />
                <label class="form-sublabel" for="menu-new-label"><?php _e('Name'); ?></label>
                <input type="text" id="menu-new-label" name="label" class="input-text" maxlength="<?php echo MenuItems::MAX_LABEL; ?>" required />
                <label class="form-sublabel" for="menu-new-id"><?php _e('Id'); ?></label>
                <input type="text" id="menu-new-id" name="menu" class="input-text" required pattern="[a-z][a-z0-9_\-]{0,31}" maxlength="32" aria-describedby="menu-new-id-help" />
                <p class="menu-help" id="menu-new-id-help"><?php printf(osc_esc_html(__('Lowercase letters, digits, - and _. Themes and widgets use it to find the menu. At most %d menus.')), MenuService::MAX_MENUS); ?></p>
                <button type="submit" class="btn btn-dim btn-sm"><?php _e('Create menu'); ?></button>
            </form>
        </aside>
    </div>

    <template id="menu-row">
        <li class="field-chip menu-item">
            <div class="field-chip-row">
                <button type="button" class="chip-grip" draggable="true" aria-describedby="menu-grip-help"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
                <span class="chip-name"></span>
                <span class="chip-type"></span>
                <span class="chip-actions">
                    <button type="button" class="chip-btn" data-act="outdent"><i class="bi bi-arrow-bar-left" aria-hidden="true"></i></button>
                    <button type="button" class="chip-btn" data-act="indent"><i class="bi bi-arrow-bar-right" aria-hidden="true"></i></button>
                    <button type="button" class="chip-btn" data-act="edit" aria-expanded="false"><i class="bi bi-pencil-fill" aria-hidden="true"></i></button>
                    <button type="button" class="chip-btn chip-danger" data-act="remove"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                </span>
            </div>
            <div class="field-chip-editor" hidden></div>
        </li>
    </template>
    <script type="application/json" id="menu-state"><?php echo $meJson; ?></script>
</div>
<?php
osc_admin_confirm_dialog(array(
    'id'      => 'menuDeleteDialog',
    'method'  => 'post',
    'fields'  => array('page' => 'appearance', 'action' => 'menu_delete_post', 'menu' => $meId),
    'title'   => $menuEditor['core'] ? __('Use the automatic menu?') : __('Delete this menu?'),
    'text'    => $menuEditor['core'] ? __('Your saved items are removed and the menu lists its automatic links again.') : __('Themes and widgets that show this menu show nothing until you make it again.'),
    'confirm' => $menuEditor['core'] ? __('Use automatic') : __('Delete'),
));
osc_current_admin_theme_path('parts/footer.php');
