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


$rule = __get('rule');

/**
 * @return array
 */
function customFrmText()
{
    $rule   = __get('rule');
    $return = array();

    if (isset($rule['pk_i_id'])) {
        $return['edit']       = true;
        $return['title']      = __('Edit rule');
        $return['action_frm'] = 'edit_ban_rule_post';
        $return['btn_text']   = __('Update rule');
    } else {
        $return['edit']       = false;
        $return['title']      = __('Add new ban rule');
        $return['action_frm'] = 'create_ban_rule_post';
        $return['btn_text']   = __('Add new ban rule');
    }

    return $return;
}


function customPageHeader()
{
    ?>
    <h1><?php _e('Ban rules'); ?></h1>
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
    $aux = customFrmText();

    return sprintf('%s &raquo; %s', $aux['title'], $string);
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
}


osc_add_hook('admin_header', 'customHead', 10);

$aux = customFrmText();
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<h2 class="render-title"><?php echo $aux['title']; ?></h2>
<div class="settings-user">
    <ul id="error_list"></ul>
    <form name="register" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="users"/>
        <input type="hidden" name="action" value="<?php echo $aux['action_frm']; ?>"/>
        <h3 class="render-title"><?php _e('Contact info'); ?></h3>
        <?php BanRuleForm::primary_input_hidden($rule); ?>
        <fieldset>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Ban name / Reason'); ?></div>
                    <div class="form-controls">
                        <?php BanRuleForm::name_text($rule); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('IP rule'); ?></div>
                    <div class="form-controls">
                        <?php BanRuleForm::ip_text($rule); ?>
                        <span class="help-box"><?php _e('(e.g. 192.168.10-20.*)'); ?></span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('E-mail rule'); ?></div>
                    <div class="form-controls">
                        <?php BanRuleForm::email_text($rule); ?>
                        <span class="help-box"><?php _e('(e.g. *@badsite.com, *@subdomain.badsite.com, *@*badsite.com)'); ?></span>
                    </div>
                </div>
                <div class="clear"></div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit"><?php echo osc_esc_html($aux['btn_text']); ?></button>
                </div>
            </div>
        </fieldset>
    </form>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
