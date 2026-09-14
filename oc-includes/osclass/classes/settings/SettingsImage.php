<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\settings;

use ImageProcessing;
use mindstellar\model\Resource;
use mindstellar\storage\ResourceUploader;
use Params;
use Throwable;

/**
 * The file half of an 'image' field on a declared settings page.
 *
 * The page stores a t_resource id; this checks a posted file, hands it to ResourceUploader
 * and deletes the image it replaces. Every write refuses to run outside a signed-in admin
 * with the page's capability, so calling the save pipeline from the front end can neither
 * upload nor delete.
 *
 * @package mindstellar\settings
 */
final class SettingsImage
{
    /** Variants a stored image can be read as, beyond the main file. */
    public const VARIANTS = array('', 'preview', 'thumbnail');

    /** @var array<int,array<string,mixed>|null> resource rows read this request */
    private static array $rows = array();

    /**
     * The request name of the "Remove image" box that belongs to an image field.
     *
     * @param string $name
     *
     * @return string
     */
    public static function removeName(string $name): string
    {
        return $name . SettingsPageRegistry::IMAGE_REMOVE_SUFFIX;
    }

    /**
     * The size cap in KB: the field's 'max_kb', or the site's upload limit. 0 is no cap.
     *
     * @param array<string,mixed> $field
     *
     * @return int
     */
    public static function maxKb(array $field): int
    {
        if (isset($field['max_kb'])) {
            return (int)$field['max_kb'];
        }

        return max(0, (int)osc_max_size_kb());
    }

    /**
     * Whether this request may upload or delete a settings image for $page.
     *
     * @param array<string,mixed> $page normalised page spec
     *
     * @return bool
     */
    public static function allowed(array $page): bool
    {
        if (!defined('OC_ADMIN') || OC_ADMIN !== true || !osc_is_admin_user_logged_in()) {
            return false;
        }

        return ($page['capability'] ?? 'administrator') === 'moderator' || !osc_is_moderator();
    }

    /**
     * What was posted for one image field: the temp file to store, an error to report, or
     * neither when no file was sent.
     *
     * @param array<string,mixed> $field
     *
     * @return array{file:?string,error:?string}
     */
    public static function posted(array $field): array
    {
        $label = (string)($field['label'] ?? $field['name']);
        $file  = Params::getFiles((string)$field['name']);
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return array('file' => null, 'error' => null);
        }

        $error = (int)$file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            return array('file' => null, 'error' => null);
        }

        $maxKb = self::maxKb($field);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            || ($error === UPLOAD_ERR_OK && $maxKb > 0 && (int)($file['size'] ?? 0) > $maxKb * 1024)
        ) {
            return array(
                'file'  => null,
                'error' => $maxKb > 0
                    ? sprintf(__('%1$s must be %2$d KB or smaller'), $label, $maxKb)
                    : sprintf(__('%s is too large to upload'), $label),
            );
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return array('file' => null, 'error' => sprintf(__('%s could not be uploaded'), $label));
        }

        try {
            ImageProcessing::fromFile($tmp);
        } catch (Throwable $e) {
            return array('file' => null, 'error' => sprintf(__('%s is not a valid image'), $label));
        }

        return array('file' => $tmp, 'error' => null);
    }

    /**
     * Store a checked temp file as a settings image.
     *
     * @param array<string,mixed> $page normalised page spec
     * @param string              $tmp
     *
     * @return int|null the new resource id, or null when refused or the upload failed
     */
    public static function upload(array $page, string $tmp): ?int
    {
        if (!self::allowed($page)) {
            return null;
        }

        // A logo keeps its own shape: scaled down to fit, never padded onto the listing photo canvas.
        $row = (new ResourceUploader())->upload(Resource::OWNER_SETTING, 0, $tmp, array(
            'variants' => array('normal' => '1600x1600', 'preview' => '800x800', 'thumbnail' => '240x240'),
            'fit'      => true,
        ));
        if (!is_array($row) || empty($row['pk_i_id'])) {
            return null;
        }
        unset(self::$rows[(int)$row['pk_i_id']]);

        return (int)$row['pk_i_id'];
    }

    /**
     * Delete a stored settings image. An id that is not a settings image is left alone, so a
     * hand-edited preference cannot delete a listing photo or an avatar.
     *
     * @param array<string,mixed> $page normalised page spec
     * @param mixed               $id
     *
     * @return bool whether a resource was deleted
     */
    public static function delete(array $page, $id): bool
    {
        if (!self::allowed($page)) {
            return false;
        }

        $row = self::row($id);
        if ($row === null) {
            return false;
        }

        (new ResourceUploader())->delete($row);
        unset(self::$rows[(int)$row['pk_i_id']]);

        return true;
    }

    /**
     * Undo the image half of a refused save: delete what it uploaded and put every image
     * field back to the id still stored, so the page redraws what is really there.
     *
     * @param array<string,mixed> $page   normalised page spec
     * @param array<string,int>   $fresh  field name => id uploaded by this save
     * @param array<string,mixed> $stored field name => id stored before it
     * @param array<string,mixed> $values submitted values, keyed by field name
     *
     * @return array<string,mixed> the values with each image field restored
     */
    public static function rollback(array $page, array $fresh, array $stored, array $values): array
    {
        foreach ($fresh as $newId) {
            self::delete($page, $newId);
        }
        foreach ($stored as $name => $storedId) {
            if (array_key_exists($name, $values)) {
                $values[$name] = $storedId;
            }
        }

        return $values;
    }

    /**
     * The URL of a stored settings image, or '' when there is none.
     *
     * @param mixed  $id
     * @param string $variant '' for the main image, 'preview' or 'thumbnail'
     *
     * @return string
     */
    public static function url($id, string $variant = ''): string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return '';
        }
        $row = self::row($id);

        return $row === null ? '' : osc_get_resource_url($row, $variant);
    }

    /**
     * The settings-image resource row behind an id, or null. Read from the owner list, which
     * core caches, so a logo on every public page costs no query of its own.
     *
     * @param mixed $id
     *
     * @return array<string,mixed>|null
     */
    private static function row($id): ?array
    {
        if (is_string($id) && ctype_digit($id)) {
            $id = (int)$id;
        }
        if (!is_int($id) || $id <= 0) {
            return null;
        }
        if (array_key_exists($id, self::$rows)) {
            return self::$rows[$id];
        }

        $found = null;
        try {
            foreach (Resource::newInstance()->findByOwner(Resource::OWNER_SETTING, 0) as $row) {
                if ((int)($row['pk_i_id'] ?? 0) === $id) {
                    $found = $row;
                    break;
                }
            }
        } catch (Throwable $e) {
            $found = null;
        }

        return self::$rows[$id] = $found;
    }
}
