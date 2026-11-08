<?php if (!defined('OC_ADMIN')) {
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

$category    = __get('category');
$has_subcats = __get('has_subcategories');
$below       = (int) __get('subcategory_count');
$parent      = __get('parent');
$parent      = is_array($parent) ? $parent : ['id' => 0, 'name' => ''];
$parent_id   = (int) ($parent['id'] ?? 0);
$self_id     = (int) ($category['pk_i_id'] ?? 0);
$days        = (int) ($category['i_expiration_days'] ?? 0);
$locales     = OSCLocale::getInstance()->listAllEnabled();
?>
<div class="iframe-category">
    <form action="<?php echo osc_admin_base_url(true); ?>" method="post" data-cat-edit-form>
        <input type="hidden" name="page" value="ajax"/>
        <input type="hidden" name="action" value="edit_category_post"/>
        <?php CategoryForm::primary_input_hidden($category); ?>
        <fieldset>
            <div class="row g-3">
                <div class="col-12">
                    <?php CategoryForm::multilanguage_name_description($locales, $category); ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="cat-parent"><?php _e('Parent'); ?></label>
                    <select name="parent_id" id="cat-parent" class="form-select form-select-sm" data-cat-parent
                            data-self="<?php echo $self_id; ?>" aria-describedby="cat-parent-hint">
                        <option value="<?php echo $parent_id; ?>" selected><?php
                            echo osc_esc_html($parent_id > 0 ? (string) $parent['name'] : __('Top level'));
                        ?></option>
                    </select>
                    <p class="form-text" id="cat-parent-hint" data-below="<?php echo $below; ?>"></p>
                </div>
                <div class="col-12">
                    <div class="cat-edit-group">
                        <h3 class="cat-drawer-section"><?php _e('Listings in this category'); ?></h3>
                        <div class="cat-field">
                            <span class="form-label" id="cat-exp-label"><?php _e('Listings expire'); ?></span>
                            <?php CategoryForm::expiry_control($days, 'cat-f'); ?>
                        </div>
                        <label class="cat-check"><?php CategoryForm::price_enabled_for_category($category); ?>
                            <span><?php _e('Show the price field on listings in this category'); ?></span></label>
                        <?php if ($has_subcats || $below > 0) { ?>
                            <div class="cat-apply" id="cat-apply-wrap">
                                <label class="cat-check"><?php CategoryForm::apply_changes_to_subcategories($category); ?>
                                    <span id="cat-apply-label"><?php
                                        echo osc_esc_html(sprintf(
                                            _n('Also apply to its %d subcategory', 'Also apply to its %d subcategories', $below),
                                            $below
                                        ));
                                    ?></span></label>
                                <p class="form-text" id="cat-apply-note"></p>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <div class="form-vertical">
                <div class="form-actions">
                    <input type="submit" class="btn btn-primary btn-sm"
                           value="<?php echo osc_esc_html(empty($category['pk_i_id']) ? __('Add category') : __('Save changes')); ?>"/>
                    <button type="button" class="btn btn-dim btn-sm" data-cat-drawer-cancel>
                        <?php echo osc_esc_html(__('Cancel')); ?>
                    </button>
                </div>
            </div>
        </fieldset>
    </form>
</div>
