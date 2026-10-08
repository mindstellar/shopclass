<?php

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

/**
 * Class CommentForm
 */
class CommentForm extends Form
{
    /**
     * Echo the hidden comment id input, preferring the id kept in the failed-submit session.
     *
     * @param array<string,mixed>|null $comment
     *
     * @return void
     */
    public static function primary_input_hidden($comment = null)
    {
        $commentId = null;
        if (isset($comment['pk_i_id'])) {
            $commentId = $comment['pk_i_id'];
        }
        if (Session::getInstance()->_getForm('commentId') != '') {
            $commentId = Session::getInstance()->_getForm('commentId');
        }
        if (null !== $commentId) {
            parent::generic_input_hidden('id', $commentId);
        }
    }

    /**
     * Echo the comment title input, preferring the value kept in the failed-submit session.
     *
     * @param array<string,mixed>|null $comment
     *
     * @return void
     */
    public static function title_input_text($comment = null)
    {
        $commentTitle = '';
        if (isset($comment['s_title'])) {
            $commentTitle = $comment['s_title'];
        }
        if (Session::getInstance()->_getForm('commentTitle') != '') {
            $commentTitle = Session::getInstance()->_getForm('commentTitle');
        }
        parent::generic_input_text('title', $commentTitle);
    }

    /**
     * Echo the author name input, preferring the value kept in the failed-submit session.
     *
     * @param array<string,mixed>|null $comment
     *
     * @return void
     */
    public static function author_input_text($comment = null)
    {
        $commentAuthorName = '';
        if (isset($comment['s_author_name'])) {
            $commentAuthorName = $comment['s_author_name'];
        }
        if (Session::getInstance()->_getForm('commentAuthorName') != '') {
            $commentAuthorName = Session::getInstance()->_getForm('commentAuthorName');
        }
        parent::generic_input_text('authorName', $commentAuthorName);
    }

    /**
     * Echo the author email input, preferring the value kept in the failed-submit session.
     *
     * @param array<string,mixed>|null $comment
     *
     * @return void
     */
    public static function email_input_text($comment = null)
    {
        $commentAuthorEmail = '';
        if (isset($comment['s_author_email'])) {
            $commentAuthorEmail = $comment['s_author_email'];
        }
        if (Session::getInstance()->_getForm('commentAuthorEmail') != '') {
            $commentAuthorEmail = Session::getInstance()->_getForm('commentAuthorEmail');
        }
        parent::generic_input_text('authorEmail', $commentAuthorEmail);
    }

    /**
     * Echo the comment body textarea, preferring the value kept in the failed-submit session.
     *
     * @param array<string,mixed>|null $comment
     *
     * @return void
     */
    public static function body_input_textarea($comment = null)
    {
        $commentBody = '';
        if (isset($comment['s_body'])) {
            $commentBody = $comment['s_body'];
        }
        if (Session::getInstance()->_getForm('commentBody') != '') {
            $commentBody = Session::getInstance()->_getForm('commentBody');
        }
        parent::generic_textarea('body', $commentBody);
    }

    /**
     * Echo (or enqueue) the client-side validation script for the comment form.
     *
     * @param bool $admin   Render for the admin comment editor rather than the public theme
     * @param bool $enqueue Buffer the script and hand it to Scripts::enqueueScriptCode
     *
     * @return void
     */
    public static function js_validation($admin = false, $enqueue = false)
    {
        if ($enqueue) {
            ob_start();
        }
        ?>
        <script>
            <?php echo self::validationJs('comment_form', [
                'body'        => ['required' => true],
                'authorEmail' => ['required' => true, 'email' => true],
            ], [
                'body'        => __('Comment: this field is required') . '.',
                'authorEmail' => [
                    'required' => __('Email: this field is required') . '.',
                    'email'    => __('Invalid email address') . '.',
                ],
            ], ['errorList' => $admin ? '#error_list' : '#comment_error_list', 'scrollToList' => true]); ?>
        </script>
        <?php
        if ($enqueue) {
            Scripts::enqueueScriptCode((string) ob_get_clean(), null, $admin, 'comment_form_js');
        }
    }
}
