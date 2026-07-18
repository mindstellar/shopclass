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

$page      = __get('page');
$templates = __get('templates');
if (isset($page)) {
    $meta      = json_decode($page['s_meta'], true);
}

$template_selected = (isset($meta['template']) && $meta['template'] != '') ? $meta['template'] : 'default';
$locales           = OSCLocale::newInstance()->listAllEnabled();

/**
 * @param string $return
 *
 * @return mixed
 */
function customFrmText($return = 'title')
{
    $page = __get('page');
    $text = array();
    if (isset($page['pk_i_id'])) {
        $text['edit']       = true;
        $text['title']      = __('Edit page');
        $text['action_frm'] = 'edit_post';
        $text['btn_text']   = __('Save changes');
    } else {
        $text['edit']       = false;
        $text['title']      = __('Add page');
        $text['action_frm'] = 'add_post';
        $text['btn_text']   = __('Add page');
    }

    return $text[$return];
}


function customPageHeader()
{
    ?>
    <h1><?php _e('Pages'); ?></h1>
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
    return sprintf('%s &raquo; %s', customFrmText('title'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        tinyMCE.init({
            selector: "textarea",
            promotion: false,
            width: "100%",
            height: "440px",
            language: 'en',
            theme_advanced_toolbar_align: "left",
            theme_advanced_toolbar_location: "top",
            plugins: [
                "advlist autolink lists link charmap preview anchor",
                "searchreplace visualblocks code fullscreen media image",
                "insertdatetime table paste"
            ],
            entity_encoding: "raw",
            theme_advanced_buttons1_add: "forecolorpicker,fontsizeselect,image",
            theme_advanced_disable: "styleselect,anchor",
            relative_urls: false,
            remove_script_host: false,
            convert_urls: false
        });

    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

osc_current_admin_theme_path('parts/header.php'); ?>
<h2 class="render-title"><?php echo customFrmText('title'); ?></h2>
<div id="item-form" class="col-xl-10">
    <?php PageForm::printMultiLangTab(); ?>
    <form action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="pages"/>
        <input type="hidden" name="action" value="<?php echo customFrmText('action_frm'); ?>"/>
        <?php PageForm::primary_input_hidden($page); ?>
        <?php if (count($templates) > 0) { ?>
            <div class="select-box-big">
                <label><?php _e('Page template'); ?></label>
                <select class="form-select form-select-sm" name="meta[template]">
                    <option value="default" <?php if ($template_selected === 'default') {
                        echo 'selected="selected"';
                                            } ?>><?php _e('Default template'); ?></option>
                    <?php foreach ($templates as $template) { ?>
                        <option value="<?php echo $template ?>" <?php if ($template_selected === $template) {
                            echo 'selected="selected"';
                                       } ?>><?php echo $template; ?></option>
                    <?php } ?>
                </select>
            </div>
        <?php } ?>
        <div>
            <label><?php _e('Internal name'); ?>/<?php echo __('Slug');?></label>
            <?php PageForm::internal_name_input_text($page); ?>
            <div class="help-box">
                <p><?php _e('Used to quickly identify this page'); ?></p>
            </div>
            <span class="help"></span>
        </div>
        <?php PageForm::printMultiLangTitleDesc($page, false); ?>
        <div class="form-controls">
            <div class="form-label-checkbox">
                <label><?php PageForm::link_checkbox($page); ?><?php _e('Show a link in footer'); ?></label>
            </div>
        </div>
        <div>
            <?php osc_run_hook('page_meta'); ?>
        </div>
        <div class="clear"></div>
        <div class="form-actions">
            <?php if (customFrmText('edit')) { ?>
                <a href="javascript:history.go(-1)" class="btn btn-dim"><?php _e('Cancel'); ?></a>
            <?php } ?>
            <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(customFrmText('btn_text')); ?></button>
        </div>
    </form>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
