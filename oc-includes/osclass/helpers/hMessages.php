<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Flash Messages
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Adds an ephemeral message to the session. (error style)
 *
 * @param $msg
 * @param $section
 *
 * @return void
 */
function osc_add_flash_message($msg, $section = 'pubMessages')
{
    Session::newInstance()->_setMessage($section, $msg, 'error');
}

/**
 * Adds an ephemeral message to the session. (ok style)
 *
 * @param $msg
 * @param $section
 *
 * @return void
 */
function osc_add_flash_ok_message($msg, $section = 'pubMessages')
{
    Session::newInstance()->_setMessage($section, $msg, 'ok');
}

/**
 * Adds an ephemeral message to the session. (error style)
 *
 * @param $msg
 * @param $section
 *
 * @return void
 */
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
    Session::newInstance()->_setMessage($section, $msg, 'error');
}

/**
 * Adds an ephemeral message to the session. (info style)
 *
 * @param $msg
 * @param $section
 *
 * @return void
 */
function osc_add_flash_info_message($msg, $section = 'pubMessages')
{
    Session::newInstance()->_setMessage($section, $msg, 'info');
}

/**
 * Adds an ephemeral message to the session. (warning style)
 *
 * @param $msg
 * @param $section
 *
 * @return void
 */
function osc_add_flash_warning_message($msg, $section = 'pubMessages')
{
    Session::newInstance()->_setMessage($section, $msg, 'warning');
}

/**
 * Shows all the pending flash messages in session and cleans up the array.
 *
 * The message element carries role=status (role=alert for an error), which is an
 * implicit live region: assistive technology is told the message appeared without
 * any script running. No dismiss control is rendered -- these messages are
 * one-shot, dropped from the session as they are printed, so they never come back
 * on the next page and a close button removed something already gone. The one
 * previously rendered was an <a> with no href: not focusable, not operable by
 * keyboard, and inert on every theme that ships no JavaScript.
 *
 * `flashmessage` and `flashmessage-<type>` are a published API -- core, bundled
 * plugins and unknown third-party themes all style those exact names -- so they
 * are never renamed. $class is the seam for a theme that wants its own.
 *
 * @param $section
 * @param $class
 * @param $id
 *
 * @return void
 */
function osc_show_flash_message($section = 'pubMessages', $class = 'flashmessage', $id = 'flashmessage')
{
    $messages = Session::newInstance()->_getMessage($section);
    if (is_array($messages) && $messages !== array()) {
        // Mount point for scripts that show a message after load (ItemForm's image
        // delete writes into it). Printed once, before the loop: inside it, two
        // queued messages emitted two elements carrying the same id.
        echo '<div id="flash_js"></div>';

        $firstMessage = true;
        foreach ($messages as $message) {
            $type = (is_array($message) && isset($message['type'])) ? (string) $message['type'] : '';
            // An error interrupts; anything else is a passive confirmation. Both are
            // implicit live regions, so the message announces itself with no
            // JavaScript -- which is the only way it announces at all on a front-end
            // theme, none of which ship the admin's enhancement script.
            $role = ($type === 'error') ? 'alert' : 'status';
            // The id is the caller's and belongs to one element; only the first
            // message can carry it and stay valid HTML.
            $idAttr       = $firstMessage ? ' id="' . $id . '"' : '';
            $firstMessage = false;

            if (isset($message['msg']) && $message['msg'] != '') {
                echo '<div' . $idAttr . ' role="' . $role . '" class="' . strtolower($class) . ' '
                    . strtolower($class) . '-' . $type . '">';
                echo osc_apply_filter('flash_message_text', $message['msg']);
                echo '</div>';
            } elseif ($message != '') {
                echo '<div' . $idAttr . ' role="' . $role . '" class="' . $class . '">';
                echo osc_apply_filter('flash_message_text', $message);
                echo '</div>';
            } else {
                echo '<div' . $idAttr . ' role="' . $role . '" class="' . $class . '" style="display:none;">';
                echo osc_apply_filter('flash_message_text', '');
                echo '</div>';
            }
        }
    }
    Session::newInstance()->_dropMessage($section);
}

/**
 *
 *
 * @param string $section
 * @param bool   $dropMessages
 *
 * @return string Message
 */
function osc_get_flash_message($section = 'pubMessages', $dropMessages = true)
{
    $message = Session::newInstance()->_getMessage($section);
    if ($dropMessages) {
        Session::newInstance()->_dropMessage($section);
    }

    return $message;
}
