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
 * Normalise a select field's declared options into a flat list of
 * ['value' => string, 'label' => string] pairs. Tolerates an associative
 * value=>label map, a flat list of scalars, or a list of
 * ['value'=>.., 'label'=>..] entries.
 *
 * @param array $options
 *
 * @return array
 */
function widgetConfigSelectOptions($options)
{
    $result = array();
    if (!is_array($options)) {
        return $result;
    }
    foreach ($options as $key => $option) {
        if (is_array($option)) {
            if (array_key_exists('value', $option) && is_scalar($option['value'])) {
                $value           = (string) $option['value'];
                $label           = isset($option['label']) && is_scalar($option['label'])
                    ? (string) $option['label'] : $value;
                $result[] = array('value' => $value, 'label' => $label);
            }
            continue;
        }
        if (!is_int($key)) {
            $result[] = array(
                'value' => (string) $key,
                'label' => is_scalar($option) ? (string) $option : (string) $key
            );
        } elseif (is_scalar($option)) {
            $result[] = array('value' => (string) $option, 'label' => (string) $option);
        }
    }

    return $result;
}


/**
 * DOM id for a config field's control. Every registered type's field group is
 * rendered server-side (and toggled client-side), so the id is namespaced by
 * type id to stay unique across groups.
 *
 * @param string $typeId
 * @param string $fieldName
 *
 * @return string
 */
function widgetConfigFieldId($typeId, $fieldName)
{
    return 'widget-cfg-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $typeId . '-' . $fieldName);
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


/**
 * Render one config field as a Bootstrap form control named config[<name>].
 * $disabled is true for fields belonging to a type that isn't the currently
 * selected one, so they don't post until the type is switched client-side.
 *
 * @param string $typeId
 * @param array  $field
 * @param mixed  $value
 * @param bool   $disabled
 *
 * @return void
 */
function renderWidgetConfigField($typeId, $field, $value, $disabled)
{
    $name = isset($field['name']) && is_string($field['name']) ? $field['name'] : '';
    if ($name === '') {
        return;
    }
    $label      = isset($field['label']) && is_string($field['label']) ? $field['label'] : $name;
    $fieldType  = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
    $fieldId    = widgetConfigFieldId($typeId, $name);
    $inputName  = 'config[' . $name . ']';
    $disabledAttr = $disabled ? 'disabled="disabled"' : '';
    ?>
    <div class="input-line">
        <?php if ($fieldType !== 'checkbox') { ?>
            <label for="<?php echo osc_esc_html($fieldId); ?>"><?php echo osc_esc_html($label); ?></label>
        <?php } ?>
        <div class="input">
            <?php switch ($fieldType) {
                case 'checkbox': ?>
                    <div class="form-label-checkbox">
                        <input type="checkbox" id="<?php echo osc_esc_html($fieldId); ?>"
                               name="<?php echo osc_esc_html($inputName); ?>" value="1"
                               <?php echo (!empty($value) && $value !== '0') ? 'checked="checked"' : ''; ?>
                               <?php echo $disabledAttr; ?> />
                        <label for="<?php echo osc_esc_html($fieldId); ?>"><?php echo osc_esc_html($label); ?></label>
                    </div>
                    <?php
                    break;
                case 'select': ?>
                    <select id="<?php echo osc_esc_html($fieldId); ?>" class="form-select form-select-sm"
                            name="<?php echo osc_esc_html($inputName); ?>" <?php echo $disabledAttr; ?>>
                        <?php foreach (widgetConfigSelectOptions($field['options'] ?? array()) as $option) { ?>
                            <option value="<?php echo osc_esc_html($option['value']); ?>"
                                <?php echo ((string) $value === $option['value']) ? 'selected="selected"' : ''; ?>>
                                <?php echo osc_esc_html($option['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <?php
                    break;
                case 'number': ?>
                    <input type="number" id="<?php echo osc_esc_html($fieldId); ?>" class="input-medium"
                           name="<?php echo osc_esc_html($inputName); ?>"
                           value="<?php echo osc_esc_html((string) $value); ?>" <?php echo $disabledAttr; ?>/>
                    <?php
                    break;
                case 'textarea': ?>
                    <textarea id="<?php echo osc_esc_html($fieldId); ?>" class="form-control"
                              name="<?php echo osc_esc_html($inputName); ?>" rows="4"
                              <?php echo $disabledAttr; ?>><?php echo osc_esc_html((string) $value); ?></textarea>
                    <?php
                    break;
                default: ?>
                    <input type="text" id="<?php echo osc_esc_html($fieldId); ?>" class="input-large"
                           name="<?php echo osc_esc_html($inputName); ?>"
                           value="<?php echo osc_esc_html((string) $value); ?>" <?php echo $disabledAttr; ?>/>
                    <?php
                    break;
            } ?>
        </div>
    </div>
    <?php
}


// Type id => description, used by the client-side toggle to show a help line
// under the picker without re-fetching anything from the server.
$widgetTypeDescriptions = array('' => '');
foreach ($widgetTypes as $typeId => $typeSpec) {
    $widgetTypeDescriptions[$typeId] = isset($typeSpec['description']) && is_string($typeSpec['description'])
        ? $typeSpec['description'] : '';
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
                    <div class="input-line">
                        <label for="widget_type_select"><?php _e('Widget type'); ?></label>
                        <div class="input">
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
                        </div>
                        <div class="help-box" id="widget_type_description"></div>
                    </div>
                    <div class="input-description-wide" id="widget-legacy-content"
                        <?php echo ($currentTypeId !== '') ? 'hidden' : ''; ?>>
                        <label><?php _e('HTML Code for the Widget'); ?></label>
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
                                    renderWidgetConfigField($typeId, $field, $fieldValue, !$isActive);
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
        var typeSelect   = document.getElementById('widget_type_select');
        var descBox      = document.getElementById('widget_type_description');
        var legacyWrap   = document.getElementById('widget-legacy-content');
        var fieldsWrap   = document.getElementById('widget-type-fields');
        var descriptions = <?php echo json_encode($widgetTypeDescriptions); ?>;

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
        }

        typeSelect.addEventListener('change', function () {
            applyType(typeSelect.value);
        });

        applyType(typeSelect.value);
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
