<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\utility;

/**
 * The one way an ajax action answers.
 *
 * Ninety-four call sites wrote `echo json_encode(...)` themselves and twenty-five of them --
 * every front-end one, reachable by anonymous visitors -- sent no Content-Type at all, so the
 * JSON went out as `text/html`. A browser pointed straight at such a URL renders the body
 * rather than downloading it, and some of those bodies carry text a visitor typed.
 *
 * @package mindstellar\utility
 */
final class AjaxResponse
{
    /**
     * Send $data as JSON, with the headers that stop a browser treating it as a page.
     *
     * Does not exit. Code that must stop after responding should exit itself.
     *
     * @param mixed $data
     * @param int   $status HTTP status, when nothing has been sent yet
     * @param int   $flags  json_encode flags; pass by name, never positionally
     *
     * @return void
     */
    public static function json($data, int $status = 200, int $flags = 0): void
    {
        if (headers_sent()) {
            // The one way this class fails is by quietly not running, which puts the
            // response back to text/html -- the thing it exists to prevent. Say so.
            trigger_error('AjaxResponse: headers already sent, response left unlabelled', E_USER_NOTICE);
        } else {
            if ($status !== 200) {
                http_response_code($status);
            }
            header('Content-Type: application/json; charset=UTF-8');
            // The Content-Type above is only worth having if the browser believes it.
            header('X-Content-Type-Options: nosniff');
            // Front ajax skips osc_send_response_cache_headers(), so without this a JSON
            // answer carrying somebody's data is heuristically cacheable by a shared cache.
            header('Cache-Control: private, no-store');
        }

        echo json_encode($data, $flags);
    }

    /**
     * The `{"error": …}` shape these controllers already answer with.
     *
     * Legacy, kept for existing JS and plugins. Do not reuse it for a new API: it reports
     * failure with HTTP 200, which a REST endpoint should not do.
     *
     * @param string $message
     * @param int    $code    the error number the existing callers read
     * @param int    $status
     *
     * @return void
     */
    public static function error(string $message, int $code = 1, int $status = 200): void
    {
        self::json(array('error' => $code, 'msg' => $message), $status);
    }
}
