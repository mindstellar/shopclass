<?php

/*
 * This file is part of Osclass (Mindstellar).
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
 * @package    Osclass
 * @subpackage Helpers
 * @author     Osclass
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
 * @param $section
 * @param $class
 * @param $id
 *
 * @return void
 */
function osc_show_flash_message($section = 'pubMessages', $class = 'flashmessage', $id = 'flashmessage')
{
    $messages = Session::newInstance()->_getMessage($section);
    if (is_array($messages)) {
        foreach ($messages as $message) {
            echo '<div id="flash_js"></div>';

            if (isset($message['msg']) && $message['msg'] != '') {
                echo '<div id="' . $id . '" class="' . strtolower($class) . ' ' . strtolower($class) . '-'
                    . $message['type'] . '"><a class="btn ico btn-mini ico-close">x</a>';
                echo osc_apply_filter('flash_message_text', $message['msg']);
                echo '</div>';
            } elseif ($message != '') {
                echo '<div id="' . $id . '" class="' . $class . '">';
                echo osc_apply_filter('flash_message_text', $message);
                echo '</div>';
            } else {
                echo '<div id="' . $id . '" class="' . $class . '" style="display:none;">';
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
