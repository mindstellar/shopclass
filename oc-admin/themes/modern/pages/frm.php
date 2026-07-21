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

osc_enqueue_script('tiny_mce');

$page      = __get('page');
$templates = __get('templates');
// Registered page templates (id => spec). Hide super_admin-gated templates from
// moderators, mirroring the widget picker's capability check.
$registeredTemplates = __get('registeredTemplates');
if (!is_array($registeredTemplates)) {
    $registeredTemplates = array();
}
$registeredTemplates = array_filter($registeredTemplates, static function ($spec) {
    return !(($spec['capability'] ?? 'admin') === 'super_admin' && osc_is_moderator());
});
$meta = array();
if (isset($page['s_meta'])) {
    $meta = json_decode($page['s_meta'], true);
}

$template_selected = (isset($meta['template']) && $meta['template'] != '') ? $meta['template'] : 'default';

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

// TinyMCE 7 — scoped to the per-language content editors only (name ends with
// "#s_text"), never the whole page, so plugin textareas in the meta rail are
// left alone. Paste is cleaned the way a WYSIWYG should: Word/Docs style cruft
// is dropped, semantic tags are kept, and images are not inlined as data URIs.
function customHead()
{
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof tinymce === 'undefined') {
                return;
            }
            tinymce.init({
                selector: 'textarea[name$="#s_text"]',
                promotion: false,
                branding: false,
                menubar: false,
                height: 460,
                relative_urls: false,
                remove_script_host: false,
                convert_urls: false,
                entity_encoding: 'raw',
                plugins: 'advlist anchor autolink charmap code fullscreen image insertdatetime'
                    + ' link lists media preview searchreplace table visualblocks',
                toolbar: 'undo redo | blocks | bold italic underline | bullist numlist'
                    + ' | link image media table | alignleft aligncenter alignright'
                    + ' | removeformat | visualblocks code fullscreen preview',
                // Paste handling — clean what comes in from Word / Google Docs.
                smart_paste: true,
                paste_as_text: false,
                paste_merge_formats: true,
                paste_data_images: false,
                paste_remove_styles_if_webkit: true,
                paste_webkit_styles: 'none',
                // Only the light oxide skin ships, so the editor is a consistent
                // "sheet of paper" in both themes rather than a half-dark panel.
                content_style: 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,'
                    + 'Helvetica Neue,Arial,sans-serif;font-size:16px;line-height:1.55;color:#14181f}'
            });
        });
    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

/**
 * Add the content column offset used by the other admin editors.
 *
 * @return string
 */
function pageFrmRenderOffset()
{
    return 'row-offset';
}


osc_add_filter('render-wrapper', 'pageFrmRenderOffset');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="adminPageForm" class="col-xl-10">
    <div class="row">
        <div class="col">
            <h2 class="render-title"><?php echo osc_esc_html(customFrmText('title')); ?></h2>
            <?php if (customFrmText('edit')) { ?>
                <a class="page-view-link"
                   href="<?php echo osc_esc_html(osc_base_url(true) . '?page=page&id=' . $page['pk_i_id']); ?>"
                   target="_blank" rel="noopener">
                    <?php _e('View page on site'); ?><i class="bi bi-arrow-up-right-square ms-1" aria-hidden="true"></i>
                </a>
            <?php } ?>
        </div>
    </div>
    <div class="row">
        <div class="col">
            <div id="item-form">
                <form class="row page-editor" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                    <input type="hidden" name="page" value="pages"/>
                    <input type="hidden" name="action" value="<?php echo customFrmText('action_frm'); ?>"/>
                    <?php PageForm::primary_input_hidden($page); ?>

                    <div id="left-side" class="col">
                        <?php PageForm::printMultiLangTitleDesc($page); ?>
                        <?php // Plugin fields render full-width here, as they did before the rail existed. ?>
                        <?php osc_run_hook('page_meta'); ?>
                    </div>

                    <div id="right-side" class="col-xl-4 col-lg-4">
                        <div class="card mb-3 page-publish-card">
                            <div class="card-body">
                                <h3 class="label"><?php _e('Publish'); ?></h3>
                                <div class="page-publish-actions">
                                    <button type="submit" class="btn btn-submit">
                                        <?php echo osc_esc_html(customFrmText('btn_text')); ?>
                                    </button>
                                    <?php if (customFrmText('edit')) { ?>
                                        <a href="javascript:history.go(-1)" class="btn btn-dim">
                                            <?php _e('Cancel'); ?>
                                        </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h3 class="label"><?php _e('Page settings'); ?></h3>

                                <?php if (count($templates) > 0 || count($registeredTemplates) > 0) { ?>
                                    <div class="mb-3">
                                        <label for="page_template"><?php _e('Page template'); ?></label>
                                        <select id="page_template" class="form-select form-select-sm"
                                                name="meta[template]">
                                            <option value="default" <?php echo $template_selected === 'default'
                                                ? 'selected' : ''; ?>><?php _e('Default template'); ?></option>
                                            <?php foreach ($registeredTemplates as $id => $spec) { ?>
                                                <option value="<?php echo osc_esc_html($id); ?>"
                                                    <?php echo $template_selected === $id ? 'selected' : ''; ?>>
                                                    <?php echo osc_esc_html($spec['label']); ?>
                                                </option>
                                            <?php } ?>
                                            <?php foreach ($templates as $template) { ?>
                                                <option value="<?php echo osc_esc_html($template); ?>"
                                                    <?php echo $template_selected === $template ? 'selected' : ''; ?>>
                                                    <?php echo osc_esc_html($template); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                <?php } ?>

                                <div class="mb-3">
                                    <label for="s_internal_name">
                                        <?php _e('Internal name'); ?> / <?php echo osc_esc_html(__('Slug')); ?>
                                    </label>
                                    <?php PageForm::internal_name_input_text($page); ?>
                                    <p class="page-field-hint"><?php _e('Used to quickly identify this page'); ?></p>
                                    <span class="help"></span>
                                </div>

                                <div class="form-check form-switch page-footer-toggle">
                                    <?php $b_link = (isset($page['b_link']) && $page['b_link']); ?>
                                    <input class="form-check-input" type="checkbox" role="switch" id="b_link"
                                           name="b_link" value="1" <?php echo $b_link ? 'checked' : ''; ?>/>
                                    <label class="form-check-label" for="b_link">
                                        <?php _e('Show a link in the footer'); ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
