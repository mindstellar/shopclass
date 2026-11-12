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
        $bold = static fn (string $label): string => '<strong>' . osc_esc_html($label) . '</strong>';
        $kbd  = static fn (string $key, string $class = ''): string => '<kbd class="cat-k' . ($class !== '' ? ' ' . $class : '') . '">' . $key . '</kbd>';
        $rtl  = osc_admin_locale_text_direction() === 'rtl';
        $grip = '<i class="bi bi-grip-vertical cat-help-grip" aria-hidden="true"></i>'; ?>
        <ul>
            <li><?php printf(
                __('%1$s drag the %2$s handle up or down; while dragging, move it right to put it under the category above, or left to move it out. On a phone, press and hold the handle first. With a keyboard, focus the handle: %3$s move it, %4$s puts it under the category above, %5$s moves it out of its group. Or use %6$s.'),
                $bold(__('Order and nesting:')),
                $grip,
                $kbd('↑') . ' ' . $kbd('↓'),
                $kbd($rtl ? '←' : '→', 'cat-k-in'),
                $kbd($rtl ? '→' : '←', 'cat-k-out'),
                '<em>' . osc_esc_html(__('Move to…')) . '</em>'
            ); ?></li>
            <li><?php printf(__('%s hides a category and its subcategories from your site. Nothing is deleted.'), $bold(__('Disable'))); ?></li>
            <li><?php printf(__('%s removes the category, its subcategories and every listing in them.'), $bold(__('Delete'))); ?></li>
        </ul>
    <?php },
));

$base = osc_admin_base_url(true);
$ajax = static fn (string $action, bool $csrf = true): string =>
    $base . '?page=ajax&action=' . $action . ($csrf ? '&' . osc_csrf_token_url() : '');

$plural = static fn (callable $form): string|array => \mindstellar\admin\AdminText::pluralForms($form);

$i18n = array(
    'delete'           => __('Delete'),
    'ajaxError'        => __('Something went wrong. Try again.'),
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
    'never'            => __('Never'),
    'clearSearch'      => __('Clear search'),
    'showAll'          => __('Show all'),
    'addAs'            => __('Add “%s” as a category'),
    'noMatch'          => __('No category matches “%s”'),
    'showSubs'         => __('Show subcategories'),
    'hideSubs'         => __('Hide subcategories'),
    'depthNote'        => $limit === null ? '' : sprintf(_n('This theme shows %d level of categories.', 'This theme shows %d levels of categories.', $limit), $limit),
    'footSum'          => __('%1$s in %2$s · %3$s'),
    'categoriesN'      => $plural(static fn (int $n): string => _n('%d category', '%d categories', $n)),
    'groupsN'          => $plural(static fn (int $n): string => _n('%d group', '%d groups', $n)),
    'listingsN'        => $plural(static fn (int $n): string => _n('%d listing', '%d listings', $n)),
    'subcategoriesN'   => $plural(static fn (int $n): string => _n('%d subcategory', '%d subcategories', $n)),
    'daysN'            => $plural(static fn (int $n): string => _n('After %d day', 'After %d days', $n)),
    'filterMatch'      => $plural(static fn (int $n): string => _n('%d category matches “%s”.', '%d categories match “%s”.', $n)),
    'filterTabOn'      => $plural(static fn (int $n): string => _n('Showing %d enabled category.', 'Showing %d enabled categories.', $n)),
    'filterTabOff'     => $plural(static fn (int $n): string => _n('Showing %d disabled category.', 'Showing %d disabled categories.', $n)),
    'filterGrey'       => __('Their groups are shown in grey. Clear the search and filter to reorder.'),
    'filterReorder'    => __('Clear the search and status filter to reorder.'),
    'noMatchIn'        => __('No category matches “%1$s” in %2$s'),
    'noMatchHint'      => __('Check the spelling, or search for part of the name.'),
    'allShowing'       => __('Every category is showing on your site.'),
    'allDisabled'      => __('Every category is disabled, so sellers cannot post.'),
    'noListings'       => __('No listings'),
    'expiryTag'        => __('%d-day expiry'),
    'emptyOn'          => __('No enabled categories.'),
    'emptyOff'         => __('No disabled categories.'),
    'tooDeepDrag'      => $limit === null ? '' : sprintf(_n('Too deep. This theme shows %d level of categories.', 'Too deep. This theme shows %d levels of categories.', $limit), $limit),
    'moreSubs'         => $plural(static fn (int $n): string => _n('+ %d subcategory', '+ %d subcategories', $n)),
    'hintMove'         => __('move up or down'),
    'hintIn'           => __('put under the category above'),
    'hintOut'          => __('move out of its group'),
    'movedUp'          => __('%1$s moved up. Now %2$d of %3$d in %4$s.'),
    'movedDown'        => __('%1$s moved down. Now %2$d of %3$d in %4$s.'),
    'movedUnder'       => __('%1$s is now under %2$s, last of %3$d.'),
    'movedOut'         => __('%1$s moved out of %2$s. Now in %3$s, after %2$s.'),
    'movedTo'          => __('Moved %1$s to %2$s, %3$d of %4$d.'),
    'alreadyFirst'     => __('%1$s is already first in %2$s.'),
    'alreadyLast'      => __('%1$s is already last in %2$s.'),
    'nothingAbove'     => __('Nothing above %s to put it under.'),
    'alreadyTop'       => __('%s is already at the top level.'),
    'notMoved'         => __('Not moved.'),
    'moveCancelled'    => __('Move cancelled.'),
    'undo'             => __('Undo'),
    'undone'           => __('Undone.'),
    'close'            => __('Close'),
    'applyLabel'       => $plural(static fn (int $n): string => _n('Also apply to its %d subcategory', 'Also apply to its %d subcategories', $n)),
    'applyOn'          => __('Every subcategory gets this setting too.'),
    'applyOff'         => __('Left off, each subcategory keeps its own setting.'),
    'applyChange'      => $plural(static fn (int $n): string => _n('%d of them changes.', '%d of them change.', $n)),
    'applyNoChange'    => __('None of them change.'),
    'applyDiffer'      => $plural(static fn (int $n): string => _n('%d subcategory differs from this one now.', '%d subcategories differ from this one now.', $n)),
    'applyMatch'       => __('They all match this one now.'),
    'redateNew'        => __('Give them new end dates'),
    'redateNever'      => __('Remove their end dates'),
    'redateHintNever'  => __('Their end dates are removed, so they stay up until taken down.'),
    'redateHintDays'   => $plural(static fn (int $n): string => _n('Each one ends %d day after it was published.', 'Each one ends %d days after it was published.', $n)),
    'disableTitle'     => __('Disable %s?'),
    'disableSelTitle'  => $plural(static fn (int $n): string => _n('Disable %d selected category?', 'Disable %d selected categories?', $n)),
    'disableSubs'      => $plural(static fn (int $n): string => _n('Its %d subcategory is disabled too.', 'Its %d subcategories are disabled too.', $n)),
    'withSubs'         => $plural(static fn (int $n): string => _n('With their subcategories, that is %d category.', 'With their subcategories, that is %d categories.', $n)),
    'disableListings'  => $plural(static fn (int $n): string => _n(
        '%d listing stops showing on your site, and sellers can\'t post here.',
        '%d listings stop showing on your site, and sellers can\'t post here.',
        $n
    )),
    'disableNoListings' => __('It has no listings. Sellers can\'t post here while it is disabled.'),
    'disableSafe'      => __('Nothing is deleted. You can enable it again at any time.'),
    'disableButton'    => $plural(static fn (int $n): string => _n('Disable %d category', 'Disable %d categories', $n)),
    'alreadyOff'       => __('Those categories are already disabled.'),
    'disabledOne'      => __('Disabled %s.'),
    // translators: 1: category name, 2: number of subcategories
    'disabledSubs'     => $plural(static fn (int $n): string => _n('Disabled %1$s and %2$d subcategory.', 'Disabled %1$s and %2$d subcategories.', $n)),
    'enabledOne'       => __('Enabled %s.'),
    // translators: 1: category name, 2: number of subcategories
    'enabledSubs'      => $plural(static fn (int $n): string => _n('Enabled %1$s and %2$d subcategory.', 'Enabled %1$s and %2$d subcategories.', $n)),
    'stillHidden'      => __('It stays hidden until %s is enabled.'),
    'deleteConfirm'    => __('Delete %s?'),
    'deleteSelTitle'   => $plural(static fn (int $n): string => _n('Delete %d selected category?', 'Delete %d selected categories?', $n)),
    'deleteSubs'       => $plural(static fn (int $n): string => _n('Its %d subcategory is deleted too.', 'Its %d subcategories are deleted too.', $n)),
    'deleteListings'   => $plural(static fn (int $n): string => _n(
        '%d listing is deleted, along with any expired or unapproved ones.',
        '%d listings are deleted, along with any expired or unapproved ones.',
        $n
    )),
    'deleteNoListings' => __('It has no listings. Any expired or unapproved ones are deleted.'),
    'deleteForever'    => __('This can\'t be undone.'),
    'deleteButton'     => $plural(static fn (int $n): string => _n('Delete %d category', 'Delete %d categories', $n)),
    'deleteAside'      => __('Want to keep the listings? Disable instead. It hides everything and deletes nothing.'),
    'disableInstead'   => __('Disable instead'),
    'deletedDone'      => __('Deleted %s.'),
    'overlapOne'       => __('%1$s is inside %2$s, so it is counted once.'),
    'overlapMany'      => $plural(static fn (int $n): string => _n(
        '%d of the selected is inside another selected category, so it is counted once.',
        '%d of the selected are inside other selected categories, so each is counted once.',
        $n
    )),
    'deleteQueued'     => __('Deleting in the background. This can take a moment.'),
    'expiryFor'        => __('Set expiry for %s'),
    'expiryForMany'    => $plural(static fn (int $n): string => _n('Set expiry for %d category', 'Set expiry for %d categories', $n)),
    'expiryNow'        => __('Now: %s'),
    'expiryNowAfter'   => $plural(static fn (int $n): string => _n('after %d day', 'after %d days', $n)),
    'expiryNowNever'   => __('never expire'),
    'expiryMixed'      => __('Mixed (%s)'),
    'expiryDays'       => $plural(static fn (int $n): string => _n('%d day', '%d days', $n)),
    'expirySet'        => __('Set on %s'),
    'expiryNoListOne'  => __('It has no listings now. New listings get it from now on.'),
    'expiryNoListMany' => __('They have no listings now. New listings get it from now on.'),
    'endToday'         => $plural(static fn (int $n): string => _n('%d listing ends today.', '%d listings end today.', $n)),
    'expiryDoneAfter'  => __('Listings in %1$s now expire after %2$s.'),
    'expiryDoneNever'  => __('Listings in %s now never expire.'),
    'postedKeep'       => __('Listings already posted keep their end dates.'),
    'postedNone'       => __('Listings already posted no longer have end dates.'),
    'postedNew'        => __('Listings already posted got new end dates.'),
    'postedNewEnded'   => $plural(static fn (int $n): string => _n(
        'Listings already posted got new end dates; %d listing ended today.',
        'Listings already posted got new end dates; %d listings ended today.',
        $n
    )),
);
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>

<?php osc_admin_body_open(array(
    'class' => 'categories-app',
    'attrs' => array(
        'data-edit-url'           => $ajax('category_edit_iframe', false),
        'data-enable-url'         => $ajax('enable_category'),
        'data-delete-url'         => $ajax('delete_category'),
        'data-move-url'           => $ajax('category_move'),
        'data-bulk-url'           => $ajax('category_bulk'),
        'data-preview-url'        => $ajax('category_delete_preview', false),
        'data-expiry-preview-url' => $ajax('category_expiry_preview', false),
        'data-depth-limit'        => $limit === null ? '' : (string)(int)$limit,
        'data-status'             => $status,
        'data-i18n'               => json_encode($i18n, JSON_HEX_APOS | JSON_HEX_QUOT),
    ),
)); ?>

<?php if ($rows === array()) { ?>
        <div class="cat-empty">
            <p><?php _e('Categories are the sections people browse and file listings under. '
                        . 'Add your first one to get started.'); ?></p>
            <button type="button" class="btn btn-submit btn-sm js-cat-add"><?php _e('Add category'); ?></button>
        </div>
<?php } else { ?>
        <p class="cat-intro"><?php _e('Sellers file each listing under one of these. Drag a row by its handle, or focus the handle and use the arrow keys, to change the order.'); ?></p>

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

        <p class="cat-filter-line" id="cat-filter-line" hidden></p>

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
    // Below the top level, the name and handle are described as "Inside {parent}": the shared words
    // around the parent's own name button, so a row adds no text of its own.
    $inside  = $row['depth'] > 1 ? 'cat-i1 cat-n-' . $row['parent'] . ' cat-i2' : '';
    $classes = 'cat-row' . ($row['enabled'] ? '' : ' is-disabled') . ($row['deep'] ? ' is-deep' : '');
    $meta    = array();
    if ($row['subs'] > 0) {
        $meta[] = sprintf(_n('%d subcategory', '%d subcategories', $row['subs']), $row['subs']);
    }
    $meta[] = $row['listings'] > 0
        ? sprintf(_n('%d listing', '%d listings', $row['listings']), $row['listings'])
        : __('No listings');
    if ($row['expiry'] > 0) {
        $meta[] = sprintf(__('%d-day expiry'), $row['expiry']);
    }
    ?>
                    <tr id="cat-<?php echo $id; ?>" class="<?php echo $classes; ?>" data-id="<?php echo $id; ?>"
                        data-parent="<?php echo $row['parent']; ?>" data-depth="<?php echo $row['depth']; ?>"
                        data-height="<?php echo $row['height']; ?>" data-subs="<?php echo $row['subs']; ?>"
                        data-below="<?php echo $row['below']; ?>" data-listings="<?php echo $row['listings']; ?>"
                        data-expiry="<?php echo $row['expiry']; ?>" data-price="<?php echo $row['price'] ? 1 : 0; ?>" data-enabled="<?php echo $row['enabled'] ? 1 : 0; ?>"
                        <?php echo $row['canAddChild'] ? '' : 'data-no-child '; ?>style="--cat-depth:<?php echo $row['depth'] - 1; ?>"<?php echo $row['depth'] > 1 ? ' hidden' : ''; ?>>
                        <td class="col-bulkactions"><input type="checkbox" value="<?php echo $id; ?>" aria-labelledby="cat-n-<?php echo $id; ?>"></td>
                        <td class="col-status"><?php if ($row['enabled']) { ?><span class="visually-hidden"><?php _e('Enabled'); ?></span><?php } else { ?><span class="cat-off"><i class="bi bi-dash-circle" aria-hidden="true"></i><?php _e('Disabled'); ?></span><?php } ?></td>
                        <td class="col-name">
                            <div class="cat-name-line">
                                <button type="button" class="cat-handle" aria-labelledby="cat-reorder cat-n-<?php echo $id; ?>" aria-describedby="<?php echo $inside !== '' ? $inside . ' ' : ''; ?>cat-key-help"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
<?php if ($row['subs'] > 0) { ?>
                                <button type="button" class="cat-disclosure" aria-expanded="false" aria-label="<?php echo osc_esc_html(__('Show subcategories')); ?>" aria-describedby="cat-n-<?php echo $id; ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
<?php } else { ?>
                                <span class="cat-disclosure-spacer"></span>
<?php } ?>
                                <button type="button" class="cat-name" id="cat-n-<?php echo $id; ?>" data-cat-act="edit" dir="auto"<?php echo $inside !== '' ? ' aria-describedby="' . $inside . '"' : ''; ?>><?php echo $name; ?></button>
<?php if ($row['deep']) { ?>
                                <span class="cat-deep"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span class="visually-hidden"><?php _e('Deeper than this theme shows'); ?></span></span>
<?php } ?>
                                <button type="button" class="cat-more" data-cat-act="more" aria-label="<?php echo osc_esc_html(__('Actions')); ?>" aria-describedby="cat-n-<?php echo $id; ?>" aria-haspopup="dialog"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                            </div>
                            <p class="cat-meta"><?php echo osc_esc_html(implode(' · ', $meta)); ?></p>
                            <div class="actions cat-actions">
                                <ul>
                                    <li><button type="button" data-cat-act="edit"><?php _e('Edit'); ?></button></li>
                                    <li><button type="button" data-cat-act="addsub"<?php echo $row['canAddChild'] ? '' : ' disabled title="' . osc_esc_html(sprintf(_n('This theme shows %d level of categories.', 'This theme shows %d levels of categories.', (int)$limit), (int)$limit)) . '"'; ?>><?php _e('Add subcategory'); ?></button></li>
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

        <?php osc_admin_confirm_dialog(array(
            'id'         => 'catConfirm',
            'form'       => false,
            'tone'       => 'neutral',
            'attrs'      => array('aria-labelledby' => 'catConfirmTitle', 'aria-describedby' => 'catConfirmFacts'),
            'title_id'   => 'catConfirmTitle',
            'cancel_id'  => 'catConfirmCancel',
            'confirm_id' => 'catConfirmOk',
            'confirm'    => '',
            'body_html'  => '<ul class="osc-dialog-facts" id="catConfirmFacts"></ul>'
                . '<div class="cat-confirm-aside" id="catConfirmAside" hidden><p></p>'
                . '<button type="button" class="btn btn-dim btn-sm"></button></div>',
        )); ?>
<?php if ($rows !== array()) { ?>

        <dialog class="osc-dialog cat-sheet" id="catSheet" aria-labelledby="catSheetTitle">
            <p class="cat-sheet-title" id="catSheetTitle" dir="auto"></p>
            <ul class="cat-sheet-list">
                <li><button type="button" data-cat-act="edit"><?php _e('Edit'); ?></button></li>
                <li><button type="button" data-cat-act="addsub"><?php _e('Add subcategory'); ?></button></li>
                <li><button type="button" data-cat-act="move"><?php _e('Move to…'); ?></button></li>
                <li><button type="button" data-cat-act="toggle"><?php _e('Disable'); ?></button></li>
                <li class="cat-sheet-sep" role="presentation"></li>
                <li><button type="button" class="cat-sheet-danger" data-cat-act="delete"><?php _e('Delete'); ?></button></li>
                <li class="cat-sheet-sep" role="presentation"></li>
                <li><button type="button" class="cat-sheet-cancel" data-osc-dialog-close><?php _e('Cancel'); ?></button></li>
            </ul>
        </dialog>

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
                    <p class="cat-exp-now" id="catExpiryNow"></p>
                    <?php CategoryForm::expiry_control(0, 'cat-bx', 'days', false); ?>
                    <div class="form-check cat-bx-sub">
                        <input type="checkbox" class="form-check-input" id="cat-bx-sub" name="to_subcategories" value="1" aria-describedby="cat-bx-sub-note">
                        <label class="form-check-label" for="cat-bx-sub" id="cat-bx-sub-label"></label>
                        <p class="form-text" id="cat-bx-sub-note"></p>
                    </div>
                    <?php CategoryForm::expiry_posted('cat-bx'); ?>
                    <ul class="osc-dialog-facts" id="catExpiryFacts" hidden></ul>
                </div>
                <div class="osc-dialog-actions">
                    <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                    <button type="submit" class="btn btn-submit btn-sm" id="catExpiryOk"><?php _e('Set expiry…'); ?></button>
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
<?php
// A translation that drops the placeholder falls back to the English words.
$inside_words = preg_split('/%(?:1\$)?s/', __('Inside %s'), 2);
if (count($inside_words) < 2) {
    $inside_words = array('Inside', '');
}
?>
        <span class="visually-hidden" id="cat-reorder"><?php _e('Reorder'); ?></span>
        <span class="visually-hidden" id="cat-i1"><?php echo osc_esc_html(trim($inside_words[0])); ?></span>
        <span class="visually-hidden" id="cat-i2"><?php echo osc_esc_html(trim($inside_words[1] ?? '')); ?></span>
        <p class="visually-hidden" id="cat-key-help"><?php _e('Use the arrow keys to move it: up or down to reorder, sideways to put it under the category above or move it out of its group.'); ?></p>
<?php osc_admin_body_close(); ?>

<?php osc_current_admin_theme_path('parts/footer.php'); ?>
