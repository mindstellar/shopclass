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
 * The blocked-keyword form's add/edit copy.
 *
 * @return array{edit:bool,title:string,action_frm:string,btn_text:string}
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

osc_admin_page(array(
    'section' => __('Settings'),
));

/**
 * Filter callback for `admin_title`: prefix the browser title with the form's title.
 *
 * @param string $string
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
<?php osc_admin_page_head($aux['title']); ?>
<div class="settings-user">
    <ul id="error_list"></ul>
    <?php osc_admin_form_open(array(
        'name'   => 'keyword_block_form',
        'page'   => 'settings',
        'action' => $aux['action_frm'],
        'fields' => array('id' => $keyword['pk_i_id'] ?? ''),
    )); ?>
                <?php osc_admin_text(array(
                    'name'  => 's_keyword',
                    'label' => __('Keyword'),
                    'value' => $keyword['s_keyword'] ?? '',
                    'help'  => sprintf(
                        __('At least %d characters. Matched as a whole word unless "Substring" is checked below.'),
                        ItemSpamFilter::MIN_KEYWORD_LENGTH
                    ),
                )); ?>
                <?php osc_admin_select(array(
                    'name'     => 's_scope',
                    'label'    => __('Where to match'),
                    'selected' => $keyword['s_scope'] ?? 'all',
                    'options'  => array(
                        'title'       => __('Title only'),
                        'description' => __('Description only'),
                        'all'         => __('Title and description'),
                        'meta'        => __('Custom fields'),
                    ),
                )); ?>
                <?php osc_admin_form_row_open(__('Substring')); ?>
                        <?php osc_admin_checkbox(array(
                            'name'      => 'b_substring',
                            'id'        => 'b_substring',
                            'value'     => '1',
                            'label'     => __('Match anywhere inside a word, not just whole words'),
                            'checked'   => isset($keyword['b_substring']) && (int)$keyword['b_substring'] === 1,
                            'help_html' => '<span class="text-danger">' . __('Broader and riskier — leave unchecked unless you specifically need to catch a fragment inside longer words.') . '</span>',
                        )); ?>
                <?php osc_admin_form_row_close(); ?>
                <?php osc_admin_form_close(array(
                    array('label' => $aux['btn_text'], 'type' => 'submit', 'variant' => 'primary'),
                    array(
                        'label'   => __('Cancel'),
                        'variant' => 'dim',
                        'url'     => osc_admin_base_url(true) . '?page=settings&action=keyword_block',
                    ),
                )); ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
