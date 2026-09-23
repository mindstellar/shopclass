<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\ui;

/**
 * The rendering behind osc_admin_photo_grid(). That function is the public,
 * plugin-facing API and stays procedural; this class holds the markup.
 *
 * Two kinds of tile share the grid. An attached one stands for a row that already exists,
 * and is removed through the delete endpoint by the id, item and code that endpoint
 * authorises on. A staged one stands for a file sitting in uploads/temp/ under this
 * form's upload token, carries the ajax_photos[] input the save reads, and is removed by
 * that temp name. The file input keeps its posted name so a browser with no JavaScript
 * still uploads the ordinary way.
 *
 * The first tile is the cover, because the save attaches photos in the order it is handed
 * them and nothing stores an order afterwards. So a tile may be moved to the front only
 * while every tile in the grid is still staged; once a photo is attached its position is
 * its row's, and no posted field can change it.
 */
class PhotoGrid
{
    /**
     * The grid. Body of osc_admin_photo_grid().
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function render(array $opts = array())
    {
        $name      = (string)($opts['name'] ?? 'photos');
        $id        = (string)($opts['id'] ?? 'photos');
        $label     = (string)($opts['label'] ?? __('Photos'));
        $resources = array_values((array)($opts['resources'] ?? array()));
        $staged    = array_values((array)($opts['staged'] ?? array()));
        $max       = (int)($opts['max'] ?? 0);
        $secret    = (string)($opts['secret'] ?? '');
        $labelId   = $id . '-label';

        // Cover selection is only offered where the grid decides the order: with nothing
        // attached yet, every tile is staged and ajax_photos[] carries the choice.
        $cover = !empty($opts['cover']) && $resources === array();
        $count = count($resources) + count($staged);
        $room  = $max === 0 || $count < $max;

        echo '<div class="osc-field osc-editor-photos photo_container" data-osc-photos'
            . ' data-upload-url="' . osc_esc_html((string)($opts['upload_url'] ?? '')) . '"'
            . ' data-delete-url="' . osc_esc_html((string)($opts['delete_url'] ?? '')) . '"'
            . ' data-temp-url="' . osc_esc_html((string)($opts['temp_url'] ?? '')) . '"'
            . ' data-max="' . $max . '"'
            . ' data-max-size="' . (int)($opts['max_size'] ?? 0) . '"'
            . ' data-extensions="' . osc_esc_html(implode(',', self::extensions($opts))) . '"'
            . ($cover ? ' data-cover="1"' : '')
            . ' data-strings="' . osc_esc_html((string)json_encode(self::strings())) . '">';

        echo '<span class="form-label" id="' . osc_esc_html($labelId) . '">'
            . osc_esc_html($label) . '</span>';

        echo '<div class="osc-photo-grid" role="list" aria-labelledby="' . osc_esc_html($labelId) . '"'
            . ' data-osc-photo-grid>';

        $first = true;
        foreach ($resources as $resource) {
            self::attachedTile((array)$resource, $secret, $first, $cover);
            $first = false;
        }
        foreach ($staged as $file) {
            self::stagedTile((string)$file, (string)($opts['temp_url'] ?? ''), $first, $cover);
            $first = false;
        }

        // The label is the drop target as well as the picker, so the same tile does both
        // and neither needs a control of its own.
        echo '<label class="osc-photo-add" data-osc-photo-add' . ($room ? '' : ' hidden') . '>';
        echo '<i class="bi bi-plus-lg" aria-hidden="true"></i>';
        echo '<span>' . osc_esc_html(__('Add photos')) . '</span>';
        echo '<input type="file" name="' . osc_esc_html($name) . '[]" accept="'
            . osc_esc_html(self::accept($opts)) . '" multiple />';
        echo '</label>';

        echo '</div>';

        echo '<p class="osc-photo-count">';
        echo '<span data-osc-photo-count role="status" aria-live="polite">'
            . osc_esc_html(self::countLine($count, $max)) . '</span> ';
        echo osc_esc_html($cover
            ? __('Drop images here or choose files. The first photo is the cover, and you can change which.')
            : __('Drop images here or choose files. The first photo is the cover.'));
        echo '</p>';

        echo '</div>';
    }

    /**
     * How many photos there are, against the site's ceiling when it has one.
     *
     * @param int $count
     * @param int $max
     *
     * @return string
     */
    public static function countLine($count, $max)
    {
        return (int)$max > 0
            ? str_replace(
                array('{n}', '{max}'),
                array((int)$count, (int)$max),
                __('{n} of {max} photos.')
            )
            : str_replace('{n}', (string)(int)$count, __('{n} photos.'));
    }

    /**
     * One photo that already belongs to the record.
     *
     * @param array<string,mixed> $resource
     * @param string              $secret
     * @param bool                $isCover
     * @param bool                $cover    Whether the cover can still be chosen
     *
     * @return void
     */
    private static function attachedTile(array $resource, $secret, $isCover, $cover)
    {
        $resourceId = (string)($resource['pk_i_id'] ?? '');
        $extension  = (string)($resource['s_extension'] ?? '');
        $file       = $resourceId . ($extension === '' ? '' : '.' . $extension);
        $path       = osc_base_url() . (string)($resource['s_path'] ?? '');
        $base       = osc_apply_filter('resource_path', $path, $resource);
        $thumb      = $base . $resourceId . '_thumbnail.' . $extension;

        echo '<div class="osc-photo" role="listitem" data-osc-photo'
            . ' data-id="' . osc_esc_html($resourceId) . '"'
            . ' data-item="' . osc_esc_html((string)($resource['fk_i_item_id'] ?? '')) . '"'
            . ' data-code="' . osc_esc_html((string)($resource['s_name'] ?? '')) . '"'
            . ' data-secret="' . osc_esc_html($secret) . '"'
            . ' data-label="' . osc_esc_html($file) . '">';
        echo '<img class="osc-photo-img" src="' . osc_esc_html($thumb) . '" alt="" loading="lazy" />';
        self::tileControls($file, $isCover, $cover);
        echo '</div>';
    }

    /**
     * One photo uploaded ahead of the save and waiting in uploads/temp/.
     *
     * @param string $file    The staged file name
     * @param string $tempUrl
     * @param bool   $isCover
     * @param bool   $cover   Whether the cover can still be chosen
     *
     * @return void
     */
    private static function stagedTile($file, $tempUrl, $isCover, $cover)
    {
        echo '<div class="osc-photo" role="listitem" data-osc-photo'
            . ' data-temp="' . osc_esc_html($file) . '"'
            . ' data-label="' . osc_esc_html($file) . '">';
        echo '<img class="osc-photo-img" src="' . osc_esc_html($tempUrl . $file) . '" alt="" loading="lazy" />';
        echo '<input type="hidden" name="ajax_photos[]" value="' . osc_esc_html($file) . '" />';
        self::tileControls($file, $isCover, $cover);
        echo '</div>';
    }

    /**
     * What sits on top of a tile: the cover badge or the control that makes it the cover,
     * the remove button, and the file name.
     *
     * @param string $file
     * @param bool   $isCover
     * @param bool   $cover
     *
     * @return void
     */
    private static function tileControls($file, $isCover, $cover)
    {
        if ($isCover) {
            echo '<span class="osc-photo-cover">' . osc_esc_html(__('Cover')) . '</span>';
        } elseif ($cover) {
            echo '<button type="button" class="osc-photo-make-cover" data-osc-photo-cover'
                . ' title="' . osc_esc_html(__('Make this the cover')) . '"'
                . ' aria-label="' . osc_esc_html(str_replace('{file}', $file, __('Make {file} the cover photo'))) . '">'
                . '<i class="bi bi-star" aria-hidden="true"></i></button>';
        }

        echo '<button type="button" class="osc-photo-remove" data-osc-photo-remove'
            . ' title="' . osc_esc_html(__('Remove')) . '"'
            . ' aria-label="' . osc_esc_html(str_replace('{file}', $file, __('Remove {file}'))) . '">'
            . '<i class="bi bi-x-lg" aria-hidden="true"></i></button>';

        echo '<span class="osc-photo-name">' . osc_esc_html($file) . '</span>';
    }

    /**
     * The allowed extensions, lower-cased, from the option or the site's setting.
     *
     * @param array<string,mixed> $opts
     *
     * @return string[]
     */
    private static function extensions(array $opts)
    {
        $allowed = $opts['extensions'] ?? (function_exists('osc_allowed_extension') ? osc_allowed_extension() : '');
        $list    = is_array($allowed) ? $allowed : explode(',', (string)$allowed);
        $list    = array_map(static fn ($e) => strtolower(trim((string)$e)), $list);

        return array_values(array_filter($list, 'strlen'));
    }

    /**
     * The file input's accept attribute.
     *
     * @param array<string,mixed> $opts
     *
     * @return string
     */
    private static function accept(array $opts)
    {
        $list = self::extensions($opts);

        return $list === array() ? 'image/*' : '.' . implode(',.', $list);
    }

    /**
     * What the browser side says, so no message is written in JavaScript.
     *
     * @return array<string,string>
     */
    private static function strings()
    {
        return array(
            'cover'     => __('Cover'),
            'makeCover' => __('Make this the cover'),
            'coverOf'   => __('Make {file} the cover photo'),
            'remove'    => __('Remove'),
            'removeOf'  => __('Remove {file}'),
            'confirm'   => __("This action can't be undone. Are you sure you want to continue?"),
            'type'      => __('{file} is not an image this site accepts.'),
            'size'      => __('{file} is too large.'),
            'tooMany'   => __('No room for more photos.'),
            'failed'    => __('{file} could not be uploaded.'),
            'gone'      => __('That photo could not be removed.'),
            'countMax'  => __('{n} of {max} photos.'),
            'count'     => __('{n} photos.'),
        );
    }
}

/* file end: ./oc-includes/osclass/classes/admin/ui/PhotoGrid.php */
