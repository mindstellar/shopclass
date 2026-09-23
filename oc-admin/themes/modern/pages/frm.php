<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_enqueue_script('tiny_mce');
osc_enqueue_script('admin-editor');

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
 * One value from the page form's add/edit copy, keyed by name.
 *
 * @param string $return One of 'edit', 'title', 'action_frm' or 'btn_text'
 *
 * @return bool|string 'edit' is a bool, the rest are strings
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

osc_admin_page(array(
    'section' => __('Pages'),
));

/**
 * Filter callback for `admin_title`: prefix the browser title with the form's title.
 *
 * @param string $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf('%s &raquo; %s', customFrmText('title'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

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

// The editor's content fields: one value per locale, with what was typed winning over
// what is stored, so a rejected save comes back with the submission still in it.
$pageErrors   = __get('editorErrors');
$pageErrors   = is_array($pageErrors) ? $pageErrors : array();
$pageLocales  = array();
$pageTitles   = array();
$pageBodies   = array();
$pageSubmitted = Session::newInstance()->_getForm('aFieldsDescription');
// What was typed wins here too, the way PageForm::internal_name_input_text() reads it.
$pageInternalName = $page['s_internal_name'] ?? '';
if (Session::newInstance()->_getForm('s_internal_name') != '') {
    $pageInternalName = Session::newInstance()->_getForm('s_internal_name');
}
foreach (osc_get_admin_locales() as $pageLocale) {
    $code                = $pageLocale['pk_c_code'];
    $pageLocales[$code]  = $pageLocale['s_name'];
    // Named before the filter runs, because the hook reference is generated from these
    // call sites and a plugin author should read what it is given, not how it was found.
    $title               = $pageSubmitted[$code]['s_title'] ?? $page['locale'][$code]['s_title'] ?? '';
    $description         = $pageSubmitted[$code]['s_text'] ?? $page['locale'][$code]['s_text'] ?? '';
    $pageTitles[$code]   = osc_apply_filter('admin_page_title', $title, $page, $pageLocale);
    $pageBodies[$code]   = osc_apply_filter('admin_page_description', $description, $page, $pageLocale);
}

// Editor images go to the media library (unattached, reusable), so the flow works on
// unsaved pages and the same images are pickable elsewhere.
$pageUploadUrl = osc_admin_base_url(true)
    . '?page=ajax&action=resource_upload&owner_type=library&owner_id=0&' . osc_csrf_token_url();

$pageBackUrl = osc_admin_base_url(true) . '?page=pages';
$pageViewUrl = customFrmText('edit')
    ? osc_base_url(true) . '?page=page&id=' . $page['pk_i_id']
    : '';

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="adminPageForm">
    <?php
    $headActions = array();
    if ($pageViewUrl !== '') {
        $headActions[] = array(
            'label' => __('View page'),
            'url'   => $pageViewUrl,
            'icon'  => 'bi-box-arrow-up-right',
            'attrs' => array('target' => '_blank', 'rel' => 'noopener'),
        );
    }
    $headActions[] = array('label' => __('Back to pages'), 'url' => $pageBackUrl, 'variant' => 'dim');
    osc_admin_page_head(customFrmText('title'), $headActions);

    // The mode class sets the initial view; the template select toggles it live (see the
    // mode script + .page-mode-* CSS). The title always shows; the text editor and the
    // widget canvas swap.
    osc_admin_editor_open(array(
        'id'           => 'item-form',
        'class'        => 'page-editor',
        'page'         => 'pages',
        'action'       => customFrmText('action_frm'),
        'main_id'      => 'left-side',
        'main_class'   => 'page-mode-' . ($pb_is_builder ? 'builder' : 'classic'),
        'errors'       => $pageErrors,
        'error_labels' => array(
            's_title'         => __('Title'),
            's_text'          => __('Body'),
            's_internal_name' => __('Internal name'),
        ),
        'error_ids'    => array(
            's_title'         => osc_admin_field_id(array('name' => 's_title')),
            's_text'          => osc_admin_field_id(array('name' => 's_text')),
            's_internal_name' => 's_internal_name',
        ),
    ));

    PageForm::primary_input_hidden($page);

    // The posted names are the ones the save has always read; only what draws them is new.
    // No 'required': the rule is that one locale carries a title, not that this one does,
    // and the browser would refuse to submit a page titled in another language.
    osc_admin_field(array(
        'type'           => 'text',
        'name'           => 's_title',
        'translate_name' => '%s#s_title',
        'label'          => __('Title'),
        'layout'         => 'stacked',
        'translate'      => true,
        'locales'        => $pageLocales,
        'value'          => $pageTitles,
        'error'          => $pageErrors['s_title'] ?? '',
        'class'          => 'osc-editor-title',
        'placeholder'    => __('Enter title here'),
    ));

    // The wrapper is what the builder mode hides: a page composed from widgets has no
    // body to write.
    echo '<div class="multilang-description">';
    osc_admin_field(array(
        'type'           => 'richtext',
        'name'           => 's_text',
        'translate_name' => '%s#s_text',
        'label'          => __('Body'),
        'layout'         => 'stacked',
        'translate'      => true,
        // The title's strip above switches this field too; a second strip could disagree with it.
        'tabs'           => false,
        'locales'        => $pageLocales,
        'value'          => $pageBodies,
        'error'          => $pageErrors['s_text'] ?? '',
        'preset'         => 'full',
        'height'         => 460,
        'media'          => true,
        'upload_url'     => $pageUploadUrl,
    ));
    echo '</div>';

    // Functional widget canvas — rendered only for a page already saved with a builder
    // template, so its page.{id} widgets are real and an Add widget lands on a builder
    // page. Add/edit happen inline in the dialog below; delete goes through the
    // appearance action, threaded with page_builder_id so it returns here.
    if ($pb_is_builder) {
        $blockLocation = $pb_location;
        $pageId        = $pb_page_id;
        $blocks        = Widget::newInstance()->findByLocation($blockLocation);
        $widgetTypes   = osc_widget_types();

        osc_admin_panel_open(__('Widgets'), array(
            'class'   => 'page-blocks-card js-page-widgets',
            'actions' => array(array(
                'label' => __('Add widget'),
                'icon'  => 'bi-plus-lg',
                'class' => 'js-page-block-add',
            )),
        )); ?>
        <p class="page-field-hint">
            <?php _e('The widgets below make up this page. Widgets can also appear in your'
                . ' theme areas — manage those under Appearance.'); ?>
        </p>
        <div class="page-blocks-reorder-error alert alert-danger py-1 px-2 small d-none" role="alert"></div>
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
        <?php }
        osc_admin_panel_close();
    } else {
        // Placeholder shown when the template select is switched to a builder template but
        // the page is not yet saved as one.
        osc_admin_panel_open(__('Widgets'), array('class' => 'page-blocks-card js-page-widgets-hint')); ?>
        <p class="page-field-hint">
            <?php _e('Save this page with the Page builder template to compose it from'
                . ' widgets instead of the text editor.'); ?>
        </p>
        <?php
        osc_admin_panel_close();
    }

    // Plugin fields render full-width here, as they did before the rail existed.
    osc_run_hook('page_meta');

    osc_admin_editor_rail(array('id' => 'right-side'));

    // The pill says the state; the rows say the dates behind it, so neither repeats the other.
    $publishRows = array();
    if (!empty($page['dt_pub_date'])) {
        $publishRows[] = array('label' => __('Added'), 'value' => osc_format_date($page['dt_pub_date']));
    }
    if (!empty($page['dt_mod_date'])) {
        $publishRows[] = array('label' => __('Last saved'), 'value' => osc_format_date($page['dt_mod_date']));
    }
    osc_admin_publish_panel(array(
        'status' => customFrmText('edit')
            ? array(array('active', __('Published')))
            : array(array('inactive', __('Not saved yet'))),
        'rows'   => $publishRows,
    ));

    osc_admin_panel_open(__('Page settings'));

    if (count($templates) > 0 || count($registeredTemplates) > 0) {
        $templateOptions = array('default' => __('Default template'));
        foreach ($registeredTemplates as $id => $spec) {
            $templateOptions[$id] = $spec['label'];
        }
        foreach ($templates as $template) {
            $templateOptions[$template] = $template;
        }
        echo '<div class="osc-field">';
        echo '<label class="form-label" for="page_template">' . osc_esc_html(__('Page template')) . '</label>';
        osc_admin_select(array(
            'row'     => false,
            'id'      => 'page_template',
            'name'    => 'meta[template]',
            'options' => $templateOptions,
            'value'   => $template_selected,
        ));
        echo '</div>';
    } ?>

    <div class="page-footer-toggle">
        <?php osc_admin_checkbox(array(
            'name'    => 'b_link',
            'id'      => 'b_link',
            'value'   => '1',
            'checked' => !empty($page['b_link']),
            'label'   => __('Show a link in the footer'),
        )); ?>
    </div>

    <?php
    // Open on a rejected save, so the message is not behind a closed summary.
    osc_admin_disclosure_open(__('Advanced'), array(
        'summary_hint' => __('Internal name'),
        'open'         => isset($pageErrors['s_internal_name']),
    ));
    // Drawn here rather than through PageForm::internal_name_input_text(), which has no
    // slot for an error; the posted name and the id are the same either way.
    $pageIndelible = isset($page['b_indelible']) && $page['b_indelible'] == 1;
    osc_admin_field(array(
        'type'   => 'text',
        'id'     => 's_internal_name',
        'name'   => 's_internal_name',
        'label'  => __('Internal name') . ' / ' . __('Slug'),
        'layout' => 'stacked',
        'value'  => $pageInternalName,
        'help'   => __('Used to quickly identify this page'),
        'error'  => $pageErrors['s_internal_name'] ?? '',
        // Readonly, never disabled: a disabled control posts nothing, and the save refuses
        // a page whose internal name is empty -- so a system page could not be saved at all.
        'attrs'  => $pageIndelible ? array('readonly' => true) : array(),
    ));
    osc_admin_disclosure_close();

    osc_admin_panel_close();

    osc_admin_editor_close(array(
        array('label' => customFrmText('btn_text'), 'type' => 'submit', 'variant' => 'primary'),
        array('label' => __('Back to pages'), 'url' => $pageBackUrl, 'variant' => 'dim'),
    )); ?>
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
                // Matches widgetConfigFieldId() (the shared field renderer).
                return 'widget-cfg-' + (typeId + '-' + name).replace(/[^a-zA-Z0-9_-]/g, '-');
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
                        if (el.classList.contains('widget-image-input') && window.oscSyncWidgetImage) {
                            window.oscSyncWidgetImage(el);
                        }
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
