<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * One fieldset of config fields per widget type, shared by the widget editor and the
 * customiser. Reads $widgetTypes, $currentTypeId and $currentWidgetConfig from the file
 * that includes it; only the current type's fieldset is shown and enabled.
 */
if (!function_exists('widgetConfigFieldValue')) {
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
}

$widgetTypes         = isset($widgetTypes) && is_array($widgetTypes) ? $widgetTypes : array();
$currentTypeId       = isset($currentTypeId) ? (string) $currentTypeId : '';
$currentWidgetConfig = isset($currentWidgetConfig) && is_array($currentWidgetConfig) ? $currentWidgetConfig : array();

foreach ($widgetTypes as $typeId => $typeSpec) {
    $fields = isset($typeSpec['fields']) && is_array($typeSpec['fields']) ? $typeSpec['fields'] : array();
    if (empty($fields)) {
        continue;
    }
    $isActive = ($typeId === $currentTypeId);
    ?>
    <div class="widget-type-fieldset" data-type-id="<?php echo osc_esc_html($typeId); ?>"
        <?php echo $isActive ? '' : 'hidden'; ?>>
        <?php foreach ($fields as $field) {
            $fieldValue = widgetConfigFieldValue($field, $typeId, $currentTypeId, $currentWidgetConfig);
            osc_widget_config_field($typeId, $field, $fieldValue, !$isActive);
        } ?>
    </div>
<?php }
