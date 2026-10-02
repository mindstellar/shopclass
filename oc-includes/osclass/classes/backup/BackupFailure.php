<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\backup;

use RuntimeException;

/**
 * A backup or restore that stopped: why, at which stage, and for a restore whether the
 * safety copy was put back. Its message is for the site owner, never a path or a query.
 */
final class BackupFailure extends RuntimeException
{
    /** @var string */
    public $stage;

    /** @var bool|null null when the database was not touched */
    public $rolledBack;

    /** @var bool */
    public $cancelled;

    /**
     * @param string    $message
     * @param string    $stage
     * @param bool|null $rolledBack
     * @param bool      $cancelled
     */
    public function __construct(string $message, string $stage, ?bool $rolledBack = null, bool $cancelled = false)
    {
        parent::__construct($message);
        $this->stage      = $stage;
        $this->rolledBack = $rolledBack;
        $this->cancelled  = $cancelled;
    }

    /**
     * The run was cancelled.
     *
     * @param string $stage
     *
     * @return self
     */
    public static function cancelled(string $stage): self
    {
        return new self('Cancelled', $stage, null, true);
    }

    /**
     * An error message fit to show and log: its first line, without paths or query
     * strings, at most 250 characters.
     *
     * @param string $message
     *
     * @return string
     */
    public static function clean(string $message): string
    {
        $line = trim((string) strtok($message, "\r\n"));
        if (defined('ABS_PATH')) {
            $line = str_replace(array(ABS_PATH, rtrim(ABS_PATH, '/')), '', $line);
        }
        $line = (string) preg_replace('#(https?://[^\s?]+)\?\S*#', '$1', $line);
        $line = (string) preg_replace('#(?<![\w.])/(?:[\w.-]+/)+[\w.-]*#', '…', $line);

        return mb_substr($line, 0, 250);
    }
}
