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


//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            oscValidateForm(document.querySelector('form[name=comments_form]'), {
                rules: {
                    num_moderate_comments: { required: true, digits: true },
                    comments_per_page: { required: true, digits: true }
                },
                messages: {
                    num_moderate_comments: {
                        required: '<?php echo osc_esc_js(__('Moderated comments: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Moderated comments: this field must only contain numeric characters')); ?>.'
                    },
                    comments_per_page: {
                        required: '<?php echo osc_esc_js(__('Comments per page: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Comments per page: this field must only contain numeric characters')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });

            var moderate = document.querySelector('input[name="moderate_comments"]');
            function setApproved(display) {
                document.querySelectorAll('.comments_approved').forEach(function (el) { el.style.display = display; });
            }
            if (moderate) {
                if (!moderate.checked) { setApproved('none'); }
                moderate.addEventListener('change', function () {
                    setApproved(moderate.checked ? '' : 'none');
                });
            }
        });
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


function addHelp()
{
    echo '<p>' . __("Modify the options that allow your users to publish comments on your site's listings.") . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Comment Settings &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-settings">
    <ul id="error_list"></ul>
    <form name="comments_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="comments_post"/>
        <fieldset>
            <div class="form-horizontal">
                <h2 class="render-title"><?php _e('Comment Settings'); ?></h2>

                <div class="form-row">
                    <div class="form-label"><?php _e('Default comment settings'); ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <label>
                                <input type="checkbox" <?php echo(osc_comments_enabled() ? 'checked="checked"' : ''); ?>
                                       name="enabled_comments"
                                       value="1"/> <?php _e('Allow people to post comments on listings'); ?>
                            </label>
                        </div>
                        <div class="form-label-checkbox">
                            <label>
                                <input type="checkbox" <?php echo(osc_reg_user_post_comments() ? 'checked="checked"'
                                    : ''); ?> name="reg_user_post_comments"
                                       value="1"/> <?php _e('Users must be registered and logged in to comment'); ?>
                            </label>
                        </div>
                        <div class="form-label-checkbox">
                            <label>
                                <input type="checkbox" <?php echo((osc_moderate_comments() == -1) ? ''
                                    : 'checked="checked"'); ?> name="moderate_comments"
                                       value="1"/> <?php _e('A comment is being held for moderation'); ?>
                            </label>
                        </div>
                        <div class="form-label-checkbox-offset">
                            <?php printf(__('Before a comment appears, comment author must have at least %s previously approved comments'),
                                         '<input type="text" class="input-small" name="num_moderate_comments" value="'
                                         . ((osc_moderate_comments() == -1) ? '0' : osc_esc_html(osc_moderate_comments()))
                                         . '" />'); ?>
                            <div class="help-box"><?php _e('If the value is zero, an administrator must always approve comments'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Other comment settings'); ?></div>
                    <div class="form-controls">
                        <?php printf(__('Break comments into pages with %s comments per page'),
                                     '<input type="text" class="input-small" name="comments_per_page" value="'
                                     . osc_esc_html(osc_comments_per_page()) . '" />'); ?>
                        <div class="help-box"><?php _e('If the value is zero all comments are shown'); ?></div>
                    </div>
                </div>

                <h2 class="render-title"><?php _e('Notifications'); ?></h2>

                <div class="form-row">
                    <div class="form-label"><?php _e('E-mail admin whenever') ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <label>
                                <input type="checkbox" <?php echo(osc_notify_new_comment() ? 'checked="checked"'
                                    : ''); ?> name="notify_new_comment"
                                       value="1"/> <?php _e('A new comment is posted'); ?>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('E-mail user whenever') ?></div>
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <label>
                                <input type="checkbox" <?php echo(osc_notify_new_comment_user() ? 'checked="checked"'
                                    : ''); ?> name="notify_new_comment_user"
                                       value="1"/> <?php _e("There's a new comment on his listing"); ?>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" id="save_changes" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                           class="btn btn-submit"/>
                </div>
            </div>
        </fieldset>
    </form>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
