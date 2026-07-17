<?php

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

/**
 * Class CommentForm
 */
class CommentForm extends Form
{

    /**
     * @param null $comment
     */
    public static function primary_input_hidden($comment = null)
    {
        $commentId = null;
        if (isset($comment['pk_i_id'])) {
            $commentId = $comment['pk_i_id'];
        }
        if (Session::newInstance()->_getForm('commentId') != '') {
            $commentId = Session::newInstance()->_getForm('commentId');
        }
        if (null !== $commentId) {
            parent::generic_input_hidden('id', $commentId);
        }
    }

    /**
     * @param null $comment
     */
    public static function title_input_text($comment = null)
    {
        $commentTitle = '';
        if (isset($comment['s_title'])) {
            $commentTitle = $comment['s_title'];
        }
        if (Session::newInstance()->_getForm('commentTitle') != '') {
            $commentTitle = Session::newInstance()->_getForm('commentTitle');
        }
        parent::generic_input_text('title', $commentTitle);
    }

    /**
     * @param null $comment
     */
    public static function author_input_text($comment = null)
    {
        $commentAuthorName = '';
        if (isset($comment['s_author_name'])) {
            $commentAuthorName = $comment['s_author_name'];
        }
        if (Session::newInstance()->_getForm('commentAuthorName') != '') {
            $commentAuthorName = Session::newInstance()->_getForm('commentAuthorName');
        }
        parent::generic_input_text('authorName', $commentAuthorName);
    }

    /**
     * @param null $comment
     */
    public static function email_input_text($comment = null)
    {
        $commentAuthorEmail = '';
        if (isset($comment['s_author_email'])) {
            $commentAuthorEmail = $comment['s_author_email'];
        }
        if (Session::newInstance()->_getForm('commentAuthorEmail') != '') {
            $commentAuthorEmail = Session::newInstance()->_getForm('commentAuthorEmail');
        }
        parent::generic_input_text('authorEmail', $commentAuthorEmail);
    }

    /**
     * @param null $comment
     */
    public static function body_input_textarea($comment = null)
    {
        $commentBody = '';
        if (isset($comment['s_body'])) {
            $commentBody = $comment['s_body'];
        }
        if (Session::newInstance()->_getForm('commentBody') != '') {
            $commentBody = Session::newInstance()->_getForm('commentBody');
        }
        parent::generic_textarea('body', $commentBody);
    }

    /**
     * @param bool $admin
     */
    public static function js_validation($admin = false)
    {
        // Self-contained vanilla validation (no jQuery / jquery-validate). This form
        // renders on both the admin (comment edit) and the public theme, so it depends
        // on neither jQuery nor the admin's ui-osc.js helper.
        $errorContainer = $admin ? '#error_list' : '#comment_error_list';
        ?>
        <script>
            (function () {
                var form = document.querySelector('form[name="comment_form"]');
                if (!form) { return; }
                var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                form.addEventListener('submit', function (e) {
                    var errors = [];
                    var body = form.querySelector('[name="body"]');
                    var email = form.querySelector('[name="authorEmail"]');
                    [body, email].forEach(function (el) { if (el) { el.classList.remove('is-invalid'); } });
                    if (body && body.value.trim() === '') {
                        errors.push({el: body, msg: "<?php echo osc_esc_js(__('Comment: this field is required')); ?>."});
                    }
                    if (email) {
                        var ev = email.value.trim();
                        if (ev === '') {
                            errors.push({el: email, msg: "<?php echo osc_esc_js(__('Email: this field is required')); ?>."});
                        } else if (!emailRe.test(ev)) {
                            errors.push({el: email, msg: "<?php echo osc_esc_js(__('Invalid email address')); ?>."});
                        }
                    }
                    var container = document.querySelector('<?php echo $errorContainer; ?>');
                    if (container) {
                        container.innerHTML = '';
                        errors.forEach(function (er) {
                            var li = document.createElement('li');
                            li.textContent = er.msg;
                            container.appendChild(li);
                            if (er.el) { er.el.classList.add('is-invalid'); }
                        });
                    }
                    if (errors.length) {
                        e.preventDefault();
                        if (container && container.scrollIntoView) { container.scrollIntoView({behavior: 'smooth', block: 'nearest'}); }
                        if (errors[0].el && errors[0].el.focus) { errors[0].el.focus(); }
                    } else {
                        form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) { b.disabled = true; });
                    }
                });
            })();
        </script>
        <?php
    }
}
