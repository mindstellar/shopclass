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

osc_enqueue_script('php-date');
osc_enqueue_script('tiny_mce');
osc_enqueue_script('admin-editor');

// The photo grid stages its uploads against this token, and the token is a cookie. Ask for
// it before the page prints: two photos chosen at once upload at the same time, and with no
// cookie yet each request would mint a token of its own and only one would survive.
if (osc_images_enabled_at_items()) {
    osc_upload_token();
}

$new_item = __get('new_item');

/**
 * One label from the listing form's copy, keyed by name.
 *
 * @param string $return One of 'title', 'subtitle' or 'button'
 *
 * @return string
 */
function customText($return = 'title')
{
    $new_item      = __get('new_item');
    $text          = array();
    $text['title'] = __('Listing');
    if ($new_item) {
        $text['subtitle'] = __('Add listing');
        $text['button']   = __('Add listing');
    } else {
        $text['subtitle'] = __('Edit listing');
        $text['button']   = __('Update listing');
    }

    return $text[$return];
}

osc_admin_page(array(
    'section' => static fn () => customText('title'),
));

/**
 * Filter callback for `admin_title`: prefix the browser title with the form's subtitle.
 *
 * @param string $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf('%s &raquo; %s', customText('subtitle'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

/**
 * Emit the listing form's scripts: the price the site's locale writes, plus the location
 * and photo widgets.
 *
 * @return void
 */
function customHead()
{
    if (osc_locale_thousands_sep() != '' || osc_locale_dec_point() != '') { ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var priceInput = document.getElementById('price');
            if (priceInput) {
                priceInput.addEventListener('blur', function () {
                    var price = priceInput.value;
                    <?php if (osc_locale_thousands_sep()) { ?>
                    while (price.indexOf('<?php echo osc_esc_js(osc_locale_thousands_sep());  ?>') !== -1) {
                        price = price.replace('<?php echo osc_esc_js(osc_locale_thousands_sep());  ?>', '');
                    }
                    <?php } ?>
                    <?php if (osc_locale_dec_point() != '') { ?>
                    var tmp = price.split('<?php echo osc_esc_js(osc_locale_dec_point())?>');
                    if (tmp.length > 2) {
                        price = tmp[0] + '<?php echo osc_esc_js(osc_locale_dec_point())?>' + tmp[1];
                    }
                    <?php } ?>
                    priceInput.value = price;
                });
            }
        });
    </script>
        <?php
    } ?>
    <?php ItemForm::location_javascript_new('admin'); ?>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

$new_item = __get('new_item');
$actions  = __get('actions');

osc_add_filter('render-wrapper', 'render_offset');
/**
 * Filter callback for `render-wrapper`: the CSS class the page wrapper renders with.
 *
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

// What was typed wins over what is stored, so a rejected save comes back with the
// submission still in it. The keys are the posted names, as the form classes read them.
$itemErrors  = __get('editorErrors');
$itemErrors  = is_array($itemErrors) ? $itemErrors : array();
$itemRecord  = $new_item ? null : osc_item();
$itemSession = Session::newInstance()->_getForm();
$itemSession = is_array($itemSession) ? $itemSession : array();
/**
 * One field's value: what was submitted if a rejected save put it there, else what is stored.
 *
 * @param string $name  The posted name
 * @param mixed  $value The stored value
 *
 * @return string
 */
$itemTyped = static function ($name, $value) use ($itemSession) {
    if (array_key_exists($name, $itemSession) && (string)$itemSession[$name] !== '') {
        return (string)$itemSession[$name];
    }

    return $value === null ? '' : (string)$value;
};

$itemLocales = array();
$itemTitles  = array();
$itemBodies  = array();
$sessionTitles = Session::newInstance()->_getForm('title');
$sessionBodies = Session::newInstance()->_getForm('description');
foreach (osc_get_locales() as $itemLocale) {
    $code               = $itemLocale['pk_c_code'];
    $itemLocales[$code] = $itemLocale['s_name'];
    $itemTitles[$code]  = osc_apply_filter(
        'admin_item_title',
        $sessionTitles[$code] ?? $itemRecord['locale'][$code]['s_title'] ?? '',
        $itemRecord,
        $itemLocale
    );
    $itemBodies[$code]  = osc_apply_filter(
        'admin_item_description',
        $sessionBodies[$code] ?? $itemRecord['locale'][$code]['s_description'] ?? '',
        $itemRecord,
        $itemLocale
    );
}

// Photos uploaded before the save and refused by it: they are still in uploads/temp/ and
// still posted, so the grid draws them again. Each name is checked the way the save checks
// it -- a bare file name, staged under this form's own upload token -- because a name from
// the request would otherwise become the src of an <img> and a value the next post carries.
$itemStaged = array();
$posted     = Params::getParam('ajax_photos');
if (is_array($posted) && $posted !== array()) {
    $stagedDir   = osc_content_path() . 'uploads/temp/';
    $stagedStore = ItemTmpUpload::newInstance();
    $stagedToken = osc_upload_token();
    // The same ceiling the save stops at, so the screen shows what would be attached and
    // a long list costs no more lookups here than it does there.
    $stagedRoom  = max(1, (int)osc_max_images_per_item());
    foreach ($posted as $stagedName) {
        if ($stagedRoom-- <= 0) {
            break;
        }
        if (!is_string($stagedName) || $stagedName === '' || basename($stagedName) !== $stagedName) {
            continue;
        }
        if ($stagedStore->belongsToToken($stagedToken, $stagedName) && is_file($stagedDir . $stagedName)) {
            $itemStaged[] = $stagedName;
        }
    }
}

// The chosen category: the record's, or the one a rejected save or a link carried.
$itemCatId = $itemTyped('catId', $itemRecord['fk_i_category_id'] ?? Params::getParam('catId'));

$itemBackUrl = osc_admin_base_url(true) . '?page=items';
$itemViewUrl = $new_item ? '' : osc_item_url();

// The record's state, its facts and the links that change it, all built by the controller:
// the view draws them, it does not decide them.
$itemStatus  = is_array(__get('itemStatus')) ? __get('itemStatus') : array();
$itemFacts   = is_array(__get('itemFacts')) ? __get('itemFacts') : array();
$itemMoves   = is_array(__get('itemActions')) ? __get('itemActions') : array();
$itemUser    = is_array(__get('itemUser')) ? __get('itemUser') : null;

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="adminItemForm">
    <?php
    $headActions = array();
    if ($itemViewUrl !== '') {
        $headActions[] = array(
            'label' => __('View listing'),
            'url'   => $itemViewUrl,
            'icon'  => 'bi-box-arrow-up-right',
            'attrs' => array('target' => '_blank', 'rel' => 'noopener'),
        );
    }
    $headActions[] = array('label' => __('Back to listings'), 'url' => $itemBackUrl, 'variant' => 'dim');
    osc_admin_page_head(customText('subtitle'), $headActions);

    // The surface the browser-side validator writes its refusals into. It is drawn only
    // when the server left it empty: a rejected save fills the same id from inside the
    // form, and two of them would be one id twice.
    if ($itemErrors === array()) {
        echo '<ul id="error_list"></ul>';
    }

    osc_admin_editor_open(array(
        'id'           => 'item-form',
        // The shipped validator finds this form by name, and so do plugins.
        'name'         => 'item',
        'page'         => 'items',
        'action'       => $new_item ? 'post_item' : 'item_edit_post',
        'fields'       => $new_item
            ? array()
            : array('id' => osc_item_id(), 'secret' => osc_item_secret()),
        'upload'       => true,
        'main_id'      => 'left-side',
        'errors'       => $itemErrors,
        'error_labels' => array(
            'title'       => __('Title'),
            'description' => __('Description'),
        ),
        'error_ids'    => array(
            'title'       => osc_admin_field_id(array('name' => 'title')),
            'description' => osc_admin_field_id(array('name' => 'description')),
        ),
    ));

    // The posted names are the ones the save has always read; only what draws them is new.
    osc_admin_field(array(
        'type'           => 'text',
        'name'           => 'title',
        'translate_name' => 'title[%s]',
        'label'          => __('Title'),
        'layout'         => 'stacked',
        'required'       => true,
        'translate'      => true,
        'locales'        => $itemLocales,
        'value'          => $itemTitles,
        'error'          => $itemErrors['title'] ?? '',
        'class'          => 'osc-editor-title',
        'placeholder'    => __('Enter title here'),
    ));

    echo '<div class="osc-editor-cols">';

    osc_admin_category_picker(array(
        'name'     => 'catId',
        'value'    => $itemCatId,
        'label'    => __('Category'),
        'required' => true,
        'error'    => $itemErrors['catId'] ?? '',
        'help'     => __('Changing the category changes which details apply below.'),
    ));

    if (osc_price_enabled_at_items()) {
        $currencies = osc_get_currencies();
        $itemPrice  = $itemTyped('price', $itemRecord['i_price'] ?? '');
        $itemPrice  = $itemPrice === '' ? '' : osc_prepare_price($itemPrice);
        $currency   = $itemTyped(
            'currency',
            $itemRecord['fk_c_currency_code'] ?? Preference::newInstance()->get('currency')
        );
        // The category script hides this whole block for a category that takes no price, by
        // the wrapper the price input sits in -- so the label belongs inside it.
        echo '<div class="osc-field item-price osc-price-field">';
        echo '<label class="form-label" for="price">' . osc_esc_html(__('Price')) . '</label>';
        // The amount and its currency are one field, so they share a row under one label.
        echo '<div class="field-group">';
        osc_admin_field(array(
            'type'        => 'text',
            'id'          => 'price',
            'name'        => 'price',
            'row'         => false,
            'width'       => 'num',
            'class'       => 'field-grow',
            // A rejected save put the price in the session the way the save stores it --
            // an integer in millionths -- so both sources take the same formatting.
            'value'       => $itemPrice,
            'placeholder' => __('Enter price'),
            'error'       => $itemErrors['price'] ?? '',
            'attrs'       => array('autocomplete' => 'off', 'inputmode' => 'decimal'),
        ));
        if (count($currencies) > 1) {
            $currencyOptions = array();
            foreach ($currencies as $one) {
                $currencyOptions[$one['pk_c_code']] = $one['s_description'];
            }
            osc_admin_field(array(
                'type'    => 'select',
                'id'      => 'currency',
                'name'    => 'currency',
                'row'     => false,
                'options' => $currencyOptions,
                'value'   => $currency,
                'attrs'   => array('aria-label' => __('Currency')),
            ));
        } elseif (count($currencies) === 1) {
            osc_admin_field(array(
                'type'  => 'hidden',
                'id'    => 'currency',
                'name'  => 'currency',
                'value' => $currencies[0]['pk_c_code'],
            ));
            echo '<span class="field-suffix">' . osc_esc_html($currencies[0]['s_description']) . '</span>';
        }
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';

    // Neither preset: a listing description wants tables and a colour picker but no
    // embedded image or media, so the pair is passed here rather than earning a preset of
    // its own for one caller.
    osc_admin_field(array(
        'type'           => 'richtext',
        'name'           => 'description',
        'translate_name' => 'description[%s]',
        'label'          => __('Description'),
        'layout'         => 'stacked',
        'required'       => true,
        'translate'      => true,
        // The title's strip above switches this field too; a second strip could disagree with it.
        'tabs'           => false,
        'locales'        => $itemLocales,
        'value'          => $itemBodies,
        'error'          => $itemErrors['description'] ?? '',
        'preset'         => 'basic',
        'height'         => 320,
        'config'         => array(
            'plugins' => 'advlist anchor autolink charmap code fullscreen insertdatetime'
                         . ' link lists preview searchreplace table',
            'toolbar' => 'undo redo | blocks | bold italic underline forecolor | bullist numlist'
                         . ' | link charmap table | removeformat | searchreplace code fullscreen preview',
        ),
    ));

    if (osc_images_enabled_at_items()) {
        osc_admin_photo_grid(array(
            'label'      => __('Photos'),
            'resources'  => $new_item ? array() : osc_get_item_resources(),
            'staged'     => $itemStaged,
            'max'        => (int)osc_max_images_per_item(),
            'max_size'   => (int)osc_max_size_kb() * 1024,
            'upload_url' => osc_base_url(true) . '?page=ajax&action=ajax_upload',
            'delete_url' => osc_base_url(true) . '?page=ajax&action=delete_image',
            'temp_url'   => osc_base_url() . 'oc-content/uploads/temp/',
            'secret'     => $new_item ? '' : osc_item_secret(),
            'cover'      => true,
        ));
    }

    // The category's own fields, fetched after load by the item_form / item_edit hook.
    if ($new_item) {
        ItemForm::plugin_post_item();
    } else {
        ItemForm::plugin_edit_item();
    }

    osc_admin_editor_rail(array('id' => 'right-side'));

    // Status: the state, the dates behind it, the moves that change it, and the expiry.
    $publishRows = array();
    if (!empty($itemFacts['published'])) {
        $publishRows[] = array('label' => __('Published'), 'value' => $itemFacts['published']);
    }
    if (!$new_item) {
        $publishRows[] = array(
            'label' => __('Expires'),
            'html'  => true,
            'value' => osc_esc_html((string)($itemFacts['expires'] ?? __('Never')))
                . ' &middot; <a href="#" data-osc-expiry-toggle aria-expanded="false">'
                . osc_esc_html(__('Change')) . '</a>',
        );
    }
    if (isset($itemFacts['views'])) {
        $publishRows[] = array('label' => __('Views'), 'value' => (string)$itemFacts['views']);
    }

    // The expiry field is the one the save reads: -1 leaves the date where it is, 0 never
    // expires, and a number of days or a date moves it.
    ob_start();
    echo '<div class="osc-publish-expiry" data-osc-expiry' . ($new_item ? '' : ' hidden') . '>';
    osc_admin_field(array(
        'type'        => 'text',
        'id'          => 'dt_expiration',
        'name'        => 'dt_expiration',
        'label'       => $new_item ? __('Expires after') : __('New expiry'),
        'layout'      => 'stacked',
        'value'       => $new_item ? '' : '-1',
        'placeholder' => $new_item ? '30' : 'yyyy-mm-dd HH:mm:ss',
        'help'        => __('Days from the publishing date, or a date like 2027-03-04. 0 never expires.'),
        'attrs'       => array('data-osc-expiry-field' => '1'),
    ));
    if (!$new_item) {
        osc_admin_checkbox(array(
            'name'  => 'update_expiration',
            'id'    => 'update_expiration',
            'value' => '',
            'label' => __('Never expires'),
            'attrs' => array('data-osc-expiry-never' => '1'),
        ));
    }
    echo '</div>';
    $expiryHtml = (string)ob_get_clean();

    osc_admin_publish_panel(array(
        'status'    => $itemStatus !== array()
            ? $itemStatus
            : array(array('inactive', __('Not saved yet'))),
        'rows'      => $publishRows,
        'body_html' => $expiryHtml,
        'actions'   => $itemMoves['routine'] ?? array(),
        'danger'    => $itemMoves['danger'] ?? array(),
    ));

    // A controller that exports no structured moves still has the links it always built.
    if ($itemMoves === array() && is_array($actions) && $actions !== array()) {
        echo '<div id="item-action-list" class="btn-group btn-group-sm">';
        foreach ($actions as $aux) {
            echo $aux;
        }
        echo '</div>';
    }

    osc_admin_panel_open(__('Seller'));

    osc_admin_user_picker(array(
        'user'   => $itemUser,
        'id'     => 'userPicker',
        'label'  => $itemUser === null ? __('Find a registered user') : __('Change the seller'),
        'help'   => __('Picking a user fills the contact fields below. The listing belongs to'
                       . ' whichever account matches the contact e-mail.'),
        'source' => osc_admin_base_url(true) . '?page=ajax&action=userajax',
        'fields' => array('name' => 'contactName', 'email' => 'contactEmail'),
    ));

    osc_admin_field(array(
        'type'   => 'text',
        'id'     => 'contactName',
        'name'   => 'contactName',
        'label'  => __('Name'),
        'layout' => 'stacked',
        'value'  => $itemTyped('contactName', $itemRecord['s_contact_name'] ?? ''),
        'error'  => $itemErrors['contactName'] ?? '',
    ));
    osc_admin_field(array(
        'type'   => 'text',
        'id'     => 'contactEmail',
        'name'   => 'contactEmail',
        'label'  => __('E-mail'),
        'layout' => 'stacked',
        'value'  => $itemTyped('contactEmail', $itemRecord['s_contact_email'] ?? ''),
        'error'  => $itemErrors['contactEmail'] ?? '',
    ));
    osc_admin_field(array(
        'type'   => 'text',
        'id'     => 'contactPhone',
        'name'   => 'contactPhone',
        'label'  => __('Phone'),
        'layout' => 'stacked',
        'value'  => $itemTyped('contactPhone', $itemRecord['s_contact_phone'] ?? ''),
        'error'  => $itemErrors['contactPhone'] ?? '',
    ));

    // A checkbox posts nothing when it is off, so the stored value may only be replaced
    // when a rejected submit actually put the key in the form session.
    $showEmail = array_key_exists('showEmail', $itemSession)
        ? !empty($itemSession['showEmail'])
        : !empty($itemRecord['b_show_email']);
    osc_admin_checkbox(array(
        'name'    => 'showEmail',
        'id'      => 'showEmail',
        'value'   => '1',
        'checked' => $showEmail,
        'label'   => __('Show the e-mail address on the listing'),
    ));

    if (!$new_item) {
        osc_admin_disclosure_open(__('Advanced'), array('summary_hint' => __('IP address')));
        osc_admin_field(array(
            'type'   => 'text',
            'id'     => 'ipAddress',
            'name'   => 'ipAddress',
            'label'  => __('IP address'),
            'layout' => 'stacked',
            'value'  => osc_item_ip(),
            'attrs'  => array('readonly' => true),
        ));
        osc_admin_disclosure_close();
    }

    osc_admin_panel_close();

    osc_admin_panel_open(__('Location'));
    osc_admin_location_picker(array(
        'value' => array(
            'countryId' => $itemTyped('countryId', $itemRecord['fk_c_country_code'] ?? ''),
            'country'   => $itemTyped('country', $itemRecord['s_country'] ?? ''),
            'region'    => $itemTyped('region', $itemRecord['s_region'] ?? ''),
            'regionId'  => $itemTyped('regionId', $itemRecord['fk_i_region_id'] ?? ''),
            'city'      => $itemTyped('city', $itemRecord['s_city'] ?? ''),
            'cityId'    => $itemTyped('cityId', $itemRecord['fk_i_city_id'] ?? ''),
            'cityArea'  => $itemTyped('cityArea', $itemRecord['s_city_area'] ?? ''),
            'zip'       => $itemTyped('zip', $itemRecord['s_zip'] ?? ''),
            'address'   => $itemTyped('address', $itemRecord['s_address'] ?? ''),
        ),
        'errors' => $itemErrors,
    ));
    osc_admin_panel_close();

    osc_admin_editor_close(array(
        array('label' => customText('button'), 'type' => 'submit', 'variant' => 'primary'),
        array('label' => __('Back to listings'), 'url' => $itemBackUrl, 'variant' => 'dim'),
    )); ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
