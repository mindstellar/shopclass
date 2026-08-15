<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Billing;

/**
 * The one billing route a payment provider's server hits directly: no session, no
 * CSRF token, because neither can exist on a server-to-server callback.
 *
 * Everything else about billing needs a logged-in buyer and lives in CWebBilling.
 *
 * Class CWebBillingNonSecure
 */
class CWebBillingNonSecure extends BaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_billing_non_secure');
    }

    //Business Layer...
    public function doModel()
    {
        if ($this->action !== 'callback') {
            $this->do404();

            return;
        }

        $this->callback();
    }

    /**
     * Resolve the gateway, hand it the request, and act on what it reports -- then
     * answer with the exact same short body no matter what happened. An unknown
     * gateway, no matching order, a mismatched amount and a genuine success all have
     * to look identical from the outside, or the response itself becomes a way to
     * probe whether an order exists.
     */
    private function callback()
    {
        $gatewayId = Params::getParamString('gateway');

        // Raw, unpurified: a gateway's signature or reference token must reach
        // handleCallback() byte-for-byte, and HTMLPurifier would otherwise be free
        // to rewrite it.
        $payload = Params::getParamsAsArray('', false);

        $contentType = (string) Params::getServerParam('CONTENT_TYPE');
        if (stripos($contentType, 'json') !== false) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $payload['raw'] = $decoded;
            }
        }

        Billing::handleCallback($gatewayId, $payload);

        header('Content-Type: text/plain; charset=UTF-8');
        http_response_code(200);
        echo 'OK';
        exit;
    }

    //hopefully generic...

    /**
     * This route never renders a theme page or redirects -- the plain-text body in
     * callback() is the entire response, on every outcome.
     *
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
    }
}

/* file end: ./oc-includes/osclass/classes/controller/CWebBillingNonSecure.php */
