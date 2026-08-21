<?php if (!defined('OC_ADMIN')) {
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

$comment = __get('comment');

if (isset($comment['pk_i_id'])) {
    //editing...
    $title      = __('Edit comment');
    $action_frm = 'comment_edit_post';
    $btn_text   = __('Update comment');
} else {
    //adding...
    $title      = __('Add comment');
    $action_frm = 'add_comment_post';
    $btn_text   = __('Add');
}

osc_admin_page(array(
    'section' => __('Listing'),
    'title'   => __('Edit comment'),
));

//customize Head
function customHead()
{
    CommentForm::js_validation(true);
}

osc_add_hook('admin_header', 'customHead', 10);

$comment = __get('comment');
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head($title); ?>
    <div id="language-form">
        <ul id="error_list"></ul>
        <form name="language_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="action" value="<?php echo $action_frm; ?>"/>
            <input type="hidden" name="page" value="comments"/>
            <input type="hidden" name="id"
                   value="<?php echo (isset($comment['pk_i_id'])) ? $comment['pk_i_id'] : '' ?>"/>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Title'); ?></div>
                    <div class="form-controls">
                        <?php CommentForm::title_input_text($comment); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Author'); ?></div>
                    <div class="form-controls">
                        <?php CommentForm::author_input_text($comment); ?>
                        <?php if (isset($comment['fk_i_user_id']) && $comment['fk_i_user_id'] != '') {
                            _e('Registered user'); ?>
                            <a href="<?php echo osc_admin_base_url(true); ?>?page=users&action=edit&id=<?php echo
                            $comment['fk_i_user_id']; ?>"><?php _e('Edit user'); ?></a>
                        <?php } ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e("Author's e-mail"); ?></div>
                    <div class="form-controls">
                        <?php CommentForm::email_input_text($comment); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Status'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <?php echo($comment['b_active'] ? __('Active') : __('Inactive')); ?> ( <a
                                    href="<?php echo osc_admin_base_url(true); ?>?page=comments&action=status&id=<?php echo
                                    $comment['pk_i_id']; ?>&value=<?php echo(($comment['b_active'] == 1)
                                        ? 'INACTIVE' : 'ACTIVE'); ?>"><?php echo(($comment['b_active'] == 1)
                                    ? __('Deactivate') : __('Activate')); ?></a> )
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Status'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <?php echo($comment['b_enabled'] ? __('Unblocked') : __('Blocked')); ?> ( <a
                                    href="<?php echo osc_admin_base_url(true); ?>?page=comments&action=status&id=<?php echo
                                    $comment['pk_i_id']; ?>&value=<?php echo(($comment['b_enabled'] == 1)
                                        ? 'DISABLE' : 'ENABLE'); ?>"><?php echo(($comment['b_enabled'] == 1)
                                    ? __('Block') : __('Unblock')); ?></a> )
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Comment'); ?></div>
                    <div class="form-controls input-description-wide">
                        <?php CommentForm::body_input_textarea($comment); ?>
                    </div>
                </div>
            </div>
            <?php osc_admin_form_actions(array(
                array('label' => __('Cancel'), 'url' => 'javascript:history.go(-1)', 'variant' => 'dim'),
                array('label' => $btn_text, 'type' => 'submit', 'variant' => 'primary'),
            )); ?>
        </form>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>