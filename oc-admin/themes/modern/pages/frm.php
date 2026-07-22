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

// Page-builder state. A page whose saved template is a builder template composes
// its body from widget blocks stored at page.{id}; the canvas needs a saved id
// for the location, so it appears only when editing an existing page.
$pb_saved_template = isset($meta['template']) ? (string)$meta['template'] : '';
$pb_saved_spec     = ($pb_saved_template !== '') ? osc_page_template($pb_saved_template) : null;
$pb_page_id        = isset($page['pk_i_id']) ? (int)$page['pk_i_id'] : 0;
$pb_is_builder     = ($pb_saved_spec !== null && !empty($pb_saved_spec['builder']) && $pb_page_id > 0);
$pb_location       = $pb_is_builder ? ('page.' . $pb_page_id) : '';

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
    // Editor images go to the media library (unattached, reusable), so the flow
    // works on unsaved pages and the same images are pickable elsewhere.
    $uploadUrl = osc_admin_base_url(true)
        . '?page=ajax&action=resource_upload&owner_type=library&owner_id=0&' . osc_csrf_token_url();
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof tinymce === 'undefined') {
                return;
            }
            var uploadUrl = <?php echo json_encode($uploadUrl); ?>;
            var cfg = {
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
            };
            // Drag/drop and paste auto-upload straight to the library; the image
            // dialog's picker opens the media library (browse existing or upload).
            cfg.automatic_uploads = true;
            cfg.images_upload_credentials = true;
            cfg.images_upload_url = uploadUrl;
            cfg.file_picker_types = 'image';
            cfg.file_picker_callback = function (cb, value, meta) {
                if (meta.filetype !== 'image' || !window.oscMediaPicker) {
                    return;
                }
                window.oscMediaPicker.open(function (url) {
                    cb(url, { title: '' });
                });
            };
            tinymce.init(cfg);
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
        </div>
    </div>
    <div class="row">
        <div class="col">
            <div id="item-form">
                <form class="row page-editor" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                    <input type="hidden" name="page" value="pages"/>
                    <input type="hidden" name="action" value="<?php echo customFrmText('action_frm'); ?>"/>
                    <?php PageForm::primary_input_hidden($page); ?>

                    <?php // The mode class sets the initial view; the template select toggles
                    // it live (see the mode script + .page-mode-* CSS). The title always
                    // shows; the text editor and the widget canvas swap. ?>
                    <div id="left-side" class="col page-mode-<?php echo $pb_is_builder ? 'builder' : 'classic'; ?>">
                        <?php PageForm::printMultiLangTitleDesc($page); ?>
                        <?php
                        // Functional widget canvas — rendered only for a page already saved
                        // with a builder template, so its page.{id} widgets are real and an
                        // Add widget lands on a builder page. Add/edit happen inline in the
                        // dialog below; delete goes through the appearance action, threaded
                        // with page_builder_id so it returns here.
                        if ($pb_is_builder) {
                            $blockLocation = $pb_location;
                            $pageId        = $pb_page_id;
                            $blocks        = Widget::newInstance()->findByLocation($blockLocation);
                            $widgetTypes   = osc_widget_types();
                            ?>
                            <div class="card mb-3 page-blocks-card js-page-widgets">
                                <div class="card-body">
                                    <div class="page-blocks-head">
                                        <h3 class="label"><?php _e('Widgets'); ?></h3>
                                        <button type="button" class="btn btn-secondary btn-sm js-page-block-add">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i> <?php _e('Add widget'); ?>
                                        </button>
                                    </div>
                                    <p class="page-field-hint">
                                        <?php _e('The widgets below make up this page. Widgets can also appear in your'
                                            . ' theme areas — manage those under Appearance.'); ?>
                                    </p>
                                    <div class="page-blocks-reorder-error alert alert-danger py-1 px-2 small d-none"
                                         role="alert"></div>
                                    <?php if (count($blocks) > 0) { ?>
                                        <ul class="page-blocks-list js-page-blocks"
                                            data-location="<?php echo osc_esc_html($blockLocation); ?>">
                                            <?php foreach ($blocks as $b) {
                                                $wid       = (int)$b['pk_i_id'];
                                                $isTyped   = !empty($b['s_type']) && isset($widgetTypes[$b['s_type']]);
                                                $typeLabel = $isTyped
                                                    ? $widgetTypes[$b['s_type']]['label']
                                                    : __('Custom HTML');
                                                // The inline dialog can edit a block only if the user may author its
                                                // type (registered, and not a super_admin type for a moderator).
                                                // Everything else falls back to the appearance widget editor, which
                                                // enforces the same gate server-side.
                                                $inlineEdit = $isTyped
                                                    && !(($widgetTypes[$b['s_type']]['capability'] ?? 'admin') === 'super_admin'
                                                        && osc_is_moderator());
                                                $editUrl   = osc_admin_base_url(true) . '?page=appearance'
                                                    . '&action=edit_widget&id=' . $wid
                                                    . '&location=' . rawurlencode($blockLocation)
                                                    . '&page_builder_id=' . $pageId;
                                                $deleteUrl = osc_admin_base_url(true) . '?page=appearance'
                                                    . '&action=delete_widget&id=' . $wid
                                                    . '&page_builder_id=' . $pageId . '&' . osc_csrf_token_url();
                                                ?>
                                                <li class="page-block-row" data-widget-id="<?php echo $wid; ?>"
                                                    data-type="<?php echo osc_esc_html((string)($b['s_type'] ?? '')); ?>"
                                                    data-description="<?php echo osc_esc_html($b['s_description']); ?>"
                                                    data-config="<?php echo osc_esc_html((string)($b['s_config'] ?? '')); ?>">
                                                    <span class="page-block-handle" draggable="true" tabindex="0"
                                                          role="button"
                                                          aria-label="<?php echo osc_esc_html(sprintf(
                                                              __('Reorder widget %s. Drag, or focus and press the up'
                                                              . ' or down arrow keys.'),
                                                              $b['s_description']
                                                          )); ?>">
                                                        <i class="bi bi-grip-vertical" aria-hidden="true"></i>
                                                    </span>
                                                    <span class="page-block-main">
                                                        <span class="page-block-desc">
                                                            <?php echo osc_esc_html($b['s_description']); ?>
                                                        </span>
                                                        <span class="page-block-type">
                                                            <?php echo osc_esc_html($typeLabel); ?>
                                                        </span>
                                                    </span>
                                                    <span class="page-block-actions">
                                                        <?php if ($inlineEdit) { ?>
                                                            <button type="button" class="btn btn-link btn-sm p-0
                                                                    js-page-block-edit"><?php _e('Edit'); ?></button>
                                                        <?php } else { ?>
                                                            <a href="<?php echo osc_esc_html($editUrl); ?>">
                                                                <?php _e('Edit'); ?>
                                                            </a>
                                                        <?php } ?>
                                                        <a href="<?php echo osc_esc_html($deleteUrl); ?>"
                                                           class="page-block-delete"
                                                           data-confirm="<?php echo osc_esc_html(
                                                               __('Delete this widget?')
                                                           ); ?>"><?php _e('Delete'); ?></a>
                                                    </span>
                                                </li>
                                            <?php } ?>
                                        </ul>
                                    <?php } else { ?>
                                        <p class="page-blocks-empty">
                                            <?php _e('No widgets yet. Add your first widget to build this page.'); ?>
                                        </p>
                                    <?php } ?>
                                </div>
                            </div>
                        <?php } else { ?>
                            <?php // Placeholder shown when the template select is switched to a
                            // builder template but the page is not yet saved as one. ?>
                            <div class="card mb-3 page-blocks-card js-page-widgets-hint">
                                <div class="card-body">
                                    <div class="page-blocks-head">
                                        <h3 class="label"><?php _e('Widgets'); ?></h3>
                                    </div>
                                    <p class="page-field-hint">
                                        <?php _e('Save this page with the Page builder template to compose it from'
                                            . ' widgets instead of the text editor.'); ?>
                                    </p>
                                </div>
                            </div>
                        <?php } ?>
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
                                    <?php if (customFrmText('edit')) { ?>
                                        <a class="page-view-link"
                                           href="<?php echo osc_esc_html(osc_base_url(true) . '?page=page&id='
                                               . $page['pk_i_id']); ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-arrow-up-right-square me-1" aria-hidden="true"></i>
                                            <?php _e('View page on site'); ?>
                                        </a>
                                    <?php } ?>
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
<script>
    // Live-switch the editor to match the chosen page template: a builder template
    // shows the widget canvas, anything else shows the text editor. Server-set
    // .page-mode-* avoids a flash; this only maintains it on change.
    (function () {
        var select = document.getElementById('page_template');
        var main   = document.getElementById('left-side');
        if (!select || !main) { return; }
        var builderIds = <?php
            $pb_builder_ids = array();
        foreach ($registeredTemplates as $rid => $rspec) {
            if (!empty($rspec['builder'])) {
                $pb_builder_ids[] = $rid;
            }
        }
            echo json_encode($pb_builder_ids);
        ?>;
        function sync() {
            var builder = builderIds.indexOf(select.value) !== -1;
            main.classList.toggle('page-mode-builder', builder);
            main.classList.toggle('page-mode-classic', !builder);
        }
        select.addEventListener('change', sync);
        sync();
    })();
</script>
<?php if ($pb_is_builder) {
    // The block dialog lives outside the page form (it carries its own form).
    $pbf_location        = $pb_location;
    $pbf_page_builder_id = $pb_page_id;
    ?>
    <dialog id="pageBlockDialog" class="osc-dialog osc-dialog-wide">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title" id="pageBlockDialogTitle"><?php _e('Add widget'); ?></p>
            <?php require __DIR__ . '/../appearance/page-block-form.php'; ?>
        </div>
    </dialog>
    <script>
        // Add / Edit block: the dialog holds one add-mode form; Edit repurposes it
        // for a specific block, populating type + config from the row's data-* .
        (function () {
            'use strict';
            var dialog = document.getElementById('pageBlockDialog');
            var form   = document.getElementById('pageBlockForm');
            if (!dialog || !form) { return; }
            var title  = document.getElementById('pageBlockDialogTitle');
            var action = document.getElementById('pbfAction');
            var widId  = document.getElementById('pbfWidgetId');
            var desc   = document.getElementById('pbfDescription');
            var type   = document.getElementById('pbfType');
            var submit = document.getElementById('pbfSubmit');

            function fieldId(typeId, name) {
                return 'pbf-' + (typeId + '-' + name).replace(/[^a-zA-Z0-9_-]/g, '-');
            }
            function resetForm() {
                if (action) { action.value = 'add_widget_post'; }
                if (widId) { widId.value = ''; }
                if (desc) { desc.value = ''; }
                form.querySelectorAll('#pbfFields input, #pbfFields select, #pbfFields textarea')
                    .forEach(function (c) {
                        if (c.type === 'checkbox') { c.checked = false; } else { c.value = ''; }
                    });
            }
            function openAdd() {
                resetForm();
                if (title) { title.textContent = <?php echo json_encode(__('Add widget')); ?>; }
                if (submit) { submit.textContent = <?php echo json_encode(__('Add widget')); ?>; }
                if (type && window.pageBlockFormApplyType) { window.pageBlockFormApplyType(type.value); }
                dialog.showModal();
            }
            function openEdit(row) {
                resetForm();
                if (title) { title.textContent = <?php echo json_encode(__('Edit widget')); ?>; }
                if (submit) { submit.textContent = <?php echo json_encode(__('Save changes')); ?>; }
                if (action) { action.value = 'edit_widget_post'; }
                if (widId) { widId.value = row.getAttribute('data-widget-id') || ''; }
                if (desc) { desc.value = row.getAttribute('data-description') || ''; }
                var typeId = row.getAttribute('data-type') || '';
                if (type) {
                    type.value = typeId;
                    if (window.pageBlockFormApplyType) { window.pageBlockFormApplyType(typeId); }
                }
                var config = {};
                try { config = JSON.parse(row.getAttribute('data-config') || '{}') || {}; } catch (e) {}
                Object.keys(config).forEach(function (key) {
                    var el = document.getElementById(fieldId(typeId, key));
                    if (!el) { return; }
                    var val = config[key];
                    if (el.type === 'checkbox') {
                        el.checked = (val && val !== '0' && val !== 0);
                    } else {
                        el.value = (val === null || val === undefined) ? '' : val;
                    }
                });
                dialog.showModal();
            }

            var addBtn = document.querySelector('.js-page-block-add');
            if (addBtn) { addBtn.addEventListener('click', openAdd); }
            document.querySelectorAll('.js-page-block-edit').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row = btn.closest('.page-block-row');
                    if (row) { openEdit(row); }
                });
            });
        })();
    </script>
<?php } ?>
<script>
    // Page-builder blocks: confirm deletes and persist drag/keyboard reordering to
    // the shared reorder endpoint (scoped by the list's data-location). Mirrors the
    // appearance widgets screen; no-op when the canvas is absent.
    (function () {
        'use strict';

        document.querySelectorAll('.page-block-delete').forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (!window.confirm(link.getAttribute('data-confirm') || 'Delete this block?')) {
                    e.preventDefault();
                }
            });
        });

        var list = document.querySelector('.js-page-blocks');
        if (!list) {
            return;
        }

        var reorderUrl = <?php echo json_encode(
            osc_admin_base_url(true) . '?page=appearance&action=reorder_widgets_post&' . osc_csrf_token_url()
        ); ?>;
        var errorBox = document.querySelector('.page-blocks-reorder-error');

        function blockIds() {
            return Array.prototype.map.call(
                list.querySelectorAll('li[data-widget-id]'),
                function (li) { return li.getAttribute('data-widget-id'); }
            );
        }

        function commitOrder() {
            var body = new URLSearchParams();
            body.set('location', list.getAttribute('data-location') || '');
            blockIds().forEach(function (id) { body.append('ids[]', id); });
            fetch(reorderUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (r) { return r.json(); }).then(function (json) {
                if (json && !json.error) {
                    if (errorBox) { errorBox.classList.add('d-none'); }
                } else {
                    showError();
                }
            }).catch(showError);
        }

        function showError() {
            if (errorBox) {
                errorBox.textContent = <?php echo json_encode(
                    __('Could not save the new widget order. Reloading the page.')
                ); ?>;
                errorBox.classList.remove('d-none');
            }
            window.setTimeout(function () { window.location.reload(); }, 1500);
        }

        function move(row, dir) {
            var sibling = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
            if (!sibling) { return; }
            if (dir < 0) { list.insertBefore(row, sibling); } else { list.insertBefore(sibling, row); }
            var handle = row.querySelector('.page-block-handle');
            if (handle) { handle.focus(); }
            commitOrder();
        }

        var dragged = null, orderAtStart = null;
        list.querySelectorAll('.page-block-handle').forEach(function (handle) {
            handle.addEventListener('dragstart', function (e) {
                dragged = handle.closest('li');
                if (!dragged) { return; }
                orderAtStart = blockIds().join(',');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', dragged.getAttribute('data-widget-id') || ''); } catch (err) {}
                dragged.classList.add('page-block-dragging');
            });
            handle.addEventListener('dragend', function () {
                if (dragged) {
                    dragged.classList.remove('page-block-dragging');
                    if (orderAtStart !== null && blockIds().join(',') !== orderAtStart) { commitOrder(); }
                }
                dragged = null;
                orderAtStart = null;
            });
            handle.addEventListener('keydown', function (e) {
                var row = handle.closest('li');
                if (!row) { return; }
                if (e.key === 'ArrowUp') { e.preventDefault(); move(row, -1); }
                else if (e.key === 'ArrowDown') { e.preventDefault(); move(row, 1); }
            });
        });

        list.querySelectorAll('li[data-widget-id]').forEach(function (row) {
            row.addEventListener('dragover', function (e) {
                if (!dragged || dragged === row) { return; }
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                var rect = row.getBoundingClientRect();
                var before = (e.clientY - rect.top) < rect.height / 2;
                list.insertBefore(dragged, before ? row : row.nextElementSibling);
            });
            row.addEventListener('drop', function (e) { e.preventDefault(); });
        });
    })();
</script>
<?php osc_current_admin_theme_path('parts/media-picker.php'); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
