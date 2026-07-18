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


$info = __get('info');

function addHelp()
{
    echo '<p>' . __("Modify your site's header or footer here.") . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Appearance'); ?>
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
    return sprintf(__('Appearance &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="appearance-page">
    <div class="appearance">
        <h2 class="render-title"><?php _e('Manage Widgets'); ?> </h2>
    </div>
</div>
</div> <!-- -->
<div class="row g-3">
    <?php if (isset($info['locations']) && is_array($info['locations'])) { ?>
        <?php foreach ($info['locations'] as $location) { ?>
            <div class="col-md-6">
                <div class="row-wrapper">
                    <div class="widget-box">
                        <div class="widget-box-title">
                            <h3><?php printf(__('Section: %s'), $location); ?>
                                <a id="add_widget_<?php echo $location; ?>"
                                   href="<?php echo osc_admin_base_url(true); ?>?page=appearance&amp;action=add_widget&amp;location=<?php echo $location; ?>"
                                   class="btn btn-secondary btn-sm float-end"><?php _e('Add HTML widget'); ?></a></h3>
                        </div>
                        <div class="widget-box-content">
                            <?php $widgets = Widget::newInstance()->findByLocation($location); ?>
                            <?php if (count($widgets) > 0) {
                                $countEvent = 1; ?>
                                <table class="table" cellpadding="0" cellspacing="0">
                                    <tbody>
                                    <?php foreach ($widgets as $w) { ?>
                                        <tr<?php if ($countEvent % 2 == 0) {
                                            echo ' class="even"';
                                           }
                                           if ($countEvent == 1) {
                                               echo ' class="table-first-row"';
                                           } ?>>
                                            <td><?php echo __('Widget') . ' ' . $w['pk_i_id']; ?></td>
                                            <td><?php printf(__('Description: %s'), $w['s_description']); ?></td>
                                            <td><?php printf(
                                                    '<a href="%1$s?page=appearance&amp;action=edit_widget&amp;id=%2$s&amp;location=%3$s">'
                                                    . __('Edit') . '</a>', osc_admin_base_url(true), $w['pk_i_id'],
                                                    $location); ?>
                                                <a href="<?php printf('%s?page=appearance&amp;action=delete_widget&amp;id=%d"',
                                                                      osc_admin_base_url(true), $w['pk_i_id']); ?>"
                                                   onclick="return delete_dialog('<?php echo $w['pk_i_id']; ?>');"><?php _e('Delete'); ?></a>
                                            </td>
                                        </tr>
                                        <?php
                                        $countEvent++;
                                    }
                                    ?>
                                    </tbody>
                                </table>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>
    <?php } else { ?>
        <div class="col-md-6">
            <div class="row-wrapper">
                <div class="widget-box">
                    <div class="widget-box-title"><h3><?php _e('Current theme does not support widgets'); ?></h3></div>
                    <div class="widget-box-content">
                        <?php _e('Current theme does not support widgets'); ?>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>
</div>
</div>
</div>
<dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="appearance"/>
        <input type="hidden" name="action" value="delete_widget"/>
        <input type="hidden" name="id" value=""/>
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo __('Delete widget'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('Are you sure you want to delete this widget?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" class="btn btn-danger btn-sm" type="submit"><?php echo __('Delete'); ?></button>
        </div>
    </form>
</dialog>
<script type="text/javascript">
    function delete_dialog(id) {
        var deleteModal = document.getElementById('deleteModal');
        var input = deleteModal.querySelector("input[name='id']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<div class="row">
    <div class="col-12">
        <div class="row-wrapper">
            <?php osc_current_admin_theme_path('parts/footer.php'); ?>
