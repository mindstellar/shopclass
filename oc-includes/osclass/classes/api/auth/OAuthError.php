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

namespace mindstellar\api\auth;

use mindstellar\api\Problem;
use mindstellar\api\Response;

/**
 * A token endpoint's refusal in the shape RFC 6749 §5.2 asks for: 400 with an `error` member.
 * The problem members stay as extensions, and `code` is the OAuth error.
 */
final class OAuthError
{
    /** The OAuth errors this site answers with. */
    public const ERRORS = ['invalid_request', 'invalid_grant', 'invalid_scope', 'unsupported_grant_type'];

    /** Problem codes that mean the request itself was malformed. */
    private const MALFORMED = ['invalid_json', 'unsupported_media_type', 'validation_failed'];

    private function __construct()
    {
    }

    /**
     * The OAuth answer for a problem; one that is not about the grant (429, 500) is kept.
     */
    public static function from(Response $problem): Response
    {
        $body  = (array) $problem->body();
        $error = (string) ($body['error'] ?? '');
        if (!in_array($error, self::ERRORS, true)) {
            if (!in_array((string) ($body['code'] ?? ''), self::MALFORMED, true)) {
                return $problem->withHeader('Cache-Control', 'no-store');
            }
            $error = 'invalid_request';
        }
        $extra = array_diff_key($body, array_flip(['type', 'title', 'status', 'detail', 'code']));
        $out   = Problem::make($error, (string) ($body['detail'] ?? ''), ['error' => $error, 'error_description' => (string) ($body['detail'] ?? '')] + $extra);

        return $out->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
