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

$maxPHPsize    = View::newInstance()->_get('max_size_upload');
$imagickLoaded = extension_loaded('imagick');
$aGD           = @gd_info();
$freeType      = array_key_exists('FreeType Support', $aGD);

//customize Head
$media_js = static function () {
    ?>
    <script type="text/javascript">
        // Code for form validation. Dimension fields must match NxN. Wrapped in
        // DOMContentLoaded so it works when ui-osc.js (oscValidateForm) is deferred.
        document.addEventListener('DOMContentLoaded', function () {
        oscValidateForm(document.querySelector('form[name=media_form]'), {
            rules: {
                dimThumbnail: { required: true, pattern: /^[0-9]+x[0-9]+$/i },
                dimPreview: { required: true, pattern: /^[0-9]+x[0-9]+$/i },
                dimNormal: { required: true, pattern: /^[0-9]+x[0-9]+$/i },
                maxSizeKb: { required: true, digits: true }
            },
            messages: {
                dimThumbnail: {
                    required: '<?php echo osc_esc_js(__('Thumbnail size: this field is required')); ?>',
                    pattern: '<?php echo osc_esc_js(__('Thumbnail size: is not in the correct format')); ?>'
                },
                dimPreview: {
                    required: '<?php echo osc_esc_js(__('Preview size: this field is required')); ?>',
                    pattern: '<?php echo osc_esc_js(__('Preview size: is not in the correct format')); ?>'
                },
                dimNormal: {
                    required: '<?php echo osc_esc_js(__('Normal size: this field is required')); ?>',
                    pattern: '<?php echo osc_esc_js(__('Normal size: is not in the correct format')); ?>'
                },
                maxSizeKb: {
                    required: '<?php echo osc_esc_js(__('Maximum size: this field is required')); ?>',
                    digits: '<?php echo osc_esc_js(__('Maximum size: this field must only contain numeric characters')); ?>'
                }
            },
            errorContainer: '#error_list',
            onInvalid: function () {
                var h1 = document.querySelector('h1');
                if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
        });

        document.querySelector('#watermark_none').addEventListener('change', function () {
            if (this.checked) {
                document.querySelector('#watermark_text_box').style.display = "none";
                document.querySelector('#watermark_image_box').style.display = "none";
            }
        });

        function watermarkModal() {
            document.getElementById('dialog-watermark-warning').showModal();
            return false;
        }

        document.querySelector('#watermark_text').addEventListener('change', function () {
            if (this.checked) {
                document.querySelector('#watermark_text_box').style.display = "block";
                document.querySelector('#watermark_image_box').style.display = "none";
                if (!document.querySelector('input[name="keep_original_image"]').checked) {
                    watermarkModal();
                }
            }
        });

        document.querySelector('#watermark_image').addEventListener('change', function () {
            if (this.checked) {
                document.querySelector('#watermark_text_box').style.display = "none";
                document.querySelector('#watermark_image_box').style.display = "block";
                if (!document.querySelector('input[name="keep_original_image"]').checked) {
                    watermarkModal();
                }
            }
        });

        document.querySelector('input[name="keep_original_image"]').addEventListener("change", function () {
            if (!this.checked) {
                if (!document.querySelector('#watermark_none').checked) {
                    watermarkModal();
                }
            }
        });
        });
    </script>
    <?php
};

osc_add_hook('admin_footer', $media_js, 10);

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


function addHelp()
{
    echo '<p>'
         . __('Manage the options for the images users can upload along with their listings. You can limit their size, '
              . 'the number of images per ad, include a watermark, etc.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Media Settings &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
    <!--the inputs have a class for the size ...-->
    <div id="general-settings">
        <h2 class="render-title"><?php _e('Media Settings'); ?></h2>
        <ul id="error_list"></ul>
        <form name="media_form" action="<?php echo osc_admin_base_url(true); ?>" method="post"
              enctype="multipart/form-data">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="media_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <h2 class="render-title"><?php _e('Image sizes'); ?></h2>
                    <div class="form-row">
                        <p><?php _e('The sizes listed below determine the maximum dimensions in pixels to use when uploading a image.'
                                    . ' Format: <b>Width</b> x <b>Height</b>.'); ?>
                        </p>
                        <div class="form-label"><?php _e('Thumbnail size'); ?></div>
                        <div class="form-controls"><input type="text" class="input-medium" name="dimThumbnail"
                                                          value="<?php echo osc_esc_html(osc_thumbnail_dimensions()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Preview size'); ?></div>
                        <div class="form-controls"><input type="text" class="input-medium" name="dimPreview"
                                                          value="<?php echo osc_esc_html(osc_preview_dimensions()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Normal size'); ?></div>
                        <div class="form-controls"><input type="text" class="input-medium" name="dimNormal"
                                                          value="<?php echo osc_esc_html(osc_normal_dimensions()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Original size'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="checkbox" id="keep_original_image" name="keep_original_image"
                                       value="1" <?php echo(osc_keep_original_image() ? 'checked="checked"' : ''); ?> />
                                <label for="keep_original_image"><?php _e('Keep original image, unaltered after uploading.'); ?></label>
                                <span class="help-box"><?php _e('Image may occupy more space than usual.'); ?></span>
                            </div>
                        </div>
                    </div>
                    <h2 class="render-title"><?php _e('Restrictions'); ?></h2>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Force JPEG'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="checkbox" id="force_jpeg" name="force_jpeg"
                                       value="1" <?php echo(osc_force_jpeg() ? 'checked="checked"' : ''); ?> />
                                <label for="force_jpeg"><?php _e('Force JPEG extension.'); ?></label>
                                <span class="help-box"><?php _e('Uploaded images will be saved in JPG/JPEG format, '
                                                                . 'it saves space but images will not have transparent background.'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Force aspect'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="checkbox" id="force_aspect_image" name="force_aspect_image"
                                       value="1" <?php echo(osc_force_aspect_image() ? 'checked="checked"' : ''); ?> />
                                <label for="force_aspect_image"><?php _e('Force image aspect.'); ?></label>
                                <span class="help-box"><?php _e('No white background will be added to keep the size.'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Maximum size'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-medium" name="maxSizeKb"
                                   value="<?php echo osc_esc_html(osc_max_size_kb()); ?>"/>
                            <span class="help-box"><?php _e('Size in KB'); ?></span>
                            <div class="callout-warning">
                               <?php printf(__('Maximum size PHP configuration allows: %d KB'), $maxPHPsize); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('ImageMagick'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="checkbox" name="use_imagick" value="1" <?php
                                echo(($imagickLoaded && osc_use_imagick()) ? 'checked="checked"' : '');
                                if (!$imagickLoaded) {
                                    echo 'disabled="disabled"';
                                } ?> />
                                <label for="use_imagick"><?php _e('Use ImageMagick instead of GD library'); ?></label>
                            </div>
                            <?php if (!$imagickLoaded) { ?>
                                <div class="callout-danger">
                                    <p><?php _e('ImageMagick library is not loaded'); ?></p>
                                </div>
                            <?php } ?>
                            <div class="help-box">
                                <?php _e("It's faster and consumes less resources than GD library."); ?>
                            </div>
                        </div>
                    </div>
                    <h2 class="render-title"><?php _e('Watermark'); ?></h2>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Watermark type'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="radio" id="watermark_none" name="watermark_type"
                                       value="none" <?php echo((!osc_is_watermark_image() && !osc_is_watermark_text())
                                        ? 'checked="checked"' : ''); ?> />
                                <label for="watermark_none"><?php _e('None'); ?></label>
                            </div>
                            <div class="form-label-checkbox">
                                <input type="radio" id="watermark_text" name="watermark_type"
                                       value="text" <?php echo(osc_is_watermark_text() ? 'checked="checked"'
                                        : ''); ?> <?php echo($freeType ? '' : 'disabled="disabled"'); ?> />
                                <label for="watermark_text"><?php _e('Text'); ?></label>
                                <?php if (!$freeType) { ?>
                                    <div class="callout-danger">
                                        <p><?php printf(
                                                __('Freetype library is required. How to <a target="_blank" href="%s">install/configure</a>'),
                                                'https://www.php.net/manual/en/image.installation.php'
                                            ); ?></p>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="form-label-checkbox">
                                <input type="radio" id="watermark_image" name="watermark_type"
                                       value="image" <?php echo(osc_is_watermark_image() ? 'checked="checked"' : ''); ?> />
                                <label for="watermark_image"><?php _e('Image'); ?></label>
                            </div>
                        </div>
                    </div>
                    <div id="watermark_text_box" class="table-backoffice-form" <?php echo(osc_is_watermark_text() ? ''
                        : 'style="display:none;"'); ?>>
                        <h2 class="render-title"><?php _e('Watermark Text Settings'); ?></h2>
                        <div class="form-row">
                            <div class="form-label"><?php _e('Watermark Text'); ?></div>
                            <div class="form-controls">
                                <input type="text" class="input-large" name="watermark_text"
                                       value="<?php echo osc_esc_html(osc_watermark_text()); ?>"/>
                            </div>
                        </div>
                        <?php
                        if (Preference::newInstance()->get('watermark_text_options')) {
                            $watermark_options = json_decode(
                                Preference::newInstance()->get('watermark_text_options'),
                                true
                            );
                            if (isset($watermark_options['watermark_width']) && $watermark_options['watermark_width']) {
                                $watermark_width = (int)$watermark_options['watermark_width'];
                            } else {
                                $watermark_width = 200;
                            }
                            if (isset($watermark_options['watermark_height']) && $watermark_options['watermark_height']) {
                                $watermark_height = (int)$watermark_options['watermark_height'];
                            } else {
                                $watermark_height = 30;
                            }
                            if (isset($watermark_options['text_offset_x']) && $watermark_options['text_offset_x']) {
                                $text_offset_x = (int)$watermark_options['text_offset_x'];
                            } else {
                                $text_offset_x = 0;
                            }
                            if (isset($watermark_options['text_offset_y']) && $watermark_options['text_offset_y']) {
                                $text_offset_y = (int)$watermark_options['text_offset_y'];
                            } else {
                                $text_offset_y = $watermark_height;
                            }
                            if (isset($watermark_options['text_angle']) && $watermark_options['text_angle']) {
                                $text_angle = (int)$watermark_options['text_angle'];
                            } else {
                                $text_angle = 0;
                            }
                            if (isset($watermark_options['background_color']) && $watermark_options['background_color']) {
                                $background_color = $watermark_options['background_color'];
                            } else {
                                $background_color = '#000000';
                            }

                            ?>
                            <div class="form-row">
                                <div class="form-label"><?php _e('Watermark Width'); ?></div>
                                <div class="form-controls">
                                    <input type="number" class="input-large" name="watermark_width" step="1"
                                           value="<?php echo $watermark_width; ?>"/>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label"><?php _e('Watermark Height'); ?></div>
                                <div class="form-controls">
                                    <input type="number" class="input-large" name="watermark_height" step="1"
                                           value="<?php echo $watermark_height; ?>"/>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label"><?php _e('Text offset_x'); ?></div>
                                <div class="form-controls">
                                    <input type="number" class="input-large" name="text_offset_x" step="1"
                                           value="<?php echo $text_offset_x; ?>"/>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label"><?php _e('Text offset_y'); ?></div>
                                <div class="form-controls">
                                    <input type="number" class="input-large" name="text_offset_y" step="1"
                                           value="<?php echo $text_offset_y; ?>"/>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label"><?php _e('Text Color'); ?></div>
                                <div class="form-controls">
                                    <input type="color" maxlength="8" id="colorpickerField1" class="form-control form-control-color"
                                           name="watermark_text_color"
                                           value="<?php echo osc_esc_html(osc_watermark_text_color()); ?>"/>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-label"><?php _e('Background Color'); ?></div>
                                <div class="form-controls">
                                    <input type="color" maxlength="8" id="colorpickerField2" class="form-control form-control-color"
                                           name="background_color"
                                           value="<?php echo $background_color;
                                            ?>"/>
                                    <div class="help-box">
                                        <?php _e('Background Hexadecimal color value'); ?>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                        <?php if (osc_is_watermark_text() && osc_watermark_text_color()) { ?>
                            <div class="form-row">
                                <div class="form-label"><?php _e('Preview Watermark'); ?></div>
                                <div class="form-controls">
                                    <div class="help-box">
                                        <?php if (!file_exists(Preference::newInstance()->get('watermark_text_options'))) {
                                            ImageProcessing::createWatermarkImageFromText(
                                                osc_watermark_text(),
                                                osc_watermark_text_color()
                                            );
                                        }
                                        ?>
                                        <img src="<?php
                                        echo osc_base_url()
                                             . str_replace(osc_base_path(), '', osc_uploads_path())
                                             . Preference::newInstance()->get('watermark_text_image_name') ?>"/>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                        <div class="form-row">
                            <div class="form-label"><?php _e('Position'); ?></div>
                            <div class="form-controls">
                                <select name="watermark_text_place" id="watermark_text_place">
                                    <option value="centre" <?php echo (osc_watermark_place() === 'centre')
                                        ? 'selected="true"' : ''; ?>><?php _e('Centre'); ?></option>
                                    <option value="tl" <?php echo (osc_watermark_place() === 'tl') ? 'selected="true"'
                                        : ''; ?>><?php _e('Top Left'); ?></option>
                                    <option value="tr" <?php echo (osc_watermark_place() === 'tr') ? 'selected="true"'
                                        : ''; ?>><?php _e('Top Right'); ?></option>
                                    <option value="bl" <?php echo (osc_watermark_place() === 'bl') ? 'selected="true"'
                                        : ''; ?>><?php _e('Bottom Left'); ?></option>
                                    <option value="br" <?php echo (osc_watermark_place() === 'br') ? 'selected="true"'
                                        : ''; ?>><?php _e('Bottom Right'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="watermark_image_box" <?php echo(osc_is_watermark_image() ? '' : 'style="display:none;"'); ?>>
                        <h2 class="render-title"><?php _e('Watermark Image Settings'); ?></h2>
                        <div class="form-row">
                            <div class="form-label"><?php _e('Image'); ?></div>
                            <div class="form-controls">
                                <input type="file" name="watermark_image" id="watermark_image_file"/>
                                <?php if (osc_is_watermark_image()) { ?>
                                    <div class="help-box"><img width="100px"
                                                               src="<?php echo osc_base_url() . str_replace(
                                                                       osc_base_path(),
                                                                       '',
                                                                       osc_uploads_path()
                                                                    ) . 'watermark.png' ?>"/></div>
                                <?php } ?>
                                <div class="help-box"><?php _e('It has to be a .PNG image'); ?></div>
                                <div class="help-box"><?php _e("Shopclass doesn't check the watermark image size"); ?></div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-label"><?php _e('Position'); ?></div>
                            <div class="form-controls">
                                <select name="watermark_image_place" id="watermark_image_place">
                                    <option value="centre" <?php echo (osc_watermark_place() === 'centre')
                                        ? 'selected="true"' : ''; ?>><?php _e('Centre'); ?></option>
                                    <option value="tl" <?php echo (osc_watermark_place() === 'tl') ? 'selected="true"'
                                        : ''; ?>><?php _e('Top Left'); ?></option>
                                    <option value="tr" <?php echo (osc_watermark_place() === 'tr') ? 'selected="true"'
                                        : ''; ?>><?php _e('Top Right'); ?></option>
                                    <option value="bl" <?php echo (osc_watermark_place() === 'bl') ? 'selected="true"'
                                        : ''; ?>><?php _e('Bottom Left'); ?></option>
                                    <option value="br" <?php echo (osc_watermark_place() === 'br') ? 'selected="true"'
                                        : ''; ?>><?php _e('Bottom Right'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <h2 class="render-title"><?php _e('Regenerate images'); ?></h2>
                    <div class="form-row">
                        <div class="form-controls">
                            <p>
                                <?php _e('You can regenerate different image dimensions. If you have changed the dimension of thumbnails, '
                                         . 'preview or normal images, you might want to regenerate your images.'); ?>
                            </p>
                            <a class="btn btn-dim"
                               href="<?php echo osc_admin_base_url(true) . '?page=settings&action=images_post' . '&'
                                                . osc_csrf_token_url(); ?>"><?php _e('Regenerate'); ?></a>
                        </div>
                    </div>
                    <div class="clear"></div>
                    <div class="form-actions">
                        <input type="submit" id="save_changes" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                               class="btn btn-submit"/>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <dialog id="dialog-watermark-warning" class="osc-dialog">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><?php echo osc_esc_html(__('Recommendation')); ?></p>
            <p class="osc-dialog-text"><?php _e("We highly recommend you have the 'Keep original image' option active when you use watermarks."); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
        </div>
    </dialog>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>