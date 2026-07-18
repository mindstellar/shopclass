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


//getting variables for this view
$themes = __get('themes');
$info   = WebThemes::newInstance()->loadThemeInfo(osc_theme());

function addHelp()
{
    echo '<p>'
         . __("Change your site's look and feel by activating a theme among those available. "
              . '<strong>Be careful</strong>: if your theme has been customized, '
              . "you'll lose all changes if you change to a new theme.")
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Appearance'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
        <a class="text-success ms-1 float-end"
           href="<?php echo osc_admin_base_url(true); ?>?page=appearance&amp;action=add"
           title="<?php _e('Add theme'); ?>">
            <i class="bi bi-plus-circle-fill"></i>
        </a>
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
    <!-- themes list -->
    <div class="appearance">
        <div id="tabs">
            <div id="available-themes">
                <h2 class="render-title"><?php _e('Current theme'); ?> <a
                            href="<?php echo osc_admin_base_url(true); ?>?page=appearance&amp;action=add"
                            class="btn btn-sm btn-primary"><?php _e('Add new'); ?></a></h2>
                <div class="current-theme">
                    <div class="card mb-3 col-sm-12 col-md-8 col-lg-6">
                        <div class="row no-gutters">
                            <div class="col">
                                <img src="<?php echo osc_base_url() . '/oc-content/themes/' . osc_theme() . '/screenshot.png' ?>"
                                     class="card-img" alt="<?php echo $info['name']; ?>">
                            </div>
                            <div class="col">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo $info['name']; ?></h5>
                                    <p><?php _e('Description') ?> : <?php echo $info['description']; ?></p>
                                    <p><?php _e('Version') ?> : <?php echo $info['version']; ?></p>
                                    <p><?php _e('Author') ?> : <a href="<?php echo $info['author_url']; ?>"
                                                                  target="_blank"><?php echo $info['author_name']; ?></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <h2 class="render-title lead"><?php _e('Available themes'); ?></h2>
                <hr>
                <div class="available-theme row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4">
                    <?php
                    $aThemesToUpdate = json_decode(osc_get_preference('themes_to_update'), true);
                    $bThemesToUpdate = is_array($aThemesToUpdate);
                    $csrf_token      = osc_csrf_token_url();
                    foreach ($themes as $theme) {
                        if ($theme === osc_theme()) {
                            continue;
                        }
                        $info = WebThemes::newInstance()->loadThemeInfo($theme);
                        ?>
                        <div class="col">
                            <div class="card">
                                <img class="card-img-top"
                                     src="<?php echo osc_base_url(); ?>/oc-content/themes/<?php echo $theme; ?>/screenshot.png"
                                     title="<?php echo $info['name']; ?>" alt="<?php echo $info['name']; ?>"/>
                                <div class="card-body">
                                    <div class="theme-stage">
                                        <div class="">
                                            <a href="<?php echo osc_admin_base_url(true);
                                            ?>?page=appearance&amp;action=activate&amp;theme=<?php
                                            echo $theme; ?>&amp;<?php echo $csrf_token;
?>" class="btn btn-mini btn-primary"><?php _e('Activate'); ?></a>
                                            <a target="_blank"
                                               href="<?php echo osc_base_url(true); ?>?theme=<?php echo $theme; ?>"
                                               class="btn btn-mini btn-dim"><?php _e('Preview'); ?></a>
                                            <a onclick="return delete_dialog('<?php echo $theme; ?>');"
                                               href="<?php echo osc_admin_base_url(true);
                                                ?>?page=appearance&amp;action=delete&amp;webtheme=<?php
                                               echo $theme; ?>&amp;<?php echo $csrf_token; ?>"
                                               class="btn btn-sm btn-dim delete"><?php _e('Delete'); ?></a>
                                            <?php
                                            if ($bThemesToUpdate && in_array($theme, $aThemesToUpdate)) { ?>
                                                <a href='#<?php echo htmlentities(@$info['theme_update_uri']); ?>'
                                                   class="btn btn-mini btn-primary market-popup"><?php _e('Update'); ?></a>
                                            <?php } ?>
                                        </div>
                                        <h4>
                                            <?php echo ucfirst($info['name']); ?>
                                        </h4>
                                        <div class="theme-info">
                                            <div><?php echo __('Version') ?>: <?php echo $info['version']; ?></div>
                                            <div><?php echo __('Author') ?>: <a target="_blank"
                                                                                href="<?php echo $info['author_url']; ?>"><?php echo $info['author_name']; ?></a>
                                            </div>
                                            <div><?php echo __('Description') ?>: <?php echo $info['description']; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <!-- /themes list -->
</div>
<dialog id="deleteModal" class="osc-dialog osc-dialog-danger">
    <form method="get" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="appearance"/>
        <input type="hidden" name="action" value="delete"/>
        <input type="hidden" name="webtheme" value=""/>
        <div class="osc-dialog-body">
            <p class="osc-dialog-title">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php _e('Delete theme'); ?>
            </p>
            <p class="osc-dialog-text"><?php _e('This action can not be undone. Are you sure you want to delete the theme?'); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
            <button id="deleteSubmit" class="btn btn-danger btn-sm" type="submit"><?php echo __('Uninstall'); ?></button>
        </div>
    </form>
</dialog>
<script type="text/javascript">
    function delete_dialog(id) {
        var deleteModal = document.getElementById("deleteModal");
        var input = deleteModal.querySelector("input[name='webtheme']");
        if (input) { input.value = id; }
        deleteModal.showModal();
        return false;
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
