<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

$category    = __get('category');
$has_subcats = __get('has_subcategories');
$locales     = OSCLocale::newInstance()->listAllEnabled();
?>
<div class="iframe-category">
    <h3><?php _e('Edit category'); ?></h3>
    <form action="<?php echo osc_admin_base_url(true); ?>" method="post" data-cat-edit-form>
        <input type="hidden" name="page" value="ajax"/>
        <input type="hidden" name="action" value="edit_category_post"/>
        <?php CategoryForm::primary_input_hidden($category); ?>
        <fieldset>
            <div class="row g-3">
                <div class="col-12">
                    <div class="form-row">
                        <?php CategoryForm::multilanguage_name_description($locales, $category); ?>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-row">
                        <div class="cat-edit-group">
                            <label for="i_expiration_days"><?php _e('Expiration (days)'); ?></label>
                            <div class="input micro">
                                <?php CategoryForm::expiration_days_input_text($category); ?>
                                <p class="help-inline"><?php _e('Zero means listings in this category never expire.'); ?></p>
                                <label><?php CategoryForm::price_enabled_for_category($category); ?>
                                    <span><?php _e('Show the price field on listings in this category'); ?></span></label>
                                <?php if ($has_subcats) { ?>
                                    <label><?php CategoryForm::apply_changes_to_subcategories($category); ?>
                                        <span><?php _e('Apply the expiration and price changes to all subcategories'); ?></span></label>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-vertical">
                <div class="form-actions">
                    <input type="submit" class="btn btn-primary btn-sm"
                           value="<?php echo osc_esc_html(__('Save changes')); ?>"/>
                    <button type="button" class="btn btn-dim btn-sm" data-cat-drawer-cancel>
                        <?php echo osc_esc_html(__('Cancel')); ?>
                    </button>
                </div>
            </div>
        </fieldset>
    </form>
</div>
