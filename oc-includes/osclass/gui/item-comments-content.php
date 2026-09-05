<?php
if (!defined('ABS_PATH')) {
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

/**
 * Comments on a listing: the thread and the form to add to it.
 *
 * The field names are core's contract -- CWebItem reads authorName, authorEmail,
 * title and body from the add_comment action, and repopulates them from the
 * session after a rejected post -- so a theme shipping this was laying out a form
 * it did not own. Comments are enabled by default, which made omitting the block
 * a silently missing feature rather than a visible gap.
 *
 * CSRF is injected on shutdown into any form not marked nocsrf.
 */

$oeCommentTotal = (int)osc_count_item_comments();
// With registration required, a guest gets the invitation rather than a form
// their post would be rejected from. The thread itself stays readable either way.
$oeCommentGated = osc_reg_user_post_comments() && !osc_is_web_user_logged_in();
$oeCommentOld   = static function ($key) {
    return osc_esc_html((string)Session::newInstance()->_getForm($key));
};
?>
<section class="oe-comments" id="comments" aria-labelledby="oe-comments-title">
    <?php osc_run_hook('item_comments_before'); ?>

    <div class="oe-comments-head">
        <h2 id="oe-comments-title"><?php echo osc_esc_html(_m('Comments')); ?></h2>
        <?php if ($oeCommentTotal > 0) { ?>
            <span class="oe-comments-count"><?php echo $oeCommentTotal; ?></span>
        <?php } ?>
    </div>

    <?php osc_show_flash_message('pubMessages'); ?>

    <?php if ($oeCommentTotal > 0) { ?>
        <ol class="oe-comments-list">
            <?php while (osc_has_item_comments()) {
                $oeCommentTitle = trim((string)osc_comment_title());
                $oeCommentMine  = osc_comment_user_id() && osc_comment_user_id() == osc_logged_user_id(); ?>
                <li class="oe-comment">
                    <div class="oe-comment-head">
                        <span class="oe-comment-author"><?php echo osc_esc_html(osc_comment_author_name()); ?></span>
                        <time class="oe-comment-date" datetime="<?php echo osc_esc_html(osc_comment_pub_date()); ?>"><?php
                            echo osc_esc_html(osc_format_date(osc_comment_pub_date())); ?></time>
                    </div>
                    <?php if ($oeCommentTitle !== '') { ?>
                        <h3 class="oe-comment-title"><?php echo osc_esc_html($oeCommentTitle); ?></h3>
                    <?php } ?>
                    <p class="oe-comment-body"><?php echo nl2br(osc_esc_html(osc_comment_body())); ?></p>
                    <?php if ($oeCommentMine) { ?>
                        <div class="oe-comment-actions">
                            <a class="oe-comment-delete" rel="nofollow"
                               href="<?php echo osc_esc_html(osc_delete_comment_url()); ?>"><?php
                                echo osc_esc_html(_m('Delete')); ?></a>
                        </div>
                    <?php } ?>
                </li>
            <?php } ?>
        </ol>
        <?php echo osc_comments_pagination(); ?>
    <?php } else { ?>
        <p class="oe-comments-empty"><?php echo osc_esc_html(_m('No comments yet.')); ?></p>
    <?php } ?>

    <?php if ($oeCommentGated) { ?>
        <p class="oe-comments-gate">
            <a href="<?php echo osc_esc_html(osc_user_login_url()); ?>"><?php
                echo osc_esc_html(_m('Sign in')); ?></a>
            <?php echo osc_esc_html(_m('to leave a comment.')); ?>
        </p>
    <?php } else { ?>
        <form class="oe-comment-form" action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post"
              name="comment_form" id="oe-comment-form">
            <h3><?php echo osc_esc_html(_m('Leave a comment')); ?></h3>
            <input type="hidden" name="page" value="item" />
            <input type="hidden" name="action" value="add_comment" />
            <input type="hidden" name="id" value="<?php echo (int)osc_item_id(); ?>" />

            <?php if (osc_is_web_user_logged_in()) { ?>
                <input type="hidden" name="authorName" value="<?php echo osc_esc_html(osc_logged_user_name()); ?>" />
                <input type="hidden" name="authorEmail" value="<?php echo osc_esc_html(osc_logged_user_email()); ?>" />
            <?php } else { ?>
                <div class="oe-field">
                    <label for="oe-comment-name"><?php echo osc_esc_html(_m('Your name')); ?></label>
                    <input id="oe-comment-name" type="text" name="authorName" autocomplete="name" required
                           value="<?php echo $oeCommentOld('commentAuthorName'); ?>" />
                </div>
                <div class="oe-field">
                    <label for="oe-comment-email"><?php echo osc_esc_html(_m('Your email address')); ?>
                        <span class="oe-comment-optional">(<?php echo osc_esc_html(_m('not published')); ?>)</span></label>
                    <input id="oe-comment-email" type="email" name="authorEmail" autocomplete="email" required
                           value="<?php echo $oeCommentOld('commentAuthorEmail'); ?>" />
                </div>
            <?php } ?>

            <div class="oe-field">
                <label for="oe-comment-title"><?php echo osc_esc_html(_m('Title')); ?>
                    <span class="oe-comment-optional">(<?php echo osc_esc_html(_m('optional')); ?>)</span></label>
                <input id="oe-comment-title" type="text" name="title"
                       value="<?php echo $oeCommentOld('commentTitle'); ?>" />
            </div>
            <div class="oe-field">
                <label for="oe-comment-body"><?php echo osc_esc_html(_m('Comment')); ?></label>
                <textarea id="oe-comment-body" name="body" rows="4" required><?php
                    echo $oeCommentOld('commentBody'); ?></textarea>
            </div>

            <?php
            // Rendered exactly when the add_comment action will verify one, so a
            // visitor is never shown a challenge that is not checked, or asked for
            // none when it is.
            if (osc_recaptcha_comments_enabled() && osc_captcha_enabled()) { ?>
                <div class="oe-field"><?php osc_show_captcha('comment'); ?></div>
            <?php } ?>

            <?php osc_run_hook('comment_form'); ?>

            <div class="oe-actions">
                <button type="submit"><?php echo osc_esc_html(_m('Post comment')); ?></button>
            </div>
        </form>
    <?php } ?>

    <?php osc_run_hook('item_comments_after'); ?>
</section>
