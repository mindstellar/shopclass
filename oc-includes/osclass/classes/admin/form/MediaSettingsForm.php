<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\admin\ui\FormSpec;
use Params;

/**
 * The media screen: image sizes, upload restrictions and the watermark.
 *
 * The watermark type is not stored. It is read back from which of watermark_text and
 * watermark_image holds something, and it is the master both watermark blocks depend on.
 * What a type clears, the text options JSON and the uploaded PNG are the page's after_save.
 * A PNG that is not one is refused in validation, so a bad file writes nothing at all.
 *
 * @package mindstellar\admin\form
 */
final class MediaSettingsForm
{
    public const PAGE_ID = 'core.settings_media';

    /** The radio both watermark blocks hang off. */
    public const TYPE = 'watermark_type';

    /** The file control, named as the screen has always named it. */
    public const UPLOAD = 'watermark_image';

    /** What jpeg_quality holds when nothing between 1 and 100 was given. */
    public const DEFAULT_JPEG_QUALITY = 82;

    /** Width x height, lower-cased, as every image-size reader parses it. */
    private const DIMENSION = '/^[0-9]+x[0-9]+$/';

    /** The text options JSON, each with what a missing or zero entry reads as. */
    private const TEXT_OPTIONS = array(
        'watermark_width'  => 200,
        'watermark_height' => 30,
        'text_offset_x'    => 0,
        'text_offset_y'    => null,
        'text_angle'       => 0,
        'background_color' => '#000000',
    );

    /** @var int|null the PHP limit maxSizeKb was lowered to on the last save, if it was */
    private static ?int $lowered = null;

    /** @var bool whether the last save had a PNG it could not put in place */
    private static bool $uploadFailed = false;

    /**
     * Declare the form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $imagick  = extension_loaded('imagick');
        $gd       = function_exists('gd_info') ? gd_info() : array();
        $freeType = array_key_exists('FreeType Support', $gd);
        $places   = array(
            'centre' => __('Centre'),
            'tl'     => __('Top Left'),
            'tr'     => __('Top Right'),
            'bl'     => __('Bottom Left'),
            'br'     => __('Bottom Right'),
        );

        $form = CoreSettings::page(self::PAGE_ID, __('Media Settings'))
            ->onValidate(static function (array $values) {
                return ($values[self::TYPE] ?? '') === 'image' ? self::uploadError() : null;
            })
            ->onAfterSave(static function (array $values) {
                self::applyWatermark($values);
            })
            ->group(__('Image sizes'))
            ->custom('sizes_intro', static function () {
                echo '<p class="form-intro">';
                _e('The sizes listed below determine the maximum dimensions in pixels to use when uploading a image.'
                   . ' Format: <b>Width</b> x <b>Height</b>.');
                echo '</p>';
            })
                ->set('row', false);

        self::dimension($form, 'dimThumbnail', __('Thumbnail size'));
        self::dimension($form, 'dimPreview', __('Preview size'));
        self::dimension($form, 'dimNormal', __('Normal size'));

        $form
            ->checkbox('keep_original_image', __('Keep original image, unaltered after uploading.'), __('Image may occupy more space than usual.'))
                ->rowLabel(__('Original size'))
                ->set('id', 'keep_original_image')
            ->group(__('Restrictions'))
            ->checkbox(
                'force_jpeg',
                __('Force JPEG extension.'),
                __('Uploaded images will be saved in JPG/JPEG format, '
                   . 'it saves space but images will not have transparent background.')
            )
                ->rowLabel(__('Force JPEG'))
                ->set('id', 'force_jpeg')
            ->number(
                'jpeg_quality',
                __('JPEG quality'),
                __('Compression quality for saved JPEGs, from 1 (smallest file) to '
                   . '100 (best quality). 82 is a good balance.')
            )
                ->set('min', 1)
                ->set('max', 100)
                ->default(self::DEFAULT_JPEG_QUALITY)
                // Corrected rather than refused, as the screen always did.
                ->sanitize(static function ($value) {
                    return self::jpegQuality($value);
                })
            ->checkbox('force_aspect_image', __('Force image aspect.'), __('No white background will be added to keep the size.'))
                ->rowLabel(__('Force aspect'))
                ->set('id', 'force_aspect_image')
            ->number('maxSizeKb', __('Maximum size'))
                ->required()
                ->set('min', 1)
                ->suffix(__('KB'))
                ->set('help_html', self::sizeHelp(self::uploadLimitKb()))
                ->sanitize(static function ($value) {
                    return self::maxSize($value);
                })
                ->validate(static function ($value, array $field) {
                    return is_int($value) ? null : sprintf(__('%s must be a whole number'), $field['label']);
                })
            ->checkbox('use_imagick', __('Use ImageMagick instead of GD library'))
                ->rowLabel(__('ImageMagick'))
                ->set('id', 'use_imagick')
                ->disabled(!$imagick)
                ->set(
                    'help_html',
                    ($imagick
                        ? ''
                        : '<span class="callout-danger">' . osc_esc_html(__('ImageMagick library is not loaded')) . '</span> ')
                    . osc_esc_html(__("It's faster and consumes less resources than GD library."))
                )
                // A checkbox ignores a sanitiser, so the switch is held off here instead: a
                // library that is not loaded cannot be the one images are made with.
                ->persist(static function ($value) {
                    return $value && extension_loaded('imagick') ? '1' : '0';
                })
            ->group(__('Watermark'))
            ->radio(self::TYPE, __('Watermark type'), array(
                'none'  => array('label' => __('None'), 'id' => 'watermark_none'),
                'text'  => array(
                    'label'       => __('Text'),
                    'id'          => 'watermark_text',
                    'disabled'    => !$freeType,
                    'custom_html' => $freeType ? '' : '<span class="callout-danger">' . sprintf(
                        __('Freetype library is required. How to <a target="_blank" rel="noopener" href="%s">install/configure</a>'),
                        'https://www.php.net/manual/en/image.installation.php'
                    ) . '</span>',
                ),
                'image' => array('label' => __('Image'), 'id' => 'watermark_image'),
            ))
                ->persist(false);

        self::boxOpen($form, 'text', 'watermark_text_box', __('Watermark Text Settings'), 'table-backoffice-form');
        $form
            ->text('watermark_text', __('Watermark Text'))
                ->dependsOn(self::TYPE, 'text');
        self::textOption($form, 'watermark_width', __('Watermark Width'));
        self::textOption($form, 'watermark_height', __('Watermark Height'));
        self::textOption($form, 'text_offset_x', __('Text offset_x'));
        self::textOption($form, 'text_offset_y', __('Text offset_y'));
        $form
            // Nothing on screen sets the angle, so the stored one is carried through a save
            // rather than reset to zero.
            ->hidden('text_angle', __('Text angle'))
                ->dependsOn(self::TYPE, 'text')
                ->persist(false)
                ->sanitize(static function ($value) {
                    return (int)$value;
                })
            ->color('watermark_text_color', __('Text Color'))
                ->set('id', 'colorpickerField1')
                ->dependsOn(self::TYPE, 'text')
            ->color('background_color', __('Background Color'), __('Background Hexadecimal color value'))
                ->set('id', 'colorpickerField2')
                ->dependsOn(self::TYPE, 'text')
                ->persist(false)
            ->custom('watermark_preview', static function () {
                self::preview();
            })
                ->set('row', false)
            ->select('watermark_text_place', __('Position'), $places)
                ->set('id', 'watermark_text_place')
                ->column('watermark_place')
                ->dependsOn(self::TYPE, 'text');
        self::boxClose($form, 'text');

        self::boxOpen($form, 'image', 'watermark_image_box', __('Watermark Image Settings'));
        $form
            ->custom('watermark_image_file', static function () {
                osc_admin_field(array(
                    'type'      => 'file',
                    'id'        => 'watermark_image_file',
                    'name'      => self::UPLOAD,
                    'label'     => __('Image'),
                    'attrs'     => array('accept' => 'image/png'),
                    'help_html' => (osc_is_watermark_image()
                        ? '<img width="100" alt="" src="' . osc_esc_html(
                            osc_base_url() . str_replace(osc_base_path(), '', osc_uploads_path()) . 'watermark.png'
                        ) . '"><br>'
                        : '')
                        . osc_esc_html(__('It has to be a .PNG image')) . '<br>'
                        . osc_esc_html(__("Shopclass doesn't check the watermark image size")),
                ));
            })
                ->set('row', false)
            ->select('watermark_image_place', __('Position'), $places)
                ->set('id', 'watermark_image_place')
                ->column('watermark_place')
                ->dependsOn(self::TYPE, 'image');
        self::boxClose($form, 'image');

        $form
            ->custom('watermark_clear', static function () {
                echo '<div class="clear"></div>';
            })
                ->set('row', false)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * What the view needs to draw the form.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back, or null
     *                                         for the stored ones
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars(?array $values = null): array
    {
        $pageId = self::register();

        $stored = osc_settings_values($pageId);
        $stored[self::TYPE] = osc_is_watermark_image() ? 'image' : (osc_is_watermark_text() ? 'text' : 'none');
        $stored = self::textOptions() + $stored;

        $quality = (int)$stored['jpeg_quality'];
        $stored['jpeg_quality'] = $quality < 1 || $quality > 100 ? self::DEFAULT_JPEG_QUALITY : $quality;
        $stored['use_imagick']  = extension_loaded('imagick') && !empty($stored['use_imagick']);

        // A refused save posts no value for a block whose type was not chosen; the stored one
        // is what that block shows if the admin switches back to it.
        return CoreSettings::vars(
            $pageId,
            'media_post',
            $values === null ? $stored : $values + $stored,
            array('name' => 'media_form', 'upload' => true)
        );
    }

    /**
     * The smallest upload PHP will take -- upload_max_filesize, post_max_size and
     * memory_limit -- in kilobytes. A zero or negative setting is no limit.
     *
     * @return int
     */
    public static function uploadLimitKb(): int
    {
        $limits = array();
        foreach (array('upload_max_filesize', 'post_max_size', 'memory_limit') as $setting) {
            $kb = self::sizeToKb((string)ini_get($setting));
            if ($kb > 0) {
                $limits[] = $kb;
            }
        }

        return $limits === array() ? PHP_INT_MAX : min($limits);
    }

    /**
     * A php.ini-style size ("8M", "1G", "512K") in kilobytes. A bare number is bytes, as
     * php.ini reads it.
     *
     * @param string $size
     *
     * @return int
     */
    public static function sizeToKb(string $size): int
    {
        $size   = trim($size);
        $suffix = strtoupper(substr($size, -1));
        $powers = array('K' => 0, 'M' => 1, 'G' => 2, 'T' => 3, 'P' => 4);
        if (!isset($powers[$suffix])) {
            return intdiv((int)$size, 1024);
        }

        return (int)((int)substr($size, 0, -1) * (1024 ** $powers[$suffix]));
    }

    /**
     * The callout under the maximum size naming PHP's limit, or nothing when PHP sets none.
     *
     * @param int $limitKb an uploadLimitKb() answer
     *
     * @return string
     */
    public static function sizeHelp(int $limitKb): string
    {
        if ($limitKb === PHP_INT_MAX) {
            return '';
        }

        return '<span class="callout-warning">'
               . osc_esc_html(sprintf(__('Maximum size PHP configuration allows: %d KB'), $limitKb))
               . '</span>';
    }

    /**
     * The PHP limit the last save lowered maxSizeKb to, or null when it did not.
     *
     * @return int|null
     */
    public static function lowered(): ?int
    {
        return self::$lowered;
    }

    /**
     * Whether the last save had a valid PNG that could not be moved into place.
     *
     * @return bool
     */
    public static function uploadFailed(): bool
    {
        return self::$uploadFailed;
    }

    /**
     * An image size box: lower-cased and stripped, and refused unless it is width x height.
     * The browser has always refused the same shape.
     *
     * @param FormSpec $form
     * @param string   $name
     * @param string   $label
     *
     * @return void
     */
    private static function dimension(FormSpec $form, string $name, string $label): void
    {
        $form->text($name, $label)
            ->width('num')
            ->required()
            ->set('attrs', array('pattern' => '[0-9]+[xX][0-9]+'))
            ->set('pattern', self::DIMENSION)
            ->sanitize(static function ($value) {
                return strtolower(trim(strip_tags((string)$value)));
            });
    }

    /**
     * One number of the text options JSON: whole, and stored in the JSON rather than on its own.
     *
     * @param FormSpec $form
     * @param string   $name
     * @param string   $label
     *
     * @return void
     */
    private static function textOption(FormSpec $form, string $name, string $label): void
    {
        $form->number($name, $label)
            ->set('step', 1)
            ->suffix(__('px'))
            ->dependsOn(self::TYPE, 'text')
            ->persist(false)
            ->sanitize(static function ($value) {
                return (int)$value;
            });
    }

    /**
     * Open a watermark block: the div the screen has always shown and hidden, now by the
     * shared conditional-field attribute, and its heading.
     *
     * @param FormSpec $form
     * @param string   $type  the watermark type that shows it
     * @param string   $id
     * @param string   $title
     * @param string   $class
     *
     * @return void
     */
    private static function boxOpen(FormSpec $form, string $type, string $id, string $title, string $class = ''): void
    {
        $form
            ->custom($type . '_box_open', static function (array $spec) use ($type, $id, $title, $class) {
                $values = json_encode(array($type), JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo '<div id="' . osc_esc_html($id) . '"'
                     . ($class === '' ? '' : ' class="' . osc_esc_html($class) . '"')
                     . ' data-osc-depends="' . self::TYPE . '"'
                     . ' data-osc-depends-value="' . osc_esc_html($values) . '"'
                     . (($spec['values'][self::TYPE] ?? '') === $type ? '' : ' hidden') . '>';
                osc_admin_page_head($title);
            })
            ->set('row', false);
    }

    /**
     * Close a block opened by boxOpen().
     *
     * @param FormSpec $form
     * @param string   $type
     *
     * @return void
     */
    private static function boxClose(FormSpec $form, string $type): void
    {
        $form
            ->custom($type . '_box_close', static function () {
                echo '</div>';
            })
            ->set('row', false);
    }

    /**
     * The stored text watermark, drawn as the site will stamp it. Only once one is saved.
     *
     * @return void
     */
    private static function preview(): void
    {
        if (!osc_is_watermark_text() || !osc_watermark_text_color()) {
            return;
        }
        osc_admin_form_row_open(__('Preview Watermark'));
        \ImageProcessing::createWatermarkImageFromText(osc_watermark_text(), osc_watermark_text_color());
        echo '<div class="help-box"><img src="'
             . osc_base_url() . str_replace(osc_base_path(), '', osc_uploads_path())
             . \Preference::newInstance()->get('watermark_text_image_name') . '"/></div>';
        osc_admin_form_row_close();
    }

    /**
     * The text options as the form shows them: the stored JSON, with a missing or zero entry
     * read as its default and the y offset defaulting to the height.
     *
     * @return array<string,int|string>
     */
    private static function textOptions(): array
    {
        $stored  = json_decode((string)\Preference::newInstance()->get('watermark_text_options'), true);
        $stored  = is_array($stored) ? $stored : array();
        $options = array();
        foreach (self::TEXT_OPTIONS as $name => $default) {
            $options[$name] = empty($stored[$name])
                ? ($default ?? $options['watermark_height'])
                : ($name === 'background_color' ? (string)$stored[$name] : (int)$stored[$name]);
        }

        return $options;
    }

    /**
     * A JPEG quality libgd and imagick both accept, or the default.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function jpegQuality($value): int
    {
        $quality = (int)$value;

        return $quality < 1 || $quality > 100 ? self::DEFAULT_JPEG_QUALITY : $quality;
    }

    /**
     * A maximum size in kilobytes, lowered to what PHP will accept. Anything that is not
     * digits is handed on for validation to refuse.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function maxSize($value)
    {
        self::$lowered = null;
        $value         = (string)$value;
        if (!preg_match('/^[0-9]+$/', $value)) {
            return is_numeric($value) ? (float)$value : $value;
        }

        $limit = self::uploadLimitKb();
        if ((int)$value > $limit) {
            self::$lowered = $limit;

            return $limit;
        }

        return (int)$value;
    }

    /**
     * The watermark file posted with this request, or null when none was.
     *
     * @return array<string,mixed>|null
     */
    private static function upload(): ?array
    {
        $file = Params::getFiles(self::UPLOAD);
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /**
     * Why the posted watermark cannot be taken, or null when it can or there is none.
     *
     * @return string|null
     */
    private static function uploadError(): ?string
    {
        $file = self::upload();
        if ($file === null) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || $file['tmp_name'] === '') {
            return _m('There was a problem uploading the watermark image');
        }

        $info = @getimagesize($file['tmp_name']);

        return $info !== false && $info['mime'] === 'image/png'
            ? null
            : _m('The watermark image has to be a .PNG file');
    }

    /**
     * The page's effect: clear what the chosen type does not use, write the text options,
     * and put a new PNG in place.
     *
     * @param array<string,mixed> $values the values that were written
     *
     * @return void
     */
    private static function applyWatermark(array $values): void
    {
        self::$uploadFailed = false;

        switch ($values[self::TYPE] ?? '') {
            case 'none':
                osc_set_preference('watermark_text_color', '');
                osc_set_preference('watermark_text', '');
                osc_set_preference('watermark_image', '');
                break;
            case 'text':
                osc_set_preference('watermark_image', '');
                $options = array();
                foreach (array_keys(self::TEXT_OPTIONS) as $name) {
                    $options[$name] = $values[$name] ?? ($name === 'background_color' ? '' : 0);
                }
                osc_set_preference('watermark_text_options', json_encode($options));
                break;
            case 'image':
                osc_set_preference('watermark_text_color', '');
                osc_set_preference('watermark_text', '');
                $file = self::upload();
                if ($file === null) {
                    break;
                }
                // Checked again: a before_save listener may have switched the type to image
                // after validation, which only checks the file for a submitted image type.
                $error = self::uploadError();
                $path  = osc_uploads_path() . '/watermark.png';
                if ($error === null && move_uploaded_file($file['tmp_name'], $path)) {
                    osc_set_preference('watermark_image', $path);
                } else {
                    self::$uploadFailed = true;
                    osc_add_flash_error_message($error ?? _m('There was a problem uploading the watermark image'), 'admin');
                }
                break;
            default:
                break;
        }
    }
}
