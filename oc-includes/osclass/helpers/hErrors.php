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
 * Helper Error
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Stop Shopclass and render a full-page system message on the shared, on-brand
 * system page (no Bootstrap, self-contained). The message is trusted HTML, so
 * callers may include links and <code>; embedded .button/.btn links are styled
 * automatically.
 *
 * @param string $title   Page title (a legacy "Foo &raquo; Bar" title is
 *                        cleaned into a heading)
 * @param string $message Message body (trusted HTML)
 * @param array  $options Optional: 'heading', 'tone' (info|warning|danger|
 *                        success), 'actions' ([['label','href','primary']]),
 *                        'status' (HTTP code)
 *
 * @since 1.2
 */
function osc_die($title, $message, $options = array())
{
    $heading = isset($options['heading']) ? $options['heading'] : trim(preg_replace('/\s*&raquo;.*$/', '', $title));
    if ($heading === '') {
        $heading = strip_tags($title);
    }

    $oscSys = array(
        'title'     => strip_tags($title),
        'heading'   => $heading,
        'body'      => $message,
        'bodyHtml'  => true,
        'tone'      => isset($options['tone']) ? $options['tone'] : 'danger',
        'actions'   => isset($options['actions']) ? $options['actions'] : array(),
        'brandName' => isset($options['brandName']) ? $options['brandName'] : null,
        'brandLogo' => isset($options['brandLogo']) ? $options['brandLogo'] : null,
    );

    if (!headers_sent()) {
        http_response_code(isset($options['status']) ? (int)$options['status'] : 500);
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $template = ABS_PATH . 'oc-includes/osclass/gui/system-page.php';
    if (is_file($template)) {
        include $template;
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars(strip_tags($title), ENT_QUOTES, 'UTF-8')
            . '</title><h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>' . $message;
    }

    die();
}


/**
 * @param      $param
 * @param bool $htmlencode
 * @param bool $quotes_encode
 *
 * @return string
 */
function getErrorParam($param, $htmlencode = false, $quotes_encode = true)
{
    if ($param == '') {
        return '';
    }
    if (!isset($_SERVER[$param])) {
        return '';
    }
    $value = $_SERVER[$param];
    if ($htmlencode) {
        if ($quotes_encode) {
            return htmlspecialchars(stripslashes($value), ENT_QUOTES);
        }

        return htmlspecialchars(stripslashes($value), ENT_NOQUOTES);
    }
    return $value;
}


/**
 * @param $array
 *
 * @return string
 */
function strip_slashes_extended_e($array)
{
    if (is_array($array)) {
        foreach ($array as $k => &$v) {
            $v = strip_slashes_extended_e($v);
        }
    } else {
        $array = stripslashes($array);
    }

    return $array;
}


/**
 * @return string
 */
function osc_get_absolute_url()
{
    $protocol = (getErrorParam('HTTPS') === 'on' || getErrorParam('HTTPS') == 1
        || getErrorParam('HTTP_X_FORWARDED_PROTO') === 'https') ? 'https' : 'http';

    return $protocol . '://' . getErrorParam('HTTP_HOST')
        . preg_replace(
            '/((oc-admin)|(oc-includes)|(oc-content)|([a-z]+\.php)|(\?.*)).*/i',
            '',
            getErrorParam('REQUEST_URI', false, false)
        );
}
