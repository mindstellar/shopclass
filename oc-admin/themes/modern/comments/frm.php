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

$comment = __get('comment');

if (isset($comment['pk_i_id'])) {
    //editing...
    $title      = __('Edit comment');
    $action_frm = 'comment_edit_post';
    $btn_text   = __('Save changes');
} else {
    //adding...
    $title      = __('Add comment');
    $action_frm = 'add_comment_post';
    $btn_text   = __('Add comment');
}

osc_admin_page(array(
    'section' => osc_mode_text(__('Listing'), __('Product')),
    'heading' => $title,
    'title'   => __('Edit comment'),
));

//customize Head
/**
 * Emit the comment form's client-side validation.
 *
 * @return void
 */
function customHead()
{
    CommentForm::js_validation(true);
}

osc_add_hook('admin_header', 'customHead', 10);

$comment = __get('comment');
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(array('id' => 'language-form')); ?>
        <ul id="error_list"></ul>
        <?php osc_admin_form_open(array(
            'name'       => 'language_form',
            'page'       => 'comments',
            'action'     => $action_frm,
            'fields'     => array('id' => isset($comment['pk_i_id']) ? $comment['pk_i_id'] : ''),
        )); ?>
                <?php osc_admin_form_row_open(__('Title'), array('for' => 'title')); ?>
                        <?php CommentForm::title_input_text($comment); ?>
                <?php osc_admin_form_row_close(); ?>
                <?php osc_admin_form_row_open(__('Author'), array('for' => 'authorName')); ?>
                        <?php CommentForm::author_input_text($comment); ?>
                        <?php if (isset($comment['fk_i_user_id']) && $comment['fk_i_user_id'] != '') {
                            _e('Registered user'); ?>
                            <a href="<?php echo osc_admin_base_url(true); ?>?page=users&action=edit&id=<?php echo
                            $comment['fk_i_user_id']; ?>"><?php _e('Edit user'); ?></a>
                        <?php } ?>
                <?php osc_admin_form_row_close(); ?>
                <?php osc_admin_form_row_open(__("Author's e-mail"), array('for' => 'authorEmail')); ?>
                        <?php CommentForm::email_input_text($comment); ?>
                <?php osc_admin_form_row_close(); ?>
                <?php osc_admin_form_row_open(_x('Visible', 'comment is shown on the site')); ?>
                    <div class="form-label-checkbox">
                        <?php osc_admin_status($comment['b_active'] ? 'active' : 'inactive', $comment['b_active'] ? __('Active') : __('Inactive'));
                        osc_admin_action_button(array(
                            'label' => $comment['b_active'] == 1 ? __('Deactivate') : __('Activate'),
                            'url'   => osc_admin_base_url(true) . '?page=comments&action=status&id=' . $comment['pk_i_id']
                                . '&value=' . ($comment['b_active'] == 1 ? 'INACTIVE' : 'ACTIVE') . '&' . osc_csrf_token_url(),
                            'variant' => 'dim',
                        )); ?>
                    </div>
                <?php osc_admin_form_row_close(); ?>
                <?php osc_admin_form_row_open(__('Moderation')); ?>
                    <div class="form-label-checkbox">
                        <?php osc_admin_status($comment['b_enabled'] ? 'active' : 'blocked', $comment['b_enabled'] ? __('Unblocked') : __('Blocked'));
                        osc_admin_action_button(array(
                            'label' => $comment['b_enabled'] == 1 ? __('Block') : __('Unblock'),
                            'url'   => osc_admin_base_url(true) . '?page=comments&action=status&id=' . $comment['pk_i_id']
                                . '&value=' . ($comment['b_enabled'] == 1 ? 'DISABLE' : 'ENABLE') . '&' . osc_csrf_token_url(),
                            'variant' => 'dim',
                        )); ?>
                    </div>
                <?php osc_admin_form_row_close(); ?>
                <?php osc_admin_form_row_open(__('Comment'), array('for' => 'body', 'controls_class' => 'input-description-wide')); ?>
                        <?php CommentForm::body_input_textarea($comment); ?>
                <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_form_close(array(
                array('label' => __('Cancel'), 'url' => 'javascript:history.go(-1)', 'variant' => 'dim'),
                array('label' => $btn_text, 'type' => 'submit', 'variant' => 'primary'),
            )); ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>