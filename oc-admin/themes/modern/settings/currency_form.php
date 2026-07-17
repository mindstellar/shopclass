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
            oscValidateForm(document.querySelector('form[name=currency_form]'), {
                rules: {
                    pk_c_code: { required: true, minlength: 3, maxlength: 3 },
                    s_name: { required: true, minlength: 1 }
                },
                messages: {
                    pk_c_code: {
                        required: '<?php echo osc_esc_js(__('Currency code: this field is required')); ?>.',
                        minlength: '<?php echo osc_esc_js(__('Currency code: this field is required')); ?>.',
                        maxlength: '<?php echo osc_esc_js(__('Currency code: this field is required')); ?>.'
                    },
                    s_name: {
                        required: '<?php echo osc_esc_js(__('Name: this field is required')); ?>.',
                        minlength: '<?php echo osc_esc_js(__('Name: this field is required')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });
        });
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
        <a href="<?php echo osc_admin_base_url(true) . '?page=settings&action=currencies&type=add'; ?>"
           class="ms-1 text-success float-end" data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php _e('Add'); ?>"><i
                    class="bi bi-plus-circle-fill"></i></a>
    </h1>
    <?php
}


$typeForm = __get('typeForm');
/**
 * @param string $return
 *
 * @return mixed
 */
function customText($return = 'title')
{
    $typeForm = __get('typeForm');
    $text     = array();
    switch ($typeForm) {
        case ('add_post'):
            $text['title']  = __('Add currency');
            $text['button'] = __('Add currency');
            break;
        case ('edit_post'):
            $text['title']  = __('Edit currency');
            $text['button'] = __('Update currency');
            break;
    }

    return $text[$return];
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf('%s &raquo; %s', customText('title'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$aCurrency = View::newInstance()->_get('aCurrency');

osc_current_admin_theme_path('parts/header.php'); ?>
    <div id="add-currency-settings">
        <h2 class="render-title"><?php echo customText('title'); ?></h2>
        <ul id="error_list"></ul>
        <form name="currency_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="currencies"/>
            <input type="hidden" name="type" value="<?php echo $typeForm; ?>"/>
            <?php if ($typeForm === 'edit_post') { ?>
                <input type="hidden" name="pk_c_code" value="<?php echo osc_esc_html($aCurrency['pk_c_code']); ?>"/>
            <?php } ?>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Currency Code'); ?></div>
                        <div class="form-controls">
                            <input
                                    class="input-small"
                                    name="pk_c_code"
                                    type="text"
                                    value="<?php echo osc_esc_html($aCurrency['pk_c_code']); ?>"
                                <?php if ($typeForm
                                          === 'edit_post'
                                ) {
                                               echo 'disabled="disabled"';
                                }
                                ?>
                            />
                            <span class="help-box">
                                <?php printf(
                                    __('Must be a three-character code according to the <a href="%s" target="_blank">ISO 4217</a>'),
                                    'http://en.wikipedia.org/wiki/ISO_4217'
                                ); ?>
                            </span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Currency symbol'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-small" name="s_description"
                                   value="<?php echo osc_esc_html($aCurrency['s_description']); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Name'); ?></div>
                        <div class="form-controls">
                            <input type="text" name="s_name" value="<?php echo osc_esc_html($aCurrency['s_name']); ?>"/>
                        </div>
                    </div>
                    <div class="form-actions">
                        <?php if ($typeForm === 'edit_post') { ?>
                            <input class="btn btn-red" type="button" value="<?php echo osc_esc_html(__('Cancel')); ?>"
                                   onclick="location.href='<?php echo osc_admin_base_url(true);
                                    ?>?page=settings&amp;action=currencies'">
                        <?php } ?>
                        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(customText('button')); ?></button>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
<?php osc_current_admin_theme_path('parts/footer.php'); ?>