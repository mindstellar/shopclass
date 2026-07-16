<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

osc_enqueue_script('jquery-validate-additional');
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

function customPageHeader()
{
    ?>
    <h1><?php echo customText('title'); ?></h1>
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
    return sprintf('%s &raquo; %s', customText('subtitle'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        $(document).ready(function () {
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
            $("#price").on("blur", function (event) {
                var price = $("#price").prop("value");
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
                $("#price").prop("value", price);

            });
            <?php } ?>

            $('#update_expiration').change(function () {
                if ($(this).attr("checked")) {
                    $('#dt_expiration').prop('value', '');
                    $('div.update_expiration').show();
                } else {
                    $('#dt_expiration').prop('value', '-1');
                    $('div.update_expiration').hide();
                }
            });
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
                <h2 class="render-title"><?php echo customText('subtitle'); ?></h2>
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
                    <div class="form-actions">
                        <?php if (!$new_item) { ?>
                            <a href="javascript:history.go(-1)" class="btn btn-dim"><?php _e('Cancel'); ?></a>
                        <?php } ?>
                        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(customText('button')); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    tinyMCE.init({
        selector: 'textarea',
        promotion: false,
        theme_advanced_toolbar_align: 'left',
        theme_advanced_toolbar_location: 'top',
        theme_advanced_buttons1_add: 'forecolorpicker,fontsizeselect',
        plugins: 'advlist anchor autolink charmap code fullscreen insertdatetime link lists paste preview searchreplace table',
    });
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
