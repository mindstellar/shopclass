<?php
if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_enqueue_script('sortablejs');
osc_enqueue_script('admin-categories');

$table  = __get('category_table');
$status = (string)__get('category_status');
$rows   = $table->rows();
$counts = $table->counts();
$sum    = $table->summary();
$limit  = $table->depthLimit();

// Opens the empty drawer; the category is created when it is saved.
$add_button = array(
    'icon'  => 'bi-plus-circle-fill',
    'label' => __('Add category'),
    'class' => 'js-cat-add',
);

osc_admin_page(array(
    'section' => osc_mode_text(__('Listings'), __('Products')),
    'heading' => __('Categories'),
    'title'   => __('Categories'),
    'actions' => array(
        $add_button,
    ),
    'help'    => static function () {
        $bold = static fn (string $label): string => '<strong>' . osc_esc_html($label) . '</strong>'; ?>
        <ul>
            <li><?php printf(
                __('%1$s Drag a row by its handle to reorder it among its siblings. To put a category inside another, use %2$s or the Parent field when you edit it.'),
                $bold(__('Order and nesting:')),
                osc_esc_html(__('Move to…'))
            ); ?></li>
            <li><?php printf(__('%s hides a category and its subcategories from your site. Nothing is deleted.'), $bold(__('Disable'))); ?></li>
            <li><?php printf(__('%s removes the category, its subcategories and every listing in them.'), $bold(__('Delete'))); ?></li>
        </ul>
    <?php },
));

$base = osc_admin_base_url(true);
$ajax = static fn (string $action, bool $csrf = true): string => osc_esc_html(
    $base . '?page=ajax&action=' . $action . ($csrf ? '&' . osc_csrf_token_url() : '')
);

// Plural forms for the script: each distinct form once, and which one n = 0..199 takes. Every
// gettext Plural-Forms rule gives n >= 200 the same form as 100 + n % 100.
$plural = static function (callable $form): array {
    $forms = array();
    $idx   = '';
    for ($n = 0; $n < 200; $n++) {
        $text = $form($n);
        $at   = array_search($text, $forms, true);
        if ($at === false) {
            $at      = count($forms);
            $forms[] = $text;
        }
        $idx .= chr(48 + (int)$at);
    }

    return array('forms' => $forms, 'idx' => $idx);
};

$i18n = array(
    'deleteTitle'      => __('Delete category'),
    'delete'           => __('Delete'),
    'cancel'           => __('Cancel'),
    'ajaxError'        => __('Something went wrong. Please try again.'),
    'enabled'          => __('Enabled'),
    'disabled'         => __('Disabled'),
    'enable'           => __('Enable'),
    'disable'          => __('Disable'),
    'editTitle'        => __('Edit category'),
    'newTitle'         => __('Add category'),
    'addSubTitle'      => __('Add subcategory'),
    'topLevel'         => __('Top level'),
    'tooDeep'          => __('(too deep)'),
    'inside'           => __('Inside %s'),
    'moveOne'          => __('Move %s'),
    'nowIn'            => __('Now in %1$s, %2$d of %3$d.'),
    'move'             => __('Move'),
    'first'            => __('First'),
    'after'            => __('After %s'),
    'last'             => __('Last'),
    'moved'            => __('Moved.'),
    'setExpiry'        => __('Set expiry…'),
    'never'            => __('Never'),
    'selected'         => __('%d selected'),
    'clear'            => __('Clear'),
    'clearSearch'      => __('Clear search'),
    'showAll'          => __('Show all'),
    'addAs'            => __('Add “%s” as a category'),
    'noMatch'          => __('No category matches “%s”'),
    'showSubs'         => __('Show subcategories'),
    'hideSubs'         => __('Hide subcategories'),
    'depthNote'        => $limit === null ? '' : sprintf(__('This theme shows %d levels of categories.'), $limit),
    'footSum'          => __('%1$s in %2$s · %3$s'),
    'categoriesN'      => $plural(static fn (int $n): string => _n('%d category', '%d categories', $n)),
    'groupsN'          => $plural(static fn (int $n): string => _n('%d group', '%d groups', $n)),
    'listingsN'        => $plural(static fn (int $n): string => _n('%d listing', '%d listings', $n)),
    'subcategoriesN'   => $plural(static fn (int $n): string => _n('%d subcategory', '%d subcategories', $n)),
    'daysN'            => $plural(static fn (int $n): string => _n('After %d day', 'After %d days', $n)),
    'filterShown'      => $plural(static fn (int $n): string => _n('%d category shown.', '%d categories shown.', $n)),
    'filterSearch'     => __('Searching for “%s”.'),
    'filterOn'         => __('Showing enabled categories only.'),
    'filterOff'        => __('Showing disabled categories only.'),
    'emptyOn'          => __('No enabled categories.'),
    'emptyOff'         => __('No disabled categories.'),
    'pair'             => __('%1$s · %2$s'),
    'filterNoReorder'  => __('Reordering is off while a filter is on.'),
    'applyLabel'       => $plural(static fn (int $n): string => _n('Also apply to its %d subcategory', 'Also apply to its %d subcategories', $n)),
    'applyOn'          => __('Every subcategory gets this setting too.'),
    'applyOff'         => __('Left off, each subcategory keeps its own setting.'),
    'redateNew'        => __('Give them new end dates'),
    'redateNever'      => __('Remove their end dates'),
    'disableTitle'     => __('Disable %s?'),
    'disableFacts'     => __('Its subcategories are disabled too. Nothing is deleted. You can enable it again at any time.'),
    'deleteConfirm'    => __('Delete %s?'),
    'deleteFacts'      => __('This also deletes its subcategories and every listing in them, including expired and unapproved ones. This cannot be undone.'),
    'deleteFactsOne'   => __('This also deletes every listing in it, including expired and unapproved ones. This cannot be undone.'),
    'deleteFactsMany'  => __('This also deletes every listing in them, including expired and unapproved ones. This cannot be undone.'),
    'deleteQueued'     => __('Deleting in the background. This can take a moment.'),
    'working'          => __('This can take a moment on a large category.'),
);
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>

    <div class="categories-app"
         data-edit-url="<?php echo $ajax('category_edit_iframe', false); ?>"
         data-enable-url="<?php echo $ajax('enable_category'); ?>"
         data-delete-url="<?php echo $ajax('delete_category'); ?>"
         data-move-url="<?php echo $ajax('category_move'); ?>"
         data-bulk-url="<?php echo $ajax('category_bulk'); ?>"
         data-preview-url="<?php echo $ajax('category_delete_preview', false); ?>"
         data-order-url="<?php echo $ajax('categories_order'); ?>"
         data-depth-limit="<?php echo $limit === null ? '' : (int)$limit; ?>"
         data-status="<?php echo osc_esc_html($status); ?>"
         data-i18n='<?php echo osc_esc_html(json_encode($i18n, JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>

<?php if ($rows === array()) { ?>
        <div class="cat-empty">
            <p><?php _e('Categories are the sections people browse and file listings under. '
                        . 'Add your first one to get started.'); ?></p>
            <button type="button" class="btn btn-submit btn-sm js-cat-add"><?php _e('Add category'); ?></button>
        </div>
<?php } else { ?>
        <?php osc_admin_tab_nav(array(
            array(
                'label'  => __('All'),
                'url'    => $base . '?page=categories',
                'active' => $status === '',
                'count'  => $counts['all'],
            ),
            array(
                'label'  => __('Enabled'),
                'url'    => $base . '?page=categories&status=on',
                'active' => $status === 'on',
                'count'  => $counts['on'],
            ),
            array(
                'label'  => __('Disabled'),
                'url'    => $base . '?page=categories&status=off',
                'active' => $status === 'off',
                'count'  => $counts['off'],
            ),
        ), 'cat-tabs'); ?>

        <?php osc_admin_toolbar_open(); ?>
            <?php osc_admin_bulk_actions(array(
                'id'      => 'cat-bulk',
                'form'    => 'cat-bulk-form',
                'options' => array(
                    array('value' => '', 'label' => osc_esc_html(__('Bulk actions'))),
                    array('value' => 'enable', 'label' => osc_esc_html(__('Enable'))),
                    array('value' => 'disable', 'label' => osc_esc_html(__('Disable'))),
                    array('value' => 'move', 'label' => osc_esc_html(__('Move to…'))),
                    array('value' => 'expiry', 'label' => osc_esc_html(__('Set expiry…'))),
                    array('value' => 'delete', 'label' => osc_esc_html(__('Delete'))),
                ),
            )); ?>
            <form id="cat-bulk-form" hidden></form>
            <p class="cat-selnote" hidden>
                <span><strong data-cat-selcount>0</strong> <?php _e('selected'); ?></span>
                <button type="button" class="btn-link" data-cat-act="clear-selection"><?php _e('Clear'); ?></button>
            </p>
            <div class="cat-tree-tools">
                <button type="button" class="btn-link" data-cat-act="expand-all"><?php _e('Expand all'); ?></button>
                <button type="button" class="btn-link" data-cat-act="collapse-all"><?php _e('Collapse all'); ?></button>
            </div>
            <div class="cat-search" role="search">
                <label class="visually-hidden" for="cat-q"><?php _e('Find a category'); ?></label>
                <input type="search" id="cat-q" class="form-control form-control-sm"
                       placeholder="<?php echo osc_esc_html(__('Find a category')); ?>" autocomplete="off">
            </div>
        <?php osc_admin_toolbar_close(); ?>

        <p class="cat-filter-line" id="cat-filter-line" role="status" hidden></p>

        <div class="table-contains-actions">
            <table class="table cat-table" aria-label="<?php echo osc_esc_html(__('Categories')); ?>">
                <thead>
                    <tr>
                        <th class="col-bulkactions">
                            <input type="checkbox" id="cat-check-all"
                                   aria-label="<?php echo osc_esc_html(__('Select all shown categories')); ?>">
                        </th>
                        <th class="col-status"><?php _e('Status'); ?></th>
                        <th class="col-name"><?php _e('Name'); ?></th>
                        <th class="col-subs cat-num"><?php _e('Subcategories'); ?></th>
                        <th class="col-listings cat-num"><?php _e('Listings'); ?></th>
                        <th class="col-expiry"><?php _e('Listings expire'); ?></th>
                    </tr>
                </thead>
                <tbody id="cat-rows">
<?php ob_start();
foreach ($rows as $row) {
    $id      = $row['id'];
    $name    = osc_esc_html($row['name']);
    $classes = 'cat-row' . ($row['enabled'] ? '' : ' is-disabled') . ($row['deep'] ? ' is-deep' : '');
    $meta    = array();
    if ($row['subs'] > 0) {
        $meta[] = sprintf(_n('%d subcategory', '%d subcategories', $row['subs']), $row['subs']);
    }
    $meta[] = $row['listings'] > 0
        ? sprintf(_n('%d listing', '%d listings', $row['listings']), $row['listings'])
        : __('No listings');
    $meta[] = $row['expiry'] > 0 ? sprintf(__('%d-day expiry'), $row['expiry']) : __('No expiry');
    ?>
                    <tr id="cat-<?php echo $id; ?>" class="<?php echo $classes; ?>" data-id="<?php echo $id; ?>"
                        data-parent="<?php echo $row['parent']; ?>" data-depth="<?php echo $row['depth']; ?>"
                        data-height="<?php echo $row['height']; ?>" data-subs="<?php echo $row['subs']; ?>"
                        data-below="<?php echo $row['below']; ?>" data-listings="<?php echo $row['listings']; ?>"
                        data-expiry="<?php echo $row['expiry']; ?>" data-enabled="<?php echo $row['enabled'] ? 1 : 0; ?>"
                        <?php echo $row['canAddChild'] ? '' : 'data-no-child '; ?>style="--cat-depth:<?php echo $row['depth'] - 1; ?>"<?php echo $row['depth'] > 1 ? ' hidden' : ''; ?>>
                        <td class="col-bulkactions"><input type="checkbox" value="<?php echo $id; ?>" aria-labelledby="cat-n-<?php echo $id; ?>"></td>
                        <td class="col-status"><?php if ($row['enabled']) { ?><span class="visually-hidden"><?php _e('Enabled'); ?></span><?php } else { ?><span class="cat-off"><i class="bi bi-dash-circle" aria-hidden="true"></i><?php _e('Disabled'); ?></span><?php } ?></td>
                        <td class="col-name">
                            <div class="cat-name-line">
                                <button type="button" class="cat-handle" aria-label="<?php echo osc_esc_html(__('Drag to reorder')); ?>" aria-describedby="cat-n-<?php echo $id; ?>"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
<?php if ($row['subs'] > 0) { ?>
                                <button type="button" class="cat-disclosure" aria-expanded="false" aria-label="<?php echo osc_esc_html(__('Show subcategories')); ?>" aria-describedby="cat-n-<?php echo $id; ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
<?php } else { ?>
                                <span class="cat-disclosure-spacer"></span>
<?php } ?>
                                <button type="button" class="cat-name" id="cat-n-<?php echo $id; ?>" data-cat-act="edit" dir="auto"><?php echo $name; ?></button>
<?php if ($row['deep']) { ?>
                                <span class="cat-deep"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span class="visually-hidden"><?php _e('Deeper than this theme shows'); ?></span></span>
<?php } ?>
                            </div>
                            <p class="cat-meta"><?php echo osc_esc_html(implode(' · ', $meta)); ?></p>
                            <div class="actions cat-actions">
                                <ul>
                                    <li><button type="button" data-cat-act="edit"><?php _e('Edit'); ?></button></li>
                                    <li><button type="button" data-cat-act="addsub"<?php echo $row['canAddChild'] ? '' : ' disabled title="' . osc_esc_html(sprintf(__('This theme shows %d levels of categories.'), (int)$limit)) . '"'; ?>><?php _e('Add subcategory'); ?></button></li>
                                    <li><button type="button" data-cat-act="move"><?php _e('Move to…'); ?></button></li>
                                    <li><button type="button" data-cat-act="toggle"><?php echo $row['enabled'] ? osc_esc_html(__('Disable')) : osc_esc_html(__('Enable')); ?></button></li>
                                    <li class="cat-actions-gap" aria-hidden="true"></li>
                                    <li><button type="button" class="row-action-danger" data-cat-act="delete"><?php _e('Delete'); ?></button></li>
                                </ul>
                            </div>
                        </td>
                        <td class="col-subs cat-num"><?php echo $row['subs'] > 0 ? $row['subs'] : '<span class="cat-nil">–</span>'; ?></td>
                        <td class="col-listings cat-num"><?php echo $row['listings']; ?></td>
                        <td class="col-expiry"><?php echo $row['expiry'] > 0
                            ? osc_esc_html(sprintf(_n('After %d day', 'After %d days', $row['expiry']), $row['expiry']))
                            : osc_esc_html(__('Never')); ?></td>
                    </tr>
<?php } // Rows are squeezed onto fewer bytes: a large tree is thousands of them.
echo preg_replace('/\s+/', ' ', preg_replace('/>\s+</', '><', (string)ob_get_clean())); ?>
                </tbody>
            </table>
        </div>

        <div class="cat-foot">
            <span id="cat-foot-sum"><?php echo osc_esc_html(sprintf(
                __('%1$s in %2$s · %3$s'),
                sprintf(_n('%d category', '%d categories', $sum['categories']), $sum['categories']),
                sprintf(_n('%d group', '%d groups', $sum['groups']), $sum['groups']),
                sprintf(_n('%d listing', '%d listings', $sum['listings']), $sum['listings'])
            )); ?></span>
            <?php osc_admin_form_open(array(
                'page'       => 'tools',
                'action'     => 'category_post',
                'horizontal' => false,
            )); ?>
                <button type="submit" class="btn-link"
                        title="<?php echo osc_esc_html(__('The counts also refresh once a day on their own.')); ?>">
                    <i class="bi bi-calculator" aria-hidden="true"></i>
                    <?php _e('Recount listings'); ?>
                </button>
            <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
        </div>
<?php } ?>

        <div class="osc-drawer-backdrop cat-drawer-backdrop" id="catDrawerBackdrop" hidden></div>
        <div class="osc-drawer cat-drawer" id="catDrawer" role="dialog" aria-modal="true"
               aria-labelledby="catDrawerTitle" hidden>
            <header class="osc-drawer-head cat-drawer-head">
                <div>
                    <h2 class="osc-drawer-title cat-drawer-title" id="catDrawerTitle"></h2>
                    <p id="catDrawerSub" class="cat-drawer-sub"></p>
                </div>
                <button type="button" class="osc-drawer-close cat-drawer-close" id="catDrawerClose"
                        aria-label="<?php echo osc_esc_html(__('Close')); ?>">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>
            <div class="osc-drawer-body cat-drawer-body" id="catDrawerBody"></div>
        </div>

        <dialog class="osc-dialog" id="catConfirm" aria-labelledby="catConfirmTitle">
            <div class="osc-dialog-body">
                <p class="osc-dialog-title" id="catConfirmTitle"></p>
                <p class="osc-dialog-text" id="catConfirmText"></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button type="button" class="btn btn-submit btn-sm" id="catConfirmOk"></button>
            </div>
        </dialog>
<?php if ($rows !== array()) { ?>

        <dialog class="osc-dialog" id="catMove" aria-labelledby="catMoveTitle">
            <form method="dialog" id="catMoveForm">
                <div class="osc-dialog-body">
                    <p class="osc-dialog-title" id="catMoveTitle"><?php _e('Move to…'); ?></p>
                    <div class="mb-3">
                        <label class="form-label" for="cat-move-parent"><?php _e('Put it under'); ?></label>
                        <select id="cat-move-parent" class="form-select form-select-sm"></select>
                    </div>
                    <div>
                        <label class="form-label" for="cat-move-pos"><?php _e('Position'); ?></label>
                        <select id="cat-move-pos" class="form-select form-select-sm"></select>
                        <p class="form-text" id="cat-move-hint"></p>
                    </div>
                </div>
                <div class="osc-dialog-actions">
                    <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                    <button type="submit" class="btn btn-submit btn-sm"><?php _e('Move'); ?></button>
                </div>
            </form>
        </dialog>

        <dialog class="osc-dialog" id="catExpiry" aria-labelledby="catExpiryTitle">
            <form method="dialog" id="catExpiryForm" novalidate>
                <div class="osc-dialog-body">
                    <p class="osc-dialog-title" id="catExpiryTitle"><?php _e('Set expiry…'); ?></p>
                    <?php CategoryForm::expiry_control(0, 'cat-bx', 'days'); ?>
                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="cat-bx-sub" name="to_subcategories" value="1">
                        <label class="form-check-label" for="cat-bx-sub" id="cat-bx-sub-label"></label>
                    </div>
                </div>
                <div class="osc-dialog-actions">
                    <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                    <button type="submit" class="btn btn-submit btn-sm"><?php _e('Set expiry…'); ?></button>
                </div>
            </form>
        </dialog>

        <template id="catParentOptions">
            <option value="0"><?php _e('Top level'); ?></option>
<?php foreach ($rows as $row) { ?>
            <option value="<?php echo $row['id']; ?>" data-depth="<?php echo $row['depth']; ?>" data-height="<?php echo $row['height']; ?>"><?php
                echo str_repeat("\u{00A0}\u{00A0}\u{00A0}\u{00A0}", $row['depth'] - 1) . osc_esc_html($row['name']);
            ?></option>
<?php } ?>
        </template>
<?php } ?>

        <p class="visually-hidden" id="cat-announce" aria-live="polite"></p>
    </div>

<?php osc_current_admin_theme_path('parts/footer.php'); ?>
