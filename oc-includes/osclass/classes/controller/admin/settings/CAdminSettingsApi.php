<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\admin\form\ApiSettingsScreen;
use mindstellar\admin\form\CoreSettings;
use mindstellar\api\ApiServices;
use mindstellar\api\auth\ApiKeyService;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\auth\IssuedKey;
use mindstellar\api\auth\KeyOwner;
use mindstellar\security\AdminReauth;
use mindstellar\security\AdminTwoFactor;
use mindstellar\webhook\WebhookService;

/**
 * Settings -> API: the API preferences, the admin and public keys, and the webhook endpoints.
 * Making or rotating a key or a webhook secret asks for the admin's password again; the new
 * key or secret is shown once, on the next page.
 */
class CAdminSettingsApi extends AdminSecBaseModel
{
    /** Session key holding a just-issued key until the page shows it. */
    public const ISSUED = 'apiKeyIssued';

    /** Seconds a just-issued key waits in the session to be shown before it is dropped. */
    public const ISSUED_TTL = 300;

    /** Session key holding a just-made webhook secret until the page shows it. */
    public const WEBHOOK_SECRET = 'apiWebhookSecret';

    public function doModel()
    {
        // Settings is closed to moderators, but a plugin can open it through moderator_access.
        if ($this->isModerator()) {
            osc_add_flash_error_message(_m("You don't have enough permissions"), 'admin');
            $this->redirectTo(osc_admin_base_url());

            return;
        }
        switch ($this->action) {
            case ('api_post'):
                osc_csrf_check();
                $result = CoreSettings::attempt(ApiSettingsScreen::register());
                if ($result['errors'] !== array()) {
                    $this->draw($result['values']);
                    break;
                }
                osc_add_flash_ok_message(_m('API settings have been updated'), 'admin');
                $this->redirectTo(self::url());
                break;
            case ('api_key_create'):
                osc_csrf_check();
                if (!$this->refuseOnDemo(self::url())) {
                    $this->createKey();
                }
                break;
            case ('api_key_rotate'):
                osc_csrf_check();
                if (!$this->refuseOnDemo(self::url())) {
                    $this->rotateKey();
                }
                break;
            case ('api_key_revoke'):
                osc_csrf_check();
                if (!$this->refuseOnDemo(self::url())) {
                    $this->revokeKey();
                }
                break;
            case ('api_webhook_create'):
            case ('api_webhook_update'):
            case ('api_webhook_toggle'):
            case ('api_webhook_rotate'):
            case ('api_webhook_test'):
            case ('api_webhook_delete'):
                osc_csrf_check();
                if (!$this->refuseOnDemo(self::url())) {
                    $this->webhookAction($this->action);
                }
                break;
            default:
                $this->draw();
                break;
        }
    }

    public static function url(): string
    {
        return osc_admin_base_url(true) . '?page=settings&action=api';
    }

    private function createKey(): void
    {
        $kind  = Params::getParamString('key_kind') === CredentialKind::PUBLIC ? CredentialKind::PUBLIC : CredentialKind::KEY;
        $input = array(
            'name'    => Params::getParamString('key_name'),
            'kind'    => $kind,
            // The scope boxes stay in the form while hidden; a public key gets the public read scope.
            'scopes'  => $kind === CredentialKind::PUBLIC ? array() : array_map('strval', Params::getParamArray('key_scopes')),
            'expires' => Params::getParamString('key_expires'),
        );
        $admin = $this->admin();
        if (!$this->reauthenticated($admin)) {
            $this->draw(null, $input);

            return;
        }
        try {
            $issued = $this->keys()->create(self::owner($admin), $input['name'], $input['kind'], $input['scopes'], $input['expires']);
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(osc_esc_html($e->getMessage()), 'admin');
            $this->draw(null, $input);

            return;
        }
        $this->handOff($issued, $this->keys()->nameOf($issued->id()));
        osc_add_flash_ok_message(_m('The key is made. Copy it now: it is not shown again.'), 'admin');
        $this->redirectTo(self::url());
    }

    private function rotateKey(): void
    {
        $admin = $this->admin();
        if (!$this->reauthenticated($admin)) {
            $this->redirectTo(self::url());

            return;
        }
        try {
            $issued = $this->keys()->rotate(Params::getParamInt('id'), (int) $admin['pk_i_id']);
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(osc_esc_html($e->getMessage()), 'admin');
            $this->redirectTo(self::url());

            return;
        }
        $this->handOff($issued, $this->keys()->nameOf($issued->id()));
        osc_add_flash_ok_message(_m('A new key is made. The old one works until you revoke it.'), 'admin');
        $this->redirectTo(self::url());
    }

    private function revokeKey(): void
    {
        if (!$this->reauthenticated($this->admin())) {
            $this->redirectTo(self::url());

            return;
        }
        try {
            $this->keys()->revoke(Params::getParamInt('id'));
            osc_add_flash_ok_message(_m('The key is revoked. Calls made with it are refused from now on.'), 'admin');
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(osc_esc_html($e->getMessage()), 'admin');
        }
        $this->redirectTo(self::url());
    }

    /**
     * The address of the screen with one webhook endpoint open for editing.
     */
    public static function webhookUrl(string $id): string
    {
        return self::url() . '&webhook=' . rawurlencode($id) . '#api-webhook-edit';
    }

    private function webhookAction(string $action): void
    {
        $id = Params::getParamString('webhook');
        switch ($action) {
            case 'api_webhook_create':
                $this->createWebhook();

                return;
            case 'api_webhook_rotate':
                if (!$this->reauthenticated($this->admin())) {
                    $this->redirectTo(self::url());

                    return;
                }
                $this->webhookStep(function () use ($id): string {
                    [$endpoint, $secret] = $this->webhooks()->rotate($id);
                    $this->handOffSecret($endpoint->url(), $secret);

                    return _m('A new secret is made. The old one also signs deliveries for 24 hours, so you can switch over.');
                }, self::url());

                return;
            case 'api_webhook_update':
                $this->webhookStep(function () use ($id): string {
                    $this->webhooks()->update(
                        $id,
                        Params::getParamString('webhook_url'),
                        array_map('strval', Params::getParamArray('webhook_events')),
                        Params::getParamString('webhook_description')
                    );

                    return _m('The webhook endpoint is saved.');
                }, self::webhookUrl($id));

                return;
            case 'api_webhook_toggle':
                $this->webhookStep(function () use ($id): string {
                    $on = Params::getParamString('enabled') === '1';
                    $this->webhooks()->update($id, null, null, null, $on);

                    return $on ? _m('The webhook endpoint is on.') : _m('The webhook endpoint is off. Nothing is sent to it until you switch it on.');
                }, self::url());

                return;
            case 'api_webhook_test':
                $this->webhookStep(function () use ($id): string {
                    $this->webhooks()->test($id);

                    return _m('A test event is queued. It is sent on the next run of the job queue.');
                }, self::webhookUrl($id));

                return;
            case 'api_webhook_delete':
                $this->webhookStep(function () use ($id): string {
                    $this->webhooks()->delete($id);

                    return _m('The webhook endpoint is deleted.');
                }, self::url());

                return;
        }
    }

    /**
     * Run one webhook change, flash its outcome and go back to the screen.
     *
     * @param callable(): string $step the message to flash on success
     */
    private function webhookStep(callable $step, string $back): void
    {
        try {
            osc_add_flash_ok_message($step(), 'admin');
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(osc_esc_html($e->getMessage()), 'admin');
        }
        $this->redirectTo($back);
    }

    private function createWebhook(): void
    {
        $input = array(
            'url'         => Params::getParamString('webhook_url'),
            'events'      => array_map('strval', Params::getParamArray('webhook_events')),
            'description' => Params::getParamString('webhook_description'),
            'enabled'     => Params::getParamString('webhook_enabled') === '1',
        );
        $admin = $this->admin();
        if (!$this->reauthenticated($admin)) {
            $this->draw(null, array(), $input);

            return;
        }
        try {
            [$endpoint, $secret] = $this->webhooks()->create($input['url'], $input['events'], $input['description'], $input['enabled'], (int) ($admin['pk_i_id'] ?? 0) ?: null);
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(osc_esc_html($e->getMessage()), 'admin');
            $this->draw(null, array(), $input);

            return;
        }
        $this->handOffSecret($endpoint->url(), $secret);
        osc_add_flash_ok_message(_m('The webhook endpoint is added. Copy its secret now: it is not shown again.'), 'admin');
        $this->redirectTo(self::url());
    }

    /**
     * The new webhook secret, kept for the next page only.
     */
    private function handOffSecret(string $url, string $secret): void
    {
        Session::newInstance()->_set(self::WEBHOOK_SECRET, array('secret' => $secret, 'url' => $url, 'at' => time()));
    }

    /**
     * @param array<string,mixed>|null $values settings a rejected save hands back
     * @param array<string,mixed>      $input  the new-key form as typed, after a refusal
     * @param array<string,mixed>      $hook   the new-webhook form as typed, after a refusal
     */
    private function draw(?array $values = null, array $input = array(), array $hook = array()): void
    {
        $admin   = $this->admin();
        $owner   = self::owner($admin);
        $keys    = $this->keys();
        $session = Session::newInstance();
        $issued  = $session->_get(self::ISSUED);
        $session->_drop(self::ISSUED);
        if (!is_array($issued) || time() - (int) ($issued['at'] ?? 0) > self::ISSUED_TTL) {
            $issued = null;
        }

        $this->_exportVariableToView('api_form', ApiSettingsScreen::formVars($values));
        $this->_exportVariableToView('api_keys', $keys->rows());
        $this->_exportVariableToView('api_scopes', $keys->grantable(CredentialKind::KEY, $owner));
        $this->_exportVariableToView('api_key_input', $input);
        $this->_exportVariableToView('api_key_issued', $issued);
        $this->_exportVariableToView('api_admin_id', (int) ($admin['pk_i_id'] ?? 0));
        $this->_exportVariableToView('api_reauth_2fa', $admin !== array() && AdminTwoFactor::enabled($admin));

        $secret = $session->_get(self::WEBHOOK_SECRET);
        $session->_drop(self::WEBHOOK_SECRET);
        if (!is_array($secret) || time() - (int) ($secret['at'] ?? 0) > self::ISSUED_TTL) {
            $secret = null;
        }
        $webhooks = $this->webhooks();
        $editing  = $webhooks->find(Params::getParamString('webhook'));
        $this->_exportVariableToView('api_webhooks', $webhooks->all());
        $this->_exportVariableToView('api_webhook_events', $webhooks->events()->all());
        $this->_exportVariableToView('api_webhook_input', $hook);
        $this->_exportVariableToView('api_webhook_secret', $secret);
        $this->_exportVariableToView('api_webhook_editing', $editing);
        $this->_exportVariableToView('api_webhook_deliveries', $editing === null ? array() : $webhooks->deliveries($editing->id()));
        $this->doView('settings/api.php');
    }

    /**
     * The new key, kept for the next page only.
     */
    private function handOff(IssuedKey $issued, string $name): void
    {
        Session::newInstance()->_set(self::ISSUED, array('token' => $issued->token(), 'name' => $name, 'at' => time()));
    }

    /**
     * Whether the password (and the 2FA code, when it is on) were typed right. Flashes why not.
     *
     * @param array<string,mixed> $admin
     */
    private function reauthenticated(array $admin): bool
    {
        $reason = $admin === array()
            ? _m("You don't have enough permissions")
            : AdminReauth::verify($admin, Params::getParamString('password', false, false), Params::getParamString('code'));
        if ($reason !== '') {
            osc_add_flash_error_message($reason, 'admin');
        }

        return $reason === '';
    }

    /**
     * @return array<string,mixed> the signed-in admin's row, or an empty array
     */
    private function admin(): array
    {
        $row = Admin::newInstance()->findByPrimaryKey(osc_logged_admin_id());

        return is_array($row) ? $row : array();
    }

    /**
     * @param array<string,mixed> $admin
     */
    private static function owner(array $admin): KeyOwner
    {
        return KeyOwner::admin((int) ($admin['pk_i_id'] ?? 0), !empty($admin['b_moderator']));
    }

    private function keys(): ApiKeyService
    {
        return ApiServices::site()->keyService();
    }

    private function webhooks(): WebhookService
    {
        return \mindstellar\webhook\WebhookServices::site()->service();
    }
}
