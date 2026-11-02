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

namespace mindstellar\api;

use mindstellar\apikey\Credential;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use Params;

/**
 * Calls an API route from PHP in this process, as the page's own visitor: no HTTP, no CORS, no
 * rate limit, and it works while the API is switched off. A theme renders from it. A signed-in
 * user or admin only reads here, since a write needs the page token the browser sends.
 */
final class InternalClient
{
    /** @var \Closure(): Credential */
    private \Closure $caller;

    /**
     * @param \Closure(): Credential $caller who the calls act as, read on each call
     */
    public function __construct(private Kernel $kernel, \Closure $caller, private string $ip = '')
    {
        $this->caller = $caller;
    }

    /**
     * The client for this request, on the site's one kernel, acting as the signed-in admin on an
     * admin page, else the signed-in web user, else a visitor.
     */
    public static function site(): self
    {
        $services = ApiServices::site();
        $scopes   = $services->access()->scopes();

        return new self($services->kernel(), static function () use ($services, $scopes): Credential {
            $admin = null;
            if (defined('OC_ADMIN') && OC_ADMIN && osc_is_admin_user_logged_in()) {
                $admin = $services->admins()->find(osc_logged_admin_id());
            }
            $userId = $admin === null && osc_is_web_user_logged_in() ? (int) osc_logged_user_id() : 0;

            return self::callerFor($scopes, $userId, $admin);
        }, (string) Params::getServerParam('REMOTE_ADDR', false, false));
    }

    /**
     * The credential a call acts as: an admin's session when $admin is given, a user's session when
     * $userId is set, else a visitor who reads public data. A user's session never holds
     * account:write, as on the site's own pages.
     *
     * @param array<string,mixed>|null $admin the t_admin row of the admin signed in on an admin page
     */
    public static function callerFor(Scopes $scopes, int $userId, ?array $admin): Credential
    {
        if ($admin !== null) {
            $id        = (int) $admin['pk_i_id'];
            $moderator = !empty($admin['b_moderator']);

            return new Credential(
                CredentialKind::SESSION,
                $scopes->allowedFor(CredentialKind::SESSION, KeyOwner::admin($id, $moderator)),
                null,
                $id,
                moderator: $moderator
            );
        }
        if ($userId > 0) {
            return new Credential(CredentialKind::SESSION, $scopes->allowedFor(CredentialKind::SESSION, KeyOwner::user($userId)), $userId);
        }

        return Credential::anonymous(Scopes::PUBLIC);
    }

    /**
     * Answer one call.
     *
     * @param string              $path  below the version, e.g. `listings/12`
     * @param array<string,mixed> $query
     * @param array<mixed>        $body
     *
     * @return array{status:int,body:array<mixed>,headers:array<string,string>}
     */
    public function call(string $method, string $path, array $query = [], array $body = []): array
    {
        $caller = ($this->caller)();
        if ($caller->kind() === CredentialKind::SESSION && !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $response = Problem::make('wrong_credential', 'A signed-in write must come from the browser with its page token.');

            return ['status' => $response->status(), 'body' => $response->body() ?? [], 'headers' => $response->headers()];
        }
        $response = $this->kernel->handle(Request::internal($method, $path, $query, $body, $caller, $this->ip));

        return ['status' => $response->status(), 'body' => $response->body() ?? [], 'headers' => $response->headers()];
    }
}
