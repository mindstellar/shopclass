<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Osclass (Mindstellar).
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
    $btn_text   = osc_esc_html(__('Update comment'));
} else {
    //adding...
    $title      = __('Add comment');
    $action_frm = 'add_comment_post';
    $btn_text   = osc_esc_html(__('Add'));
}

function customPageHeader()
{
    ?>
    <h1><?php _e('Listing'); ?></h1>
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
    return sprintf(__('Edit comment &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    CommentForm::js_validation(true);
}


osc_add_hook('admin_header', 'customHead', 10);

$comment = __get('comment');
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php echo $title; ?></h2>
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
            <div class="form-actions">
                <a href="javascript:history.go(-1)" class="btn btn-dim"><?php _e('Cancel'); ?></a>
                <button type="submit" class="btn btn-submit"><?php echo $btn_text; ?></button>
            </div>
        </form>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>