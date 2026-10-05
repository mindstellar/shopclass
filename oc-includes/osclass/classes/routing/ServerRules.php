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

namespace mindstellar\routing;

/**
 * The .htaccess Apache reads for friendly URLs. Core treats the file as its own only while
 * it holds exactly the rules some release wrote; anything else was edited by hand and is
 * never rewritten.
 */
final class ServerRules
{
    /** The line that hands the Authorization header to PHP, which Apache hides otherwise. */
    public const AUTHORIZATION = 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]';

    public const CURRENT    = 'current';
    public const UPDATED    = 'updated';
    public const OLD        = 'old';
    public const CUSTOM     = 'custom';
    public const ABSENT     = 'absent';
    public const UNWRITABLE = 'unwritable';

    private function __construct()
    {
    }

    /**
     * The rules this release writes for a site at $base (REL_WEB_URL).
     */
    public static function apache(string $base): string
    {
        return "<IfModule mod_rewrite.c>\n"
               . "RewriteEngine On\n"
               . "RewriteBase {$base}\n"
               . self::AUTHORIZATION . "\n"
               . "RewriteRule ^index\\.php$ - [L]\n"
               . "RewriteCond %{REQUEST_FILENAME} !-f\n"
               . "RewriteCond %{REQUEST_FILENAME} !-d\n"
               . "RewriteRule . {$base}index.php [L]\n"
               . "</IfModule>\n"
               . "<IfModule mod_mime.c>\n"
               . "AddType text/xsl .xsl\n"
               . '</IfModule>';
    }

    /**
     * What the file holds: the current rules, an older release's, a hand-edited file, or none.
     */
    public static function status(string $file, string $base): string
    {
        if (!is_file($file)) {
            return self::ABSENT;
        }
        $held = self::normalize((string) file_get_contents($file));
        if ($held === self::normalize(self::apache($base))) {
            return self::CURRENT;
        }
        if (in_array($held, array_map([self::class, 'normalize'], self::older($base)), true)) {
            return self::OLD;
        }

        return self::CUSTOM;
    }

    /**
     * Bring a file an older release wrote up to the current rules. A hand-edited file, or
     * none, is left alone.
     *
     * @return string the status after: CURRENT, UPDATED, CUSTOM, ABSENT or UNWRITABLE
     */
    public static function refresh(string $file, string $base): string
    {
        $status = self::status($file, $base);
        if ($status !== self::OLD) {
            return $status;
        }

        return is_writable($file) && file_put_contents($file, self::apache($base)) !== false ? self::UPDATED : self::UNWRITABLE;
    }

    /**
     * Whether a file passes the Authorization header on to PHP.
     */
    public static function passesAuthorization(string $file): bool
    {
        return is_file($file) && str_contains((string) file_get_contents($file), 'HTTP_AUTHORIZATION');
    }

    /**
     * The rules earlier releases wrote: before the Authorization line, and before that
     * without the mod_mime block.
     *
     * @return string[]
     */
    private static function older(string $base): array
    {
        $noAuth = str_replace(self::AUTHORIZATION . "\n", '', self::apache($base));

        return [$noAuth, substr($noAuth, 0, (int) strpos($noAuth, "</IfModule>\n") + strlen('</IfModule>'))];
    }

    private static function normalize(string $text): string
    {
        return rtrim(str_replace("\r\n", "\n", $text));
    }
}
