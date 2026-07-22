<?php

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

function admin_js_lang_string()
{
    ?>
    <script type="text/javascript">
        var osc = window.osc || {};
        <?php
        $lang = array(
            'nochange_expiration' => __('No change expiration'),
            'without_expiration'  => __('Without expiration'),
            'expiration_day'      => __('1 day'),
            'expiration_days'     => __('%d days'),
            'select_category'     => __('Select category'),
            'no_subcategory'      => __('No subcategory'),
            'select_subcategory'  => __('Select subcategory')
        );
        $locales = osc_get_locales();
        $codes = array();
        foreach ($locales as $locale) {
            $codes[] = osc_esc_js($locale['pk_c_code']);
        }
        ?>
        osc.locales = {};
        osc.locales._default = '<?php echo osc_language(); ?>';
        osc.locales.current = '<?php echo osc_current_admin_locale(); ?>';
        osc.locales.codes = <?php echo json_encode($codes); ?>;
        osc.locales.string = '[name*="' + osc.locales.codes.join('"],[name*="') + '"],.' + osc.locales.codes.join(',.');
        osc.langs = <?php echo json_encode($lang); ?>;
    </script>
    <?php
}


osc_add_hook('admin_header', 'admin_js_lang_string');

// favicons
function admin_header_favicons()
{
    $favicons   = array();
    $favicons[] = array(
        'rel'   => 'icon',
        'type'  => 'image/svg+xml',
        'href'  => osc_current_admin_theme_url('images/shopclass-favicon.svg')
    );
    $favicons[] = array(
        'rel'   => 'icon',
        'type'  => 'image/png',
        'sizes' => '32x32',
        'href'  => osc_current_admin_theme_url('images/favicon-32.png')
    );
    $favicons[] = array(
        'rel'   => 'icon',
        'type'  => 'image/png',
        'sizes' => '16x16',
        'href'  => osc_current_admin_theme_url('images/favicon-16.png')
    );
    $favicons[] = array(
        'rel'   => 'apple-touch-icon',
        'sizes' => '180x180',
        'href'  => osc_current_admin_theme_url('images/favicon-180.png')
    );
    $favicons[] = array(
        'rel'   => 'manifest',
        'href'  => osc_current_admin_theme_url('images/site.webmanifest')
    );

    $favicons = osc_apply_filter('admin_favicons', $favicons);

    foreach ($favicons as $f) { ?>
        <link<?php
            foreach (array('rel', 'type', 'sizes') as $attr) {
                if (!empty($f[$attr])) {
                    echo ' ' . $attr . '="' . osc_esc_html($f[$attr]) . '"';
                }
            }
        ?> href="<?php echo osc_esc_html($f['href']); ?>">
    <?php }
}


osc_add_hook('admin_header', 'admin_header_favicons');

// admin footer
function admin_footer_html()
{
    ?>
    <div class="admin-footer-credit">
        <?php printf(
            __('Thank you for using <a href="%s" target="_blank">Shopclass</a>'),
            'https://github.com/mindstellar/shopclass/'
        ); ?> -
        <a title="<?php _e('Forums'); ?>" href="https://github.com/mindstellar/shopclass/discussions"
           target="_blank" rel="noopener"><?php _e('Forums'); ?></a> &middot;
        <a title="<?php _e('Report Issue'); ?>" href="https://github.com/mindstellar/shopclass/issues/"
           target="_blank" rel="noopener"><?php _e('Report Issue'); ?></a>
    </div>
    <div class="admin-footer-version">
        <strong>Shopclass <?php echo OSCLASS_VERSION; ?></strong>
    </div><?php
}


osc_add_hook('admin_content_footer', 'admin_footer_html');

/**
 * @param $slug
 * @param $language_version
 *
 * @return bool
 */
function check_market_language_compatibility($slug, $language_version)
{
    return osc_check_language_update($slug);
}


/**
 * @param $versions
 *
 * @return bool
 */
function check_market_compatibility($versions)
{
    $versions        = explode(',', $versions);
    $current_version = OSCLASS_VERSION;

    foreach ($versions as $_version) {
        $result = version_compare2(OSCLASS_VERSION, $_version);

        if ($result == 0 || $result == -1) {
            return true;
        }
    }

    return false;
}


function check_version_admin_footer()
{
    if ((time() - osc_last_version_check()) > (24 * 3600)) {
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_version', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
            });
        </script>
        <?php
    }
}


osc_add_hook('admin_footer', 'check_version_admin_footer');

function check_languages_admin_footer()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_languages', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        });
    </script>
    <?php
}


function check_themes_admin_footer()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_themes', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        });
    </script>
    <?php
}


function check_plugins_admin_footer()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_plugins', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        });
    </script>
    <?php
}

/**
 * Normalise a widget-type select field's declared options into a flat list of
 * ['value' => string, 'label' => string] pairs. Tolerates an associative
 * value=>label map, a flat list of scalars, or a list of ['value'=>..,'label'=>..]
 * entries. Shared by the appearance widget form and the page-builder block form.
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
                $value = (string) $option['value'];
                $label = isset($option['label']) && is_scalar($option['label'])
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
 * DOM id for a widget config control, namespaced by type so ids stay unique
 * across the (hidden) per-type field groups. Shared by the appearance widget form
 * and the page-builder block dialog.
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
 * Render one widget config field as a Bootstrap control named config[<name>].
 * The single source both the appearance widget editor and the page-builder block
 * dialog use, so a field looks and behaves the same in both. $disabled is true
 * for a field whose type is not the currently selected one, so it does not post
 * until the type is switched client-side. Supported types:
 * text, number, textarea, select, checkbox, code, image. Field types map to what
 * CAdminAppearance::buildWidgetConfig() accepts.
 *
 * @param string $typeId
 * @param array  $field
 * @param mixed  $value    current value (empty in add-mode; the dialog populates
 *                         edits client-side)
 * @param bool   $disabled
 *
 * @return void
 */
function osc_widget_config_field($typeId, $field, $value, $disabled)
{
    $name = isset($field['name']) && is_string($field['name']) ? $field['name'] : '';
    if ($name === '') {
        return;
    }
    $label     = isset($field['label']) && is_string($field['label']) ? $field['label'] : $name;
    $fieldType = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
    $id        = widgetConfigFieldId($typeId, $name);
    $inputName = 'config[' . $name . ']';
    $dis       = $disabled ? 'disabled="disabled"' : '';
    $val       = (string) $value;
    ?>
    <div class="mb-3">
        <?php if ($fieldType !== 'checkbox') { ?>
            <label for="<?php echo osc_esc_html($id); ?>"><?php echo osc_esc_html($label); ?></label>
        <?php } ?>
        <?php switch ($fieldType) {
            case 'checkbox': ?>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="<?php echo osc_esc_html($id); ?>"
                           name="<?php echo osc_esc_html($inputName); ?>" value="1"
                           <?php echo (!empty($val) && $val !== '0') ? 'checked="checked"' : ''; ?>
                           <?php echo $dis; ?>/>
                    <label class="form-check-label"
                           for="<?php echo osc_esc_html($id); ?>"><?php echo osc_esc_html($label); ?></label>
                </div>
                <?php break;
            case 'select': ?>
                <select id="<?php echo osc_esc_html($id); ?>" class="form-select form-select-sm"
                        name="<?php echo osc_esc_html($inputName); ?>" <?php echo $dis; ?>>
                    <?php foreach (widgetConfigSelectOptions($field['options'] ?? array()) as $opt) { ?>
                        <option value="<?php echo osc_esc_html($opt['value']); ?>"
                            <?php echo ($val === $opt['value']) ? 'selected="selected"' : ''; ?>>
                            <?php echo osc_esc_html($opt['label']); ?>
                        </option>
                    <?php } ?>
                </select>
                <?php break;
            case 'number': ?>
                <input type="number" id="<?php echo osc_esc_html($id); ?>" class="form-control form-control-sm"
                       name="<?php echo osc_esc_html($inputName); ?>"
                       value="<?php echo osc_esc_html($val); ?>" <?php echo $dis; ?>/>
                <?php break;
            case 'code': ?>
                <?php // Raw HTML/JS: a plain monospace textarea, never TinyMCE. The
                      // widget-code-editor class keeps the appearance TinyMCE init away. ?>
                <textarea id="<?php echo osc_esc_html($id); ?>" class="form-control widget-code-editor"
                          style="font-family:monospace" rows="8" spellcheck="false" autocomplete="off"
                          name="<?php echo osc_esc_html($inputName); ?>"
                          <?php echo $dis; ?>><?php echo osc_esc_html($val); ?></textarea>
                <?php break;
            case 'textarea': ?>
                <textarea id="<?php echo osc_esc_html($id); ?>" class="form-control" rows="5"
                          name="<?php echo osc_esc_html($inputName); ?>"
                          <?php echo $dis; ?>><?php echo osc_esc_html($val); ?></textarea>
                <?php break;
            case 'image': ?>
                <?php // A media URL chosen via the picker (parts/media-picker.php). ?>
                <div class="widget-image-field">
                    <input type="hidden" id="<?php echo osc_esc_html($id); ?>" class="widget-image-input"
                           name="<?php echo osc_esc_html($inputName); ?>"
                           value="<?php echo osc_esc_html($val); ?>" <?php echo $dis; ?>/>
                    <div class="widget-image-preview"<?php echo $val !== '' ? '' : ' hidden'; ?>>
                        <img src="<?php echo osc_esc_html($val); ?>" alt=""/>
                    </div>
                    <div class="widget-image-actions">
                        <button type="button" class="btn btn-secondary btn-sm widget-image-choose">
                            <?php _e('Choose image'); ?>
                        </button>
                        <button type="button" class="btn btn-link btn-sm widget-image-clear"
                            <?php echo $val !== '' ? '' : 'hidden'; ?>><?php _e('Remove'); ?></button>
                    </div>
                </div>
                <?php break;
            default: ?>
                <input type="text" id="<?php echo osc_esc_html($id); ?>" class="form-control form-control-sm"
                       name="<?php echo osc_esc_html($inputName); ?>"
                       value="<?php echo osc_esc_html($val); ?>" <?php echo $dis; ?>/>
                <?php break;
        } ?>
    </div>
    <?php
}

/* end of file */
