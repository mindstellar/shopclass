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

$categories  = __get('categories');
$selected    = __get('selected');
$plugin_data = __get('plugin_data');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php echo osc_apply_filter('custom_plugin_title', __('Plugins')); ?></h1>
    <?php
}


//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        // check all the categories in a subtree
        function checkAll(id, check) {
            var root = document.getElementById(id);
            if (root) {
                root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; });
            }
        }

        function checkCat(id, check) {
            var root = document.getElementById('cat' + id);
            if (root) {
                root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; });
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (typeof oscTreeview === 'function') {
                oscTreeview(document.getElementById('plugin_tree'), {
                    collapsed: true,
                    toggleLabel: '<?php echo osc_esc_js(__('Toggle subcategories')); ?>'
                });
            }
        });
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Plugins &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
    <!-- plugin configuration -->
    <div class="plugin-configuration form-horizontal">
        <form id="plugin-frm" action="<?php echo osc_admin_base_url(true); ?>?page=plugins" method="post">
            <input type="hidden" name="action" value="configure_post"/>
            <input type="hidden" name="plugin" value="<?php echo $plugin_data['filename']; ?>"/>
            <input type="hidden" name="plugin_short_name" value="<?php echo $plugin_data['short_name']; ?>"/>
            <fieldset>
                <h2 class="render-title"><?php echo $plugin_data['plugin_name']; ?></h2>
                <p class="text"><?php echo $plugin_data['description']; ?></p>
                <div class="form-row">
                    <div><?php _e('Select the categories where you want to apply these attribute:'); ?></div>
                    <div class="separate-top">
                        <div class="form-label">
                            <a href="javascript:void(0);"
                               onclick="checkAll('plugin_tree', true); return false;"><?php _e('Check all'); ?></a>
                            &middot;
                            <a href="javascript:void(0);"
                               onclick="checkAll('plugin_tree', false); return false;"><?php _e('Uncheck all'); ?></a>
                        </div>
                        <div class="form-controls">
                            <ul id="plugin_tree">
                                <?php CategoryForm::categories_tree($categories, $selected); ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" id="plugin-configuration-submit"
                           value="<?php echo osc_esc_html(__('Update')); ?>" class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /theme files -->
<?php osc_current_admin_theme_path('parts/footer.php'); ?>