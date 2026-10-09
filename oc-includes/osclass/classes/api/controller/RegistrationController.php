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

namespace mindstellar\api\controller;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\Response;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\auth\Actor;
use mindstellar\user\AccountInput;

/**
 * Sign-up, when the site switches it on, through AccountService::register() with the same checks,
 * hooks and activation e-mail as the form. Each address gets a few tries an hour and the site a
 * cap, and a taken e-mail gets the answer a new one gets.
 */
final class RegistrationController
{
    private ApiSettings $settings;
    private RateLimiter $limiter;

    public function __construct(private ApiServices $api)
    {
        $this->settings = $api->settings();
        $this->limiter = $api->limiter();
    }

    public function register(ApiCall $call): Response
    {
        $request = $call->request();
        if (!$this->settings->registration()) {
            throw ProblemException::of('feature_disabled', 'This site does not take sign-ups through the API.');
        }
        // Both limits stand in for a captcha, so they fail closed: no counter, no sign-up.
        $busy = 'Too many sign-ups right now. Try again later.';
        $this->limiter->enforce($this->api->ratePolicy()->signUp($request->ip()), $busy, false);

        $input    = $call->input();
        $password = (string) ($input['password'] ?? '');
        $form     = AccountInput::signUpFromArray([
            's_name'         => (string) ($input['name'] ?? ''),
            's_email'        => trim((string) ($input['email'] ?? '')),
            's_password'     => $password,
            's_password2'    => $password,
            's_username'     => (string) ($input['username'] ?? ''),
            's_phone_land'   => (string) ($input['phone_land'] ?? ''),
            's_phone_mobile' => (string) ($input['phone_mobile'] ?? ''),
        ]);
        // The site-wide cap counts only sign-ups that passed every check, so bad requests cannot use it up.
        $site    = fn () => $this->limiter->enforce($this->api->ratePolicy()->signUpSite(), $busy, false);
        $account = $this->api->accounts()->register($form, Actor::guest($request->ip()), true, true, $site);

        // No id and no Location: a taken e-mail must answer the same, and until the activation
        // link is opened the account's profile is not shown.
        return Response::ok(['confirmed' => $account['active']], 201);
    }
}
