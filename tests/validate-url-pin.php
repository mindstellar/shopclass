<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Validate::url() with the address check asks the host it resolved and checked, not the
 * host name again. DNS and the cURL options are stood in for, so nothing leaves the machine.
 *
 * No database.  Usage:  php tests/validate-url-pin.php
 */

namespace mindstellar\security {
    function dns_get_record($host, $type = 0)
    {
        return array(array('ip' => '93.184.216.34'));
    }
}

namespace mindstellar\utility {
    function curl_setopt_array($ch, array $options)
    {
        if (!isset($options[CURLOPT_NOBODY])) {
            return \curl_setopt_array($ch, $options);
        }
        $GLOBALS['seenCurlOptions'] = $options;
        throw new \RuntimeException('stop before connecting');
    }
}

namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);

    define('ABS_PATH', dirname(__DIR__) . '/');
    define('OSCLASS_VERSION', '0');

    require ABS_PATH . 'oc-includes/vendor/autoload.php';
    require_once __DIR__ . '/lib/harness.php';

    function osc_base_url()
    {
        return 'http://localhost/';
    }

    $GLOBALS['okCount']    = 0;
    $GLOBALS['failCount']  = 0;
    $GLOBALS['failLabels'] = array();

    harness_section('Validate::url with a HEAD check');

    try {
        (new \mindstellar\utility\Validate())->url('http://pinned.example.com/page', true, true);
    } catch (\Throwable $e) {
        // The stand-in stops the request.
    }
    $options = $GLOBALS['seenCurlOptions'] ?? array();
    pin('the HEAD is pinned to the address that was checked', array('pinned.example.com:80:93.184.216.34'), $options[CURLOPT_RESOLVE] ?? null);
    pin('...with no proxy', '*', $options[CURLOPT_NOPROXY] ?? null);

    exit(harness_result());
}
