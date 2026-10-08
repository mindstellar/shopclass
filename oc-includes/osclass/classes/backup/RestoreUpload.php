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

use mindstellar\admin\form\MediaSettingsScreen;
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
            $kb = MediaSettingsScreen::sizeToKb((string) ini_get($setting));
            if ($kb > 0) {
                $limits[] = $kb * 1024;
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
