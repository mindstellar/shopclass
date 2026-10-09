<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\backup;

use mindstellar\utility\Formatting;

/**
 * Checks on a backup file uploaded for restore: PHP's size limit and the upload's error code.
 */
final class RestoreUpload
{
    /**
     * The largest upload PHP accepts here, in bytes: the smaller of upload_max_filesize
     * and post_max_size. PHP_INT_MAX when neither sets a limit.
     *
     * @return int
     */
    public static function limit(): int
    {
        $limits = array();
        foreach (array('upload_max_filesize', 'post_max_size') as $setting) {
            $bytes = Formatting::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return $limits === array() ? PHP_INT_MAX : min($limits);
    }

    /**
     * Why an uploaded restore file cannot be used, or '' when it can. A file field sent
     * as an array (sql[]) is refused like a missing file.
     *
     * @param mixed $file the \$_FILES entry
     *
     * @return string
     */
    public static function error($file): string
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])
            || !is_int($file['error']) || !is_string($file['tmp_name'])
        ) {
            return __('No file was uploaded');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return self::tooLargeMessage();
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE || (int) $file['size'] === 0) {
            return __('No file was uploaded');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return __('The upload failed. Try again.');
        }

        return '';
    }

    /**
     * Save an uploaded restore file into the backup folder and check it can be restored. A file
     * that fails the check is removed again.
     *
     * @param mixed                                $file  the \$_FILES entry
     * @param BackupStore|null                     $store the site's backup folder by default
     * @param (callable(string, string): bool)|null $move  moves the upload; move_uploaded_file() by default
     *
     * @return array{name:string,error:string} the saved file's name, or why it was refused
     */
    public static function store($file, ?BackupStore $store = null, ?callable $move = null): array
    {
        $error = self::error($file);
        if ($error === '' && $move === null && !is_uploaded_file($file['tmp_name'])) {
            $error = __('No file was uploaded');
        }
        $move ??= 'move_uploaded_file';
        $ext    = $error === '' ? self::type($file['tmp_name']) : '';
        if ($error === '' && $ext === '') {
            $error = __('Choose a .zip or .sql backup file.');
        }
        $store ??= BackupStore::site();
        if ($error === '' && !$store->protect()) {
            $error = BackupStore::unwritable();
        }
        $name = BackupStore::uploadName($ext);
        $path = $store->dir() . $name;
        if ($error === '' && !$move($file['tmp_name'], $path)) {
            $error = __('The upload failed. Try again.');
        }
        if ($error === '') {
            @chmod($path, 0600);
            $error = BackupService::checkFile($path)['reason'];
            if ($error !== '') {
                @unlink($path);
            }
        }

        return array('name' => $error === '' ? $name : '', 'error' => $error);
    }

    /**
     * 'zip' or 'sql' for a file that looks like one, else ''.
     */
    public static function type(string $file): string
    {
        $head = (string) @file_get_contents($file, false, null, 0, 8192);
        if (strncmp($head, "PK\x03\x04", 4) === 0) {
            return 'zip';
        }
        if ($head === '' || strpos($head, "\0") !== false) {
            return '';
        }
        if (function_exists('finfo_open')) {
            $mime = (string) finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $head);
            if (strpos($mime, 'text/') !== 0 && $mime !== 'application/sql') {
                return '';
            }
        }

        return 'sql';
    }

    /**
     * The message for a file over PHP's upload limit.
     *
     * @return string
     */
    public static function tooLargeMessage(): string
    {
        return sprintf(
            __('The file is larger than this server accepts (%s). Raise upload_max_filesize and post_max_size, or restore from the command line.'),
            Formatting::bytes(self::limit())
        );
    }
}
