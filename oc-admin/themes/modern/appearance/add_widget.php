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

osc_enqueue_script('tiny_mce');

$info   = __get('info');
$widget = __get('widget');

if (Params::getParam('action') === 'edit_widget') {
    $title  = __('Edit widget');
    $edit   = true;
    $button = osc_esc_html(__('Save changes'));
} else {
    $title  = __('Add widget');
    $edit   = false;
    $button = osc_esc_html(__('Add widget'));
}

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    if (Params::getParam('action') === 'edit_widget') {
        $title = __('Edit widget');
    } else {
        $title = __('Add widget');
    }
    ?>
    <h1><?php echo $title; ?></h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Appearance &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');
function customHead()
{
    $info   = __get('info');
    $widget = __get('widget');
    if (Params::getParam('action') === 'edit_widget') {
        $title  = __('Edit widget');
        $edit   = true;
        $button = osc_esc_html(__('Save changes'));
    } else {
        $title  = __('Add widget');
        $edit   = false;
        $button = osc_esc_html(__('Add widget'));
    }
    ?>
<?php }


osc_add_hook('admin_header', 'customHead', 10);
osc_current_admin_theme_path('parts/header.php'); ?>
<div id="widgets-page">
    <div class="widgets">
        <div id="item-form">
            <ul id="error_list"></ul>
            <form name="widget_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                <input type="hidden" name="action"
                       value="<?php echo($edit ? 'edit_widget_post' : 'add_widget_post'); ?>"/>
                <input type="hidden" name="page" value="appearance"/>
                <?php if ($edit) { ?>
                    <input type="hidden" name="id" value="<?php echo Params::getParam('id', true); ?>"/>
                <?php } ?>
                <input type="hidden" name="location" value="<?php echo Params::getParam('location', true); ?>"/>
                <fieldset>
                    <div class="input-line">
                        <label><?php _e('Description (for internal purposes only)'); ?></label>
                        <div class="input">
                            <input type="text" class="large" name="description" value="<?php if ($edit) {
                                echo osc_esc_html($widget['s_description']);
                                                                                       } ?>"/>
                        </div>
                    </div>
                    <div class="input-description-wide">
                        <label><?php _e('HTML Code for the Widget'); ?></label>
                        <textarea name="content" id="body"><?php if ($edit) {
                                echo osc_esc_html($widget['s_content']);
                                                           } ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit"><?php echo $button; ?></button>
                    </div>
                </fieldset>
            </form>
        </div>
    </div>
</div>
<script type="text/javascript">
    tinyMCE.init({
        selector: "textarea",
        promotion: false,
        width: "500px",
        height: "340px",
        theme_advanced_buttons3: "",
        theme_advanced_toolbar_align: "left",
        theme_advanced_toolbar_location: "top",
        plugins: [
            "advlist autolink lists link charmap preview anchor",
            "searchreplace visualblocks code fullscreen",
            "insertdatetime table paste"
        ],
        entity_encoding: "raw",
        theme_advanced_buttons1_add: "forecolorpicker,fontsizeselect",
        theme_advanced_disable: "styleselect",
        extended_valid_elements: "script[type|src|charset|defer]",
        relative_urls: false,
        remove_script_host: false,
        convert_urls: false
    });

</script>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        oscValidateForm(document.querySelector('form[name=widget_form]'), {
            rules: { description: { required: true } },
            messages: {
                description: {
                    required: '<?php echo osc_esc_js(__('Description: this field is required')); ?>.'
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
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
