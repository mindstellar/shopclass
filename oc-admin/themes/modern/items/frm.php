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

osc_enqueue_script('php-date');
osc_enqueue_script('tiny_mce');

/* Not used ?
// cateogry js
$categories = Category::newInstance()->toTree();
*/

$new_item = __get('new_item');
/**
 * @param string $return
 *
 * @return mixed
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

// Expire Select Options
if ($new_item) {
    $options = array(0, 1, 3, 5, 7, 10, 15, 30);
} else {
    $options = array(-1, 0, 1, 3, 5, 7, 10, 15, 30);
}

osc_admin_page(array(
    'section' => static fn () => customText('title'),
));

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf('%s &raquo; %s', customText('subtitle'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('#user, #fUser').forEach(function (el) {
                oscAutocomplete(el, {
                    source: "<?php echo osc_admin_base_url(true); ?>?page=ajax&action=userajax",
                    minLength: 0,
                    onSelect: function (item) {
                        var ci = document.getElementById('contact_info');
                        if (item.id === '') {
                            if (ci) { ci.style.display = ''; }
                            return false;
                        }
                        ['userId', 'fUserId'].forEach(function (id) {
                            var f = document.getElementById(id);
                            if (f) { f.value = item.id; }
                        });
                        if (ci) { ci.style.display = 'none'; }
                    }
                });
            });

            <?php if (osc_locale_thousands_sep() != '' || osc_locale_dec_point() != '') { ?>
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
            <?php } ?>

            var updateExp = document.getElementById('update_expiration');
            if (updateExp) {
                updateExp.addEventListener('change', function () {
                    var dt = document.getElementById('dt_expiration');
                    var rows = document.querySelectorAll('div.update_expiration');
                    if (updateExp.checked) {
                        if (dt) { dt.value = ''; }
                        rows.forEach(function (el) { el.style.display = ''; });
                    } else {
                        if (dt) { dt.value = '-1'; }
                        rows.forEach(function (el) { el.style.display = 'none'; });
                    }
                });
            }
        });
    </script>
    <?php ItemForm::location_javascript_new('admin'); ?>
    <?php if (osc_images_enabled_at_items()) {
        ItemForm::photos_javascript();
    } ?>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

$new_item = __get('new_item');
$actions  = __get('actions');

osc_add_filter('render-wrapper', 'render_offset');
/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="adminItemForm" class="col-xl-10">
    <div class="row ">
        <div class="col">
                <?php osc_admin_page_head(customText('subtitle')); ?>
                <?php if (!$new_item) { ?>
                    <a href="<?php echo osc_item_url(); ?>"><?php _e('View listing on front'); ?><i class="bi
                    bi-arrow-up-right-square ms-1"></i></a>
                <?php } ?>
        </div>
        <div class="col">
                <?php if (!$new_item) { ?>
                    <div id="item-action-list" class="btn-group btn-group-sm float-right">
                        <?php foreach ($actions as $aux) { ?>
                            <?php echo $aux; ?>

                        <?php } ?>
                    </div>
                    <div class="clear"></div>
                <?php } ?>
        </div>
    </div>
    <div class="row">
        <div class="col">
            <ul id="error_list"></ul>
            <div id="item-form">
                <form class="row" action="<?php echo osc_admin_base_url(true); ?>" method="post" enctype="multipart/form-data"
                      name="item">
                    <input type="hidden" name="page" value="items"/>
                    <?php if ($new_item) { ?>
                        <input type="hidden" name="action" value="post_item"/>
                    <?php } else { ?>
                        <input type="hidden" name="action" value="item_edit_post"/>
                        <input type="hidden" name="id" value="<?php echo osc_item_id(); ?>"/>
                        <input type="hidden" name="secret" value="<?php echo osc_item_secret(); ?>"/>
                    <?php } ?>
                    <ul id="error_list"></ul>
                    <div id="left-side" class="col">
                        <?php \mindstellar\form\admin\Item::instance()->printMultiLangTab(); ?>
                        <div class="category mb-3">
                            <label><?php _e('Category'); ?> *</label>
                            <?php ItemForm::category_multiple_selects(); ?>
                        </div>
                        <?php \mindstellar\form\admin\Item::instance()->printMultiLangTitleDesc(null, false); ?>
                        <?php \mindstellar\form\admin\Item::instance()->itemPrice(); ?>
                        <?php if (osc_images_enabled_at_items()) { ?>
                            <div class="photo_container">
                                <label><?php _e('Photos'); ?></label>
                                <?php ItemForm::photos(); ?>
                                <div id="photos">
                                    <?php if (osc_max_images_per_item() == 0
                                              || (osc_max_images_per_item() != 0
                                                  && osc_count_item_resources() < osc_max_images_per_item())
                                    ) { ?>
                                        <div>
                                            <input type="file" name="photos[]"/> (<?php _e('optional'); ?>)
                                        </div>
                                    <?php } ?>
                                </div>
                                <p>
                                    <a href="#" class="add-photo-btn" title="<?php echo osc_esc_html(__('Add new photo')); ?>"
                                       aria-label="<?php echo osc_esc_html(__('Add new photo')); ?>" onclick="addNewPhoto(); return false;">
                                        <i class="h4 bi bi-plus-circle-fill"></i>
                                    </a>
                                </p>
                            </div>
                        <?php } ?>
                        <?php if ($new_item) {
                            ItemForm::plugin_post_item();
                        } else {
                            ItemForm::plugin_edit_item();
                        }
?>
                    </div>
                    <div id="right-side" class="col-xl-4 col-lg-4">
                        <div class="card mb-3">
                            <div id="contact_info" class="card-body">
                                <h3 class="label"><?php _e('User'); ?></h3>
                                <div>
                                    <label><?php _e('Name'); ?></label>
                                    <?php ItemForm::contact_name_text(); ?>
                                </div>
                                <div>
                                    <label><?php _e('E-mail'); ?></label>
                                    <?php ItemForm::contact_email_text(); ?>
                                </div>
                                <div>
                                    <label><?php _e('Phone'); ?></label>
                                    <?php ItemForm::contact_phone_text(); ?>
                                </div>
                                <?php if (!$new_item) { ?>
                                    <div>
                                        <label><?php _e('Ip Address'); ?></label>
                                        <input id="ipAddress" type="text" name="ipAddress"
                                               value="<?php echo osc_item_ip(); ?>"
                                               class="form-control form-control-sm valid"
                                               readonly="readonly">
                                    </div>
                                <?php } ?>
                                <div>
                                    <label><?php ItemForm::show_email_checkbox(); ?><?php _e('Show e-mail'); ?></label>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h3 class="label"><?php _e('Location'); ?></h3>
                                <div>
                                    <label><?php _e('Country'); ?></label>
                                    <?php ItemForm::country_select(); ?>
                                </div>
                                <div>
                                    <label><?php _e('Region'); ?></label>
                                    <?php ItemForm::region_text(); ?>
                                </div>
                                <div>
                                    <label><?php _e('City'); ?></label>
                                    <?php ItemForm::city_text(); ?>
                                </div>
                                <div>
                                    <label><?php _e('City area'); ?></label>
                                    <?php ItemForm::city_area_text(); ?>
                                </div>
                                <div>
                                    <label><?php _e('Zip code'); ?></label>
                                    <?php ItemForm::zip_text(); ?>
                                </div>
                                <div>
                                    <label><?php _e('Address'); ?></label>
                                    <?php ItemForm::address_text(); ?>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h3 class="label"><?php _e('Expiration'); ?></h3>
                                <?php if ($new_item) { ?>
                                    <div>
                                        <?php ItemForm::expiration_input('add'); ?>
                                    </div>
                                    <label><?php _e('It could be an integer (days from original publishing date it will '
                            . 'be expired, 0 to never expire) or a date in the format "yyyy-mm-dd hh:mm:ss"'); ?></label>
                                <?php } elseif (!$new_item) { ?>
                                    <div>
                                        <label><input type="checkbox" id="update_expiration" name="update_expiration"
                                                      style="width: inherit!important;"/> <?php _e('Update expiration?'); ?>
                                        </label>
                                        <div class="hide update_expiration">
                                            <div class="input-separate-top">
                                                <?php ItemForm::expiration_input('edit'); ?>
                                            </div>
                                            <label><?php _e('It could be an integer (days from original publishing date '
                                    . 'it will be expired, 0 to never expire) or a date in the format '
                                    . '"yyyy-mm-dd hh:mm:ss"'); ?></label>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                    <?php
                    $formActions = array();
                    if (!$new_item) {
                        $formActions[] = array('label' => __('Cancel'), 'url' => 'javascript:history.go(-1)', 'variant' => 'dim');
                    }
                    $formActions[] = array('label' => customText('button'), 'type' => 'submit', 'variant' => 'primary');
                    osc_admin_form_actions($formActions);
                    ?>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    // This block used to call tinyMCE.init() inline, before the enqueued tinymce
    // bundle had executed, which threw "tinyMCE is not defined" and left bare
    // textareas. Wait for DOM ready (the library has loaded by then) and guard,
    // the same way the page and email editors do. The old config also carried
    // TinyMCE 3-era options (theme_advanced_*, forecolorpicker, fontsizeselect,
    // the merged-in paste plugin) that are inert in TinyMCE 7 — replaced with the
    // valid equivalents.
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof tinymce === 'undefined') {
            return;
        }
        // Neither preset: a listing description wants tables and a colour picker but no
        // embedded image or media, so the pair is passed here rather than earning a
        // preset of its own for one caller. The selector takes only the per-locale
        // description editors (name="description[<locale>]"), never plugin textareas
        // elsewhere on the form.
        var cfg = <?php echo osc_tinymce_config('basic', array(
            'selector' => 'textarea[name^="description["]',
            'height'   => 320,
            'plugins'  => 'advlist anchor autolink charmap code fullscreen insertdatetime'
                          . ' link lists preview searchreplace table',
            'toolbar'  => 'undo redo | blocks | bold italic underline forecolor | bullist numlist'
                          . ' | link charmap table | removeformat | searchreplace code fullscreen preview',
        )); ?>;
        if (window.oscTinymceTheme) { Object.assign(cfg, window.oscTinymceTheme()); }
        tinymce.init(cfg);
    });
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
