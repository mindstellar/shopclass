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

// Inline mode: the widgets screen loads this same view into a widget's row so the
// editor opens where the control is. Only the page chrome is skipped — the form
// below stays the single source for both the full page and the inline editor.
$inline = Params::getParam('inline') === '1';
if (!$inline) {
    osc_enqueue_script('tiny_mce');
}

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

// Widget types available from this screen: registered types filtered by the
// current admin's capability, plus (if editing) the widget's own type even
// when a capability change would otherwise hide it from the picker, so an
// existing typed widget always remains editable.
$allWidgetTypes = osc_widget_types();
$isModerator    = osc_is_moderator();

$widgetTypes = array();
foreach ($allWidgetTypes as $typeId => $typeSpec) {
    if ($isModerator && isset($typeSpec['capability']) && $typeSpec['capability'] === 'super_admin') {
        continue;
    }
    $widgetTypes[$typeId] = $typeSpec;
}

$currentTypeId = '';
if ($edit && !empty($widget['s_type'])) {
    $currentTypeId = (string) $widget['s_type'];
    if (!isset($widgetTypes[$currentTypeId]) && isset($allWidgetTypes[$currentTypeId])) {
        $widgetTypes[$currentTypeId] = $allWidgetTypes[$currentTypeId];
    }
}

$currentWidgetConfig = array();
if ($currentTypeId !== '' && !empty($widget['s_config'])) {
    $decodedWidgetConfig = json_decode($widget['s_config'], true);
    if (is_array($decodedWidgetConfig)) {
        $currentWidgetConfig = $decodedWidgetConfig;
    }
}


/**
 * The value a config field should show: the widget's saved config when this
 * is the widget's current type, otherwise the field's declared default.
 *
 * @param array  $field
 * @param string $typeId
 * @param string $currentTypeId
 * @param array  $currentWidgetConfig
 *
 * @return mixed
 */
function widgetConfigFieldValue($field, $typeId, $currentTypeId, $currentWidgetConfig)
{
    $name    = isset($field['name']) && is_string($field['name']) ? $field['name'] : '';
    $default = $field['default'] ?? '';
    if ($typeId === $currentTypeId && array_key_exists($name, $currentWidgetConfig)) {
        return $currentWidgetConfig[$name];
    }

    return $default;
}



// Type id => description, used by the client-side toggle to show a help line
// under the picker without re-fetching anything from the server.
$widgetTypeDescriptions = array('' => '');
foreach ($widgetTypes as $typeId => $typeSpec) {
    $widgetTypeDescriptions[$typeId] = isset($typeSpec['description']) && is_string($typeSpec['description'])
        ? $typeSpec['description'] : '';
}

// Type id => danger warning, populated only for super_admin-capability types
// (which store raw HTML/JS). The client toggle shows an alert-danger box with
// this copy while such a type is selected. A non-super_admin type maps to an
// empty string, which hides the box.
$widgetTypeDanger = array('' => '');
foreach ($widgetTypes as $typeId => $typeSpec) {
    $isSuperAdminType = isset($typeSpec['capability']) && $typeSpec['capability'] === 'super_admin';
    $widgetTypeDanger[$typeId] = ($isSuperAdminType && isset($typeSpec['description'])
        && is_string($typeSpec['description'])) ? $typeSpec['description'] : '';
}

if (!$inline) {
    osc_add_hook('admin_page_header', 'customPageHeader');
}
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


if (!$inline) {
    osc_add_filter('admin_title', 'customPageTitle');
}
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


if (!$inline) {
    osc_add_hook('admin_header', 'customHead', 10);
    osc_current_admin_theme_path('parts/header.php');
} ?>
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
                <?php // When managing a static page's blocks, carry the page id so the ?>
                <?php // save returns to that page editor instead of the appearance screen. ?>
                <?php if ((int)Params::getParam('page_builder_id') > 0) { ?>
                    <input type="hidden" name="page_builder_id"
                           value="<?php echo (int)Params::getParam('page_builder_id'); ?>"/>
                <?php } ?>
                <fieldset>
                    <div class="mb-3">
                        <label for="widget_description"><?php _e('Description (for internal purposes only)'); ?></label>
                        <input type="text" class="form-control form-control-sm" id="widget_description"
                               name="description" value="<?php if ($edit) {
                                   echo osc_esc_html($widget['s_description']);
                                                         } ?>"/>
                    </div>
                    <div class="mb-3">
                        <label for="widget_type_select"><?php _e('Widget type'); ?></label>
                        <select id="widget_type_select" name="s_type" class="form-select form-select-sm">
                            <option value="" <?php echo ($currentTypeId === '') ? 'selected="selected"' : ''; ?>>
                                <?php echo osc_esc_html(__('Custom HTML (legacy)')); ?>
                            </option>
                            <?php foreach ($widgetTypes as $typeId => $typeSpec) { ?>
                                <option value="<?php echo osc_esc_html($typeId); ?>"
                                    <?php echo ($currentTypeId === $typeId) ? 'selected="selected"' : ''; ?>>
                                    <?php echo osc_esc_html($typeSpec['label']); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <p class="help-box" id="widget_type_description"></p>
                    </div>
                    <div class="alert alert-danger" role="alert" id="widget_type_danger" hidden></div>
                    <div class="mb-3" id="widget-legacy-content"
                        <?php echo ($currentTypeId !== '') ? 'hidden' : ''; ?>>
                        <label for="body"><?php _e('HTML Code for the Widget'); ?></label>
                        <textarea name="content" id="body"><?php if ($edit) {
                                echo osc_esc_html($widget['s_content']);
                                                           } ?></textarea>
                    </div>
                    <div id="widget-type-fields">
                        <?php foreach ($widgetTypes as $typeId => $typeSpec) {
                            $fields = isset($typeSpec['fields']) && is_array($typeSpec['fields'])
                                ? $typeSpec['fields'] : array();
                            if (empty($fields)) {
                                continue;
                            }
                            $isActive = ($typeId === $currentTypeId);
                            ?>
                            <div class="widget-type-fieldset" data-type-id="<?php echo osc_esc_html($typeId); ?>"
                                <?php echo $isActive ? '' : 'hidden'; ?>>
                                <?php foreach ($fields as $field) {
                                    $fieldValue = widgetConfigFieldValue(
                                        $field,
                                        $typeId,
                                        $currentTypeId,
                                        $currentWidgetConfig
                                    );
                                    osc_widget_config_field($typeId, $field, $fieldValue, !$isActive);
                                } ?>
                            </div>
                        <?php } ?>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit"><?php echo $button; ?></button>
                    </div>
                </fieldset>
            </form>
        </div>
    </div>
</div>
<script>
    // This view is also loaded inline into the widgets screen, i.e. injected after
    // DOMContentLoaded has already fired — a plain listener would never run and the
    // editor and type switcher would sit dead. Run now when the document is ready.
    function oscWidgetReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }
    // TinyMCE 7 for the legacy widget-content editor. Deferred and guarded so it
    // never runs before tinymce.min.js has loaded (the old inline tinyMCE.init
    // threw "tinyMCE is not defined"), scoped to non-code textareas, with paste
    // cleanup. extended_valid_elements keeps <script> for super_admin legacy
    // widgets that embed one.
    oscWidgetReady(function () {
        if (typeof tinymce === 'undefined') {
            return;
        }
        tinymce.init({
            selector: 'textarea:not(.widget-code-editor)',
            promotion: false,
            skin: window.oscTinymceTheme ? window.oscTinymceTheme().skin : 'oxide',
            content_css: window.oscTinymceTheme ? window.oscTinymceTheme().content_css : 'default',
            branding: false,
            menubar: false,
            height: 340,
            relative_urls: false,
            remove_script_host: false,
            convert_urls: false,
            entity_encoding: 'raw',
            extended_valid_elements: 'script[type|src|charset|defer]',
            plugins: 'advlist anchor autolink charmap code fullscreen image insertdatetime'
                + ' link lists media preview searchreplace table visualblocks',
            toolbar: 'undo redo | blocks | bold italic underline | bullist numlist'
                + ' | link image media table | alignleft aligncenter alignright'
                + ' | removeformat | visualblocks code fullscreen preview',
            smart_paste: true,
            paste_as_text: false,
            paste_merge_formats: true,
            paste_data_images: false,
            paste_remove_styles_if_webkit: true,
            paste_webkit_styles: 'none',
            content_style: 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,'
                + 'Helvetica Neue,Arial,sans-serif;font-size:16px;line-height:1.55;color:#14181f}'
        });
    });
</script>
<script type="text/javascript">
    oscWidgetReady(function () {
        var typeSelect   = document.getElementById('widget_type_select');
        var descBox      = document.getElementById('widget_type_description');
        var dangerBox    = document.getElementById('widget_type_danger');
        var legacyWrap   = document.getElementById('widget-legacy-content');
        var fieldsWrap   = document.getElementById('widget-type-fields');
        var descriptions = <?php echo json_encode($widgetTypeDescriptions); ?>;
        var dangers      = <?php echo json_encode($widgetTypeDanger); ?>;

        if (!typeSelect) {
            return;
        }

        function setGroupEnabled(group, enabled) {
            var controls = group.querySelectorAll('input, select, textarea');
            for (var i = 0; i < controls.length; i++) {
                controls[i].disabled = !enabled;
            }
        }

        function applyType(typeId) {
            if (fieldsWrap) {
                var groups = fieldsWrap.querySelectorAll('[data-type-id]');
                for (var i = 0; i < groups.length; i++) {
                    var isActive = (groups[i].getAttribute('data-type-id') === typeId);
                    groups[i].hidden = !isActive;
                    setGroupEnabled(groups[i], isActive);
                }
            }

            if (legacyWrap) {
                legacyWrap.hidden = (typeId !== '');
            }

            if (descBox) {
                descBox.textContent = descriptions[typeId] || '';
            }

            if (dangerBox) {
                var danger = dangers[typeId] || '';
                dangerBox.textContent = danger;
                dangerBox.hidden = (danger === '');
            }
        }

        typeSelect.addEventListener('change', function () {
            applyType(typeSelect.value);
        });

        applyType(typeSelect.value);
    });
</script>
<script type="text/javascript">
    oscWidgetReady(function () {
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
<?php if (!$inline) {
    // Inline mode: the widgets screen already carries the picker and the footer.
    osc_current_admin_theme_path('parts/media-picker.php');
    osc_current_admin_theme_path('parts/footer.php');
} ?>
