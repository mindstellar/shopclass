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
     * Does not exit, on purpose. Every caller is `AjaxResponse::json(...); break;` inside a
     * switch that `doModel()` ends immediately after, so exiting would change nothing today --
     * and adding it while converting 103 call sites would have meant the conversion was no
     * longer just a header change, with no way to tell a real difference from a mistake.
     *
     * Somewhere that keeps running after the response is a different matter: a `404` that
     * carries on executing is a bug. Code like that should exit itself, or ask for it here
     * explicitly, rather than have this guess from context.
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
     * Legacy, and kept because the JavaScript and the plugins already read it. **An API should
     * not reuse this.** It reports failure in a 200 body, which leaves the status code saying
     * the opposite; a REST endpoint wants the status doing that work and an envelope of its
     * own. Calling this from an API for convenience would make this shape a public contract by
     * accident, and then it could not be changed.
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
