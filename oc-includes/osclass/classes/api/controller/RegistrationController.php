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
use mindstellar\user\AccountService;

/**
 * `POST /users`: sign up, when the site switches it on. AccountService::register() makes the
 * account as it does for the sign-up form, with the same checks, hooks and activation e-mail.
 * There is no captcha to show, so each address gets a few tries an hour and the site a cap,
 * both refusing when they cannot count.
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

        if (!$this->settings->registration() || !osc_users_enabled() || !osc_user_registration_enabled()) {
            throw ProblemException::of('feature_disabled', 'This site does not take sign-ups through the API.');
        }
        // Both limits stand in for a captcha, so they fail closed: no counter, no sign-up.
        $this->limiter->enforceAll($this->api->ratePolicy()->signUp($request->ip()), 'Too many sign-ups right now. Try again later.', false);

        $input = $request->input();
        $email = trim((string) ($input['email'] ?? ''));
        osc_run_hook('before_user_register');
        if (osc_is_banned($email, $request->ip()) !== 0) {
            throw ProblemException::of('banned', 'This e-mail address or your address may not sign up.');
        }

        $password = (string) ($input['password'] ?? '');
        $params   = [
            's_name'         => (string) ($input['name'] ?? ''),
            's_email'        => $email,
            's_password'     => $password,
            's_password2'    => $password,
            's_username'     => (string) ($input['username'] ?? ''),
            's_phone_land'   => (string) ($input['phone_land'] ?? ''),
            's_phone_mobile' => (string) ($input['phone_mobile'] ?? ''),
        ];
        $form    = \Params::withRequest($params, static fn (): array => AccountInput::signUp());
        $account = (new AccountService())->register($form, Actor::guest($request->ip()));

        // No Location: until the activation link is opened the account is not confirmed, and its
        // profile is not shown.
        return Response::ok(['id' => $account['id'], 'confirmed' => $account['active']], 201);
    }
}
