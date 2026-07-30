<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$keyword = __get('keyword');

/**
 * @return array
 */
function customFrmText()
{
    $keyword = __get('keyword');
    $return  = array();

    if (isset($keyword['pk_i_id'])) {
        $return['edit']       = true;
        $return['title']      = __('Edit keyword');
        $return['action_frm'] = 'keyword_block_edit_post';
        $return['btn_text']   = __('Update keyword');
    } else {
        $return['edit']       = false;
        $return['title']      = __('Add new keyword');
        $return['action_frm'] = 'keyword_block_add_post';
        $return['btn_text']   = __('Add new keyword');
    }

    return $return;
}


function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?></h1>
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
    $aux = customFrmText();

    return sprintf('%s &raquo; %s', $aux['title'], $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$aux = customFrmText();
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<h2 class="render-title"><?php echo osc_esc_html($aux['title']); ?></h2>
<div class="settings-user">
    <ul id="error_list"></ul>
    <form name="keyword_block_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="<?php echo osc_esc_html($aux['action_frm']); ?>"/>
        <?php KeywordBlockForm::primary_input_hidden($keyword); ?>
        <fieldset>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Keyword'); ?></div>
                    <div class="form-controls">
                        <?php KeywordBlockForm::keyword_text($keyword); ?>
                        <div class="help-box">
                            <?php printf(
                                __('At least %d characters. Matched as a whole word unless "Substring" is checked below.'),
                                ItemSpamFilter::MIN_KEYWORD_LENGTH
                            ); ?>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Where to match'); ?></div>
                    <div class="form-controls">
                        <?php KeywordBlockForm::scope_select($keyword); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Substring'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <?php KeywordBlockForm::substring_checkbox($keyword); ?>
                            <label for="b_substring"><?php _e('Match anywhere inside a word, not just whole words'); ?></label>
                        </div>
                        <div class="help-box text-danger">
                            <?php _e('Broader and riskier — leave unchecked unless you specifically need to catch a fragment inside longer words.'); ?>
                        </div>
                    </div>
                </div>
                <div class="clear"></div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit"><?php echo osc_esc_html($aux['btn_text']); ?></button>
                    <a class="btn btn-dim" href="<?php echo osc_admin_base_url(true); ?>?page=settings&action=keyword_block"><?php _e('Cancel'); ?></a>
                </div>
            </div>
        </fieldset>
    </form>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
