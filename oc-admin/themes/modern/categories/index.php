<?php
if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_enqueue_script('sortablejs');
osc_enqueue_script('admin-categories');

$categories = __get('categories');

function addHelp()
{
    echo '<p>'
         . __('Add, edit or delete the categories in which users post listings. Drag a row by its handle to '
              . 'reorder it, or drop it onto another category to nest it as a subcategory.')
         . '</p>';
    echo '<p>'
         . __('Deleting a category also deletes every listing filed under it and its subcategories.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a href="#" class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box"
           data-bs-toggle="collapse"></a>
    </h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Categories &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

/**
 * One tree node: the row (handle, disclosure, name, counts, status, actions)
 * plus the always-present children list (empty for leaves, so a category can be
 * dropped in to nest it). All behaviour lives in categories.js; this only emits
 * markup and the data-* the script reads.
 *
 * @param array $category
 */
function drawCategory($category)
{
    $children  = isset($category['categories']) ? $category['categories'] : array();
    $num_subs  = count($children);
    $num_items = (int)($category['i_num_items'] ?? 0);
    $enabled   = (bool)$category['b_enabled'];
    $id        = (int)$category['pk_i_id'];
    $name      = $category['s_name'];

    $node_class = 'cat-node';
    if (!$enabled) {
        $node_class .= ' is-disabled';
    }
    if ($num_subs > 0) {
        // Start collapsed so a large tree opens tidy; the disclosure expands it.
        $node_class .= ' is-collapsed';
    }
    ?>
    <li class="<?php echo $node_class; ?>" id="cat-<?php echo $id; ?>" data-cat-id="<?php echo $id; ?>"
        data-enabled="<?php echo $enabled ? '1' : '0'; ?>" data-num-items="<?php echo $num_items; ?>"
        data-name="<?php echo osc_esc_html($name); ?>">
        <div class="cat-row">
            <button type="button" class="cat-handle" tabindex="-1" aria-hidden="true"
                    title="<?php echo osc_esc_html(__('Drag to reorder')); ?>">
                <i class="bi bi-grip-vertical"></i>
            </button>
            <?php if ($num_subs > 0) { ?>
                <button type="button" class="cat-disclosure" aria-expanded="false"
                        aria-label="<?php echo osc_esc_html(__('Show subcategories')); ?>">
                    <i class="bi bi-chevron-down"></i>
                </button>
            <?php } else { ?>
                <span class="cat-disclosure-spacer" aria-hidden="true"></span>
            <?php } ?>
            <div class="cat-main">
                <span class="cat-name"><?php echo osc_esc_html($name); ?></span>
                <span class="cat-meta">
                    <?php if ($num_subs > 0) { ?>
                        <span class="cat-meta-item" title="<?php echo osc_esc_html(__('Subcategories')); ?>">
                            <i class="bi bi-diagram-3"></i><?php echo osc_esc_html(sprintf(
                                _n('%d subcategory', '%d subcategories', $num_subs), $num_subs)); ?>
                        </span>
                    <?php } ?>
                    <?php if ($num_items > 0) { ?>
                        <span class="cat-meta-item" title="<?php echo osc_esc_html(__('Listings in this category')); ?>">
                            <i class="bi bi-card-list"></i><?php echo osc_esc_html(sprintf(
                                _n('%d listing', '%d listings', $num_items), $num_items)); ?>
                        </span>
                    <?php } ?>
                </span>
            </div>
            <span class="cat-status <?php echo $enabled ? 'cat-status-on' : 'cat-status-off'; ?>">
                <?php echo $enabled ? osc_esc_html(__('Enabled')) : osc_esc_html(__('Disabled')); ?>
            </span>
            <div class="cat-actions">
                <button type="button" class="cat-action" data-cat-act="edit"
                        aria-label="<?php echo osc_esc_html(sprintf(__('Edit %s'), $name)); ?>"
                        title="<?php echo osc_esc_html(__('Edit')); ?>">
                    <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="cat-action" data-cat-act="toggle"
                        aria-label="<?php echo osc_esc_html(sprintf(
                            $enabled ? __('Disable %s') : __('Enable %s'), $name)); ?>"
                        title="<?php echo $enabled ? osc_esc_html(__('Disable')) : osc_esc_html(__('Enable')); ?>">
                    <i class="bi <?php echo $enabled ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                </button>
                <button type="button" class="cat-action cat-action-danger" data-cat-act="delete"
                        aria-label="<?php echo osc_esc_html(sprintf(__('Delete %s'), $name)); ?>"
                        title="<?php echo osc_esc_html(__('Delete')); ?>">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>
        </div>
        <ul class="cat-children">
            <?php foreach ($children as $subcategory) {
                drawCategory($subcategory);
            } ?>
        </ul>
    </li>
    <?php
} //End drawCategory

$add_url = osc_admin_base_url(true) . '?page=categories&amp;action=add_post_default&amp;' . osc_csrf_token_url();
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Categories'); ?></h2>

    <div class="categories-app"
         data-edit-url="<?php echo osc_esc_html(osc_admin_base_url(true)
             . '?page=ajax&action=category_edit_iframe'); ?>"
         data-enable-url="<?php echo osc_esc_html(osc_admin_base_url(true)
             . '?page=ajax&action=enable_category&' . osc_csrf_token_url()); ?>"
         data-delete-url="<?php echo osc_esc_html(osc_admin_base_url(true)
             . '?page=ajax&action=delete_category&' . osc_csrf_token_url()); ?>"
         data-order-url="<?php echo osc_esc_html(osc_admin_base_url(true)
             . '?page=ajax&action=categories_order&' . osc_csrf_token_url()); ?>"
         data-i18n='<?php echo osc_esc_html(json_encode(array(
             'deleteTitle'   => __('Delete category'),
             'deleteOne'     => __('Delete <strong>%s</strong>? This permanently removes the category and every listing filed under it. This cannot be undone.'),
             'deleteSubs'    => __('Delete <strong>%1$s</strong>? This permanently removes it, its %2$d subcategories, and all %3$d listings filed under them. This cannot be undone.'),
             'deleteNoItems' => __('Delete <strong>%s</strong>? This permanently removes the category. This cannot be undone.'),
             'confirm'       => __('Delete category'),
             'cancel'        => __('Cancel'),
             'working'       => __('This can take a moment on a large category.'),
             'ajaxError'     => __('Something went wrong. Please try again.'),
             'enabled'       => __('Enabled'),
             'disabled'      => __('Disabled'),
             'enable'        => __('Enable'),
             'disable'       => __('Disable'),
             'editPrefix'    => __('Edit category'),
         ), JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>

        <div class="cat-toolbar">
            <button type="button" class="cat-tool-btn" data-cat-act="expand-all"><?php _e('Expand all'); ?></button>
            <button type="button" class="cat-tool-btn" data-cat-act="collapse-all"><?php _e('Collapse all'); ?></button>
            <div class="cat-toolbar-spacer"></div>
            <a class="btn btn-primary btn-sm" href="<?php echo $add_url; ?>">
                <i class="bi bi-plus-lg"></i> <?php _e('Add category'); ?>
            </a>
        </div>

        <?php if (empty($categories)) { ?>
            <div class="cat-empty">
                <i class="bi bi-diagram-3"></i>
                <p class="cat-empty-title"><?php _e('No categories yet'); ?></p>
                <p class="cat-empty-hint">
                    <?php _e('Categories are the sections people browse and file listings under. Add your first one to get started.'); ?>
                </p>
                <a class="btn btn-primary btn-sm" href="<?php echo $add_url; ?>">
                    <i class="bi bi-plus-lg"></i> <?php _e('Add category'); ?>
                </a>
            </div>
        <?php } else { ?>
            <ul class="cat-tree">
                <?php foreach ($categories as $category) {
                    drawCategory($category);
                } ?>
            </ul>
        <?php } ?>

        <!-- Edit drawer -->
        <div class="cat-drawer-backdrop" id="catDrawerBackdrop" hidden></div>
        <aside class="cat-drawer" id="catDrawer" role="dialog" aria-modal="true"
               aria-labelledby="catDrawerTitle" hidden>
            <header class="cat-drawer-head">
                <div>
                    <span class="cat-drawer-eyebrow"><?php _e('Edit category'); ?></span>
                    <h2 class="cat-drawer-title" id="catDrawerTitle"></h2>
                </div>
                <button type="button" class="cat-drawer-close" id="catDrawerClose"
                        aria-label="<?php echo osc_esc_html(__('Close')); ?>">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>
            <div class="cat-drawer-body" id="catDrawerBody"></div>
        </aside>

        <!-- Delete confirmation -->
        <dialog class="osc-dialog osc-dialog-danger" id="catDeleteDialog">
            <div class="osc-dialog-body">
                <p class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span id="catDeleteTitle"><?php _e('Delete category'); ?></span>
                </p>
                <p class="osc-dialog-text" id="catDeleteText"></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" id="catDeleteCancel"><?php _e('Cancel'); ?></button>
                <button type="button" class="btn btn-danger btn-sm" id="catDeleteConfirm"><?php _e('Delete category'); ?></button>
            </div>
        </dialog>
    </div>

<?php osc_current_admin_theme_path('parts/footer.php'); ?>
