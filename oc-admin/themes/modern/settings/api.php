<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\apiaccess\ApiKeyService;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\Scopes;
use mindstellar\webhook\Endpoint;

$form    = __get('api_form');
$keys    = (array) __get('api_keys');
$scopes  = (array) __get('api_scopes');
$input   = (array) __get('api_key_input');
$issued  = __get('api_key_issued');
$adminId = (int) __get('api_admin_id');
$twoStep = (bool) __get('api_reauth_2fa');

$webhooks     = (array) __get('api_webhooks');
$hookEvents   = (array) __get('api_webhook_events');
$hookInput    = (array) __get('api_webhook_input');
$hookSecret   = __get('api_webhook_secret');
$hookEditing  = __get('api_webhook_editing');
$hookEditing  = $hookEditing instanceof Endpoint ? $hookEditing : null;
$deliveries   = (array) __get('api_webhook_deliveries');
unset($hookEvents['ping']);

$kind    = ($input['kind'] ?? CredentialKind::KEY) === CredentialKind::PUBLIC ? CredentialKind::PUBLIC : CredentialKind::KEY;
$chosen  = (array) ($input['scopes'] ?? array());

$kindWords = array(
    'admin'  => __('Admin'),
    'user'   => __('User'),
    'public' => __('Public'),
);
$statusWords = array(
    ApiKeyService::STATUS_ACTIVE   => array('active', __('Active')),
    ApiKeyService::STATUS_REVOKED  => array('blocked', __('Revoked')),
    ApiKeyService::STATUS_EXPIRED  => array('expired', __('Expired')),
    ApiKeyService::STATUS_DISABLED => array('disabled', __('Disabled')),
    ApiKeyService::STATUS_ORPHANED => array('inactive', __('Owner gone')),
);
$when = static fn (?int $time): string => osc_admin_when($time === null ? null : date('Y-m-d H:i:s', $time));
$hookStates = array(
    Endpoint::STATUS_ACTIVE   => array('active', __('Active')),
    Endpoint::STATUS_PAUSED   => array('blocked', __('Paused')),
    Endpoint::STATUS_DISABLED => array('disabled', __('Off')),
);
$jobStates = array(
    'pending' => array('inactive', __('Waiting')),
    'running' => array('active', __('Sending')),
    'error'   => array('blocked', __('Gave up')),
);

/** The event checkboxes of the add and edit forms. */
$eventBoxes = static function (string $id, array $chosen) use ($hookEvents): void {
    echo '<div class="api-scopes" id="' . osc_esc_html($id) . '">';
    foreach ($hookEvents as $type => $spec) {
        osc_admin_checkbox(array(
            'name'       => 'webhook_events[]',
            'value'      => $type,
            'checked'    => in_array($type, $chosen, true),
            'label_html' => '<code class="osc-mono">' . osc_esc_html($type) . '</code> <span class="text-muted">' . osc_esc_html((string) $spec['description']) . '</span>',
        ));
    }
    echo '</div>';
};

/** A one-button form that posts a webhook action: action, label and hidden fields. */
$hookButton = static function (array $spec): void {
    echo '<form method="post" action="' . osc_esc_html(osc_admin_base_url(true)) . '" class="api-inline-form">'
        . '<input type="hidden" name="page" value="settings">'
        . '<input type="hidden" name="action" value="' . osc_esc_html($spec['action']) . '">';
    foreach ($spec['fields'] as $name => $value) {
        echo '<input type="hidden" name="' . osc_esc_html($name) . '" value="' . osc_esc_html($value) . '">';
    }
    osc_admin_action_button(array('label' => $spec['label'], 'variant' => 'dim', 'type' => 'submit'));
    echo '</form>';
};

/** The password box, plus the 2FA code box when it is on, for the rotate and revoke dialogs. */
$reauthFields = static function (string $prefix) use ($twoStep): string {
    $html = '<div class="api-reauth">'
        . '<div class="api-reauth-field"><label class="form-label" for="' . $prefix . '-password">' . osc_esc_html(__('Your password')) . '</label>'
        . '<input type="password" class="input-text" id="' . $prefix . '-password" name="password" autocomplete="current-password" required></div>';
    if ($twoStep) {
        $html .= '<div class="api-reauth-field"><label class="form-label" for="' . $prefix . '-code">' . osc_esc_html(__('Code from your app, or a backup code')) . '</label>'
            . '<input type="text" class="input-text two-factor-code" id="' . $prefix . '-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required></div>';
    }

    return $html . '</div>';
};

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('API'),
    'help'    => __('The REST API lets apps, scripts and other sites read and change your site\'s data. Keys say who is calling and what they may do.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="api-settings">
    <?php osc_admin_page_head(__('API')); ?>
    <p><?php printf(__('The API is served at %s.'), '<code>' . osc_esc_html(osc_api_url()) . '</code>'); ?></p>

    <?php if (is_array($issued) && !empty($issued['token'])) { ?>
        <div class="api-key-issued" id="api-key-issued">
            <div class="callout-warning callout-block">
                <?php echo osc_esc_html(sprintf(__('Your new key "%s". Copy it now and keep it somewhere safe: it is not shown again.'), (string) ($issued['name'] ?? ''))); ?>
            </div>
            <div class="api-key-token">
                <code class="osc-mono" id="api-key-token"><?php echo osc_esc_html((string) $issued['token']); ?></code>
                <button type="button" class="btn btn-dim btn-sm" data-api-copy="api-key-token"><?php _e('Copy'); ?></button>
            </div>
        </div>
    <?php } ?>

    <?php if (is_array($hookSecret) && !empty($hookSecret['secret'])) { ?>
        <div class="api-key-issued" id="api-webhook-secret">
            <div class="callout-warning callout-block">
                <?php echo osc_esc_html(sprintf(__('The signing secret of %s. Copy it now and keep it somewhere safe: it is not shown again.'), (string) ($hookSecret['url'] ?? ''))); ?>
            </div>
            <div class="api-key-token">
                <code class="osc-mono" id="api-webhook-secret-value"><?php echo osc_esc_html((string) $hookSecret['secret']); ?></code>
                <button type="button" class="btn btn-dim btn-sm" data-api-copy="api-webhook-secret-value"><?php _e('Copy'); ?></button>
            </div>
        </div>
    <?php } ?>

    <div id="api-general-settings">
        <?php osc_admin_settings_form($form['id'], $form); ?>
    </div>

    <div id="api-keys" class="separate-top">
        <?php osc_admin_form_section(__('Keys'), array(
            'intro' => __('Admin keys act as you, with the scopes you give them. Public keys only read public data and are safe to put in an app or a web page.'),
        )); ?>
        <?php if ($keys === array()) { ?>
            <p class="text-muted"><?php _e('No keys yet.'); ?></p>
        <?php } else { ?>
            <div class="table-contains-actions">
                <table class="table api-keys-table osc-table-stack" cellpadding="0" cellspacing="0">
                    <thead>
                    <tr>
                        <th><?php _e('Name'); ?></th>
                        <th><?php _e('Type'); ?></th>
                        <th><?php _e('Scopes'); ?></th>
                        <th><?php _e('Owner'); ?></th>
                        <th><?php _e('Created'); ?></th>
                        <th><?php _e('Last used'); ?></th>
                        <th><?php _e('Expires'); ?></th>
                        <th><?php _e('Status'); ?></th>
                        <th class="text-end"><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($keys as $key) {
                        [$state, $word] = $statusWords[$key['status']] ?? array('inactive', $key['status']);
                        $active    = $key['status'] === ApiKeyService::STATUS_ACTIVE;
                        $canRotate = $active && ($key['kind'] === 'public' || $key['owner_admin'] === $adminId); ?>
                        <tr>
                            <td data-col-name="<?php echo osc_esc_html(__('Name')); ?>">
                                <strong><?php echo osc_esc_html($key['name']); ?></strong>
                                <div class="text-muted osc-mono api-key-prefix"><?php echo osc_esc_html($key['prefix']); ?>&hellip;</div>
                            </td>
                            <td data-col-name="<?php echo osc_esc_html(__('Type')); ?>"><?php echo osc_esc_html($kindWords[$key['kind']] ?? $key['kind']); ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Scopes')); ?>">
                                <span class="api-key-scopes osc-mono"><?php echo osc_esc_html(implode(' ', $key['scopes'])); ?></span>
                            </td>
                            <td data-col-name="<?php echo osc_esc_html(__('Owner')); ?>"><?php echo $key['owner'] !== '' ? osc_esc_html($key['owner']) : '<span class="text-muted">&mdash;</span>'; ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Created')); ?>"><?php echo $when($key['created']); ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Last used')); ?>"><?php echo $key['last_used'] === null ? '<span class="text-muted">' . osc_esc_html(__('Never')) . '</span>' : $when($key['last_used']); ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Expires')); ?>"><?php echo $key['expires'] === null ? '<span class="text-muted">' . osc_esc_html(__('Never')) . '</span>' : $when($key['expires']); ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Status')); ?>"><?php osc_admin_status($state, $word); ?></td>
                            <td class="text-end">
                                <?php if ($active) { ?>
                                    <span class="api-key-actions">
                                        <?php if ($canRotate) {
                                            osc_admin_action_button(array(
                                                'label'   => __('Rotate'),
                                                'variant' => 'dim',
                                                'attrs'   => array('data-osc-dialog-open' => '#api-rotate-dialog', 'data-api-key' => $key['id']),
                                            ));
                                        }
                                        osc_admin_action_button(array(
                                            'label'   => __('Revoke'),
                                            'variant' => 'outline-danger',
                                            'attrs'   => array('data-osc-dialog-open' => '#api-revoke-dialog', 'data-api-key' => $key['id']),
                                        )); ?>
                                    </span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>

    <div id="api-key-new" class="separate-top">
        <?php osc_admin_form_section(__('New key')); ?>
        <?php osc_admin_form_open(array(
            'name'   => 'api_key_form',
            'page'   => 'settings',
            'action' => 'api_key_create',
        ));
        osc_admin_text(array(
            'name'     => 'key_name',
            'label'    => __('Name'),
            'value'    => (string) ($input['name'] ?? ''),
            'required' => true,
            'attrs'    => array('maxlength' => '100'),
            'help'     => __('What uses the key, such as "Mobile app" or "Stock sync".'),
        ));
        osc_admin_radio_group(array(
            'name'    => 'key_kind',
            'label'   => __('Type'),
            'value'   => $kind,
            'options' => array(
                CredentialKind::KEY    => __('Admin key: acts as you, with the scopes below'),
                CredentialKind::PUBLIC => __('Public key: reads public data only'),
            ),
        ));
        osc_admin_form_row_open(__('Scopes'), array('id' => 'api-key-scopes', 'class' => $kind === CredentialKind::PUBLIC ? 'd-none' : ''));
        echo '<div class="api-scopes">';
        foreach ($scopes as $scope => $description) {
            osc_admin_checkbox(array(
                'name'       => 'key_scopes[]',
                'value'      => $scope,
                'checked'    => in_array($scope, $chosen, true) || ($chosen === array() && $scope === Scopes::PUBLIC_READ),
                'label_html' => '<code class="osc-mono">' . osc_esc_html($scope) . '</code> <span class="text-muted">' . osc_esc_html($description) . '</span>',
            ));
        }
        echo '</div>';
        osc_admin_form_row_close();
        osc_admin_field(array(
            'type'  => 'custom',
            'name'  => 'key_expires',
            'label' => __('Expires'),
            'help'  => __('Optional. Leave empty for a key that works until you revoke it.'),
            'render' => static function () use ($input) {
                echo '<input type="date" class="input-text" id="key_expires" name="key_expires" min="' . date('Y-m-d', time() + 86400) . '"'
                    . ' value="' . osc_esc_html((string) ($input['expires'] ?? '')) . '">';
            },
        ));
        osc_admin_field(array(
            'type'     => 'secret',
            'name'     => 'password',
            'id'       => 'api-create-password',
            'label'    => __('Your password'),
            'required' => true,
            'help'     => __('Asked again before a key is made.'),
        ));
        if ($twoStep) {
            osc_admin_text(array(
                'name'     => 'code',
                'id'       => 'api-create-code',
                'label'    => __('Code from your app, or a backup code'),
                'required' => true,
                'width'    => 'num',
                'attrs'    => array('inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => '10'),
            ));
        }
        osc_admin_form_close(array(
            array('label' => __('Create key'), 'type' => 'submit'),
        )); ?>
    </div>

    <div id="api-webhooks" class="separate-top">
        <?php osc_admin_form_section(__('Webhooks'), array(
            'intro' => __('A webhook tells another system when something happens here, such as a new listing, by sending it a signed POST. A delivery that fails is tried again for about two hours; an endpoint that fails 8 times in a row is paused and you get an e-mail.'),
        )); ?>
        <?php if ($webhooks === array()) { ?>
            <p class="text-muted"><?php _e('No webhook endpoints yet.'); ?></p>
        <?php } else { ?>
            <div class="table-contains-actions">
                <table class="table api-webhooks-table osc-table-stack" cellpadding="0" cellspacing="0">
                    <thead>
                    <tr>
                        <th><?php _e('Address'); ?></th>
                        <th><?php _e('Events'); ?></th>
                        <th><?php _e('Last delivery'); ?></th>
                        <th><?php _e('Status'); ?></th>
                        <th class="text-end"><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($webhooks as $hook) {
                        if (!$hook instanceof Endpoint) {
                            continue;
                        }
                        [$state, $word] = $hookStates[$hook->status()]; ?>
                        <tr>
                            <td data-col-name="<?php echo osc_esc_html(__('Address')); ?>">
                                <strong class="osc-mono api-webhook-url"><?php echo osc_esc_html($hook->url()); ?></strong>
                                <?php if ($hook->description() !== '') { ?>
                                    <div class="text-muted"><?php echo osc_esc_html($hook->description()); ?></div>
                                <?php } ?>
                            </td>
                            <td data-col-name="<?php echo osc_esc_html(__('Events')); ?>">
                                <span class="api-key-scopes osc-mono"><?php echo osc_esc_html(implode(' ', $hook->events())); ?></span>
                            </td>
                            <td data-col-name="<?php echo osc_esc_html(__('Last delivery')); ?>">
                                <?php if ($hook->lastAttempt() === null) { ?>
                                    <span class="text-muted"><?php _e('Never'); ?></span>
                                <?php } else { ?>
                                    <span class="osc-mono"><?php echo osc_esc_html((string) $hook->lastStatus()); ?></span>
                                    <div class="text-muted"><?php echo $when($hook->lastAttempt()); ?></div>
                                <?php } ?>
                                <?php if ($hook->failures() > 0) { ?>
                                    <div class="text-muted"><?php echo osc_esc_html(sprintf(__('%d failed in a row'), $hook->failures())); ?></div>
                                <?php } ?>
                            </td>
                            <td data-col-name="<?php echo osc_esc_html(__('Status')); ?>"><?php osc_admin_status($state, $word); ?></td>
                            <td class="text-end">
                                <span class="api-key-actions">
                                    <?php
                                    osc_admin_action_button(array('label' => __('Edit'), 'variant' => 'dim', 'url' => CAdminSettingsApi::webhookUrl($hook->id())));
                                    $hookButton(array(
                                        'action' => 'api_webhook_test',
                                        'label'  => __('Send test'),
                                        'fields' => array('webhook' => $hook->id()),
                                    ));
                                    $hookButton(array(
                                        'action' => 'api_webhook_toggle',
                                        'label'  => $hook->enabled() ? __('Switch off') : __('Switch on'),
                                        'fields' => array('webhook' => $hook->id(), 'enabled' => $hook->enabled() ? '0' : '1'),
                                    ));
                                    osc_admin_action_button(array(
                                        'label'   => __('Rotate secret'),
                                        'variant' => 'dim',
                                        'attrs'   => array('data-osc-dialog-open' => '#api-webhook-rotate-dialog', 'data-api-webhook' => $hook->id()),
                                    ));
                                    osc_admin_action_button(array(
                                        'label'   => __('Delete'),
                                        'variant' => 'outline-danger',
                                        'attrs'   => array('data-osc-dialog-open' => '#api-webhook-delete-dialog', 'data-api-webhook' => $hook->id()),
                                    )); ?>
                                </span>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>

    <?php if ($hookEditing !== null) { ?>
        <div id="api-webhook-edit" class="separate-top">
            <?php osc_admin_form_section(sprintf(__('Edit %s'), $hookEditing->url())); ?>
            <?php if ($hookEditing->paused()) { ?>
                <div class="callout-warning callout-block api-webhook-paused">
                    <?php echo osc_esc_html(sprintf(__('Paused: %s. Switch it on again once the receiver works.'), (string) $hookEditing->pausedReason())); ?>
                </div>
            <?php } ?>
            <?php osc_admin_form_open(array(
                'name'   => 'api_webhook_edit_form',
                'page'   => 'settings',
                'action' => 'api_webhook_update',
            ));
            echo '<input type="hidden" name="webhook" value="' . osc_esc_html($hookEditing->id()) . '">';
            osc_admin_text(array(
                'name'     => 'webhook_url',
                'id'       => 'api-webhook-edit-url',
                'label'    => __('Address'),
                'value'    => $hookEditing->url(),
                'required' => true,
                'attrs'    => array('maxlength' => '2048'),
            ));
            osc_admin_text(array(
                'name'  => 'webhook_description',
                'id'    => 'api-webhook-edit-description',
                'label' => __('Description'),
                'value' => $hookEditing->description(),
                'attrs' => array('maxlength' => '255'),
            ));
            osc_admin_form_row_open(__('Events'));
            $eventBoxes('api-webhook-edit-events', $hookEditing->events());
            osc_admin_form_row_close();
            osc_admin_form_close(array(
                array('label' => __('Save endpoint'), 'type' => 'submit'),
            )); ?>

            <?php osc_admin_form_section(__('Deliveries waiting or given up')); ?>
            <?php if ($deliveries === array()) { ?>
                <p class="text-muted"><?php _e('None. Deliveries that got an answer leave the queue.'); ?></p>
            <?php } else { ?>
                <table class="table api-webhook-deliveries osc-table-stack" cellpadding="0" cellspacing="0">
                    <thead>
                    <tr>
                        <th><?php _e('Event'); ?></th>
                        <th><?php _e('Message'); ?></th>
                        <th><?php _e('Tries'); ?></th>
                        <th><?php _e('Last error'); ?></th>
                        <th><?php _e('Next try'); ?></th>
                        <th><?php _e('Status'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $delivery) {
                        [$state, $word] = $jobStates[$delivery['status']] ?? array('inactive', $delivery['status']); ?>
                        <tr>
                            <td data-col-name="<?php echo osc_esc_html(__('Event')); ?>"><code class="osc-mono"><?php echo osc_esc_html($delivery['type']); ?></code></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Message')); ?>"><span class="osc-mono api-key-prefix"><?php echo osc_esc_html($delivery['message_id']); ?></span></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Tries')); ?>"><?php echo (int) $delivery['attempts']; ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Last error')); ?>"><?php echo $delivery['last_error'] === null ? '<span class="text-muted">&mdash;</span>' : osc_esc_html($delivery['last_error']); ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Next try')); ?>"><?php echo $delivery['status'] === 'pending' ? $when($delivery['next_run']) : '<span class="text-muted">&mdash;</span>'; ?></td>
                            <td data-col-name="<?php echo osc_esc_html(__('Status')); ?>"><?php osc_admin_status($state, $word); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    <?php } ?>

    <div id="api-webhook-new" class="separate-top">
        <?php osc_admin_form_section(__('New webhook endpoint')); ?>
        <?php osc_admin_form_open(array(
            'name'   => 'api_webhook_form',
            'page'   => 'settings',
            'action' => 'api_webhook_create',
        ));
        osc_admin_text(array(
            'name'     => 'webhook_url',
            'label'    => __('Address'),
            'value'    => (string) ($hookInput['url'] ?? ''),
            'required' => true,
            'attrs'    => array('maxlength' => '2048', 'placeholder' => 'https://example.com/webhooks'),
            'help'     => __('Where the site sends each event, as an https address.'),
        ));
        osc_admin_text(array(
            'name'  => 'webhook_description',
            'label' => __('Description'),
            'value' => (string) ($hookInput['description'] ?? ''),
            'attrs' => array('maxlength' => '255'),
            'help'  => __('Optional, such as "Stock sync".'),
        ));
        osc_admin_form_row_open(__('Events'));
        $eventBoxes('api-webhook-events', (array) ($hookInput['events'] ?? array()));
        osc_admin_form_row_close();
        osc_admin_form_row_open(__('Status'));
        osc_admin_checkbox(array(
            'name'    => 'webhook_enabled',
            'value'   => '1',
            'checked' => $hookInput === array() || !empty($hookInput['enabled']),
            'label'   => __('Send events to it from now on'),
        ));
        osc_admin_form_row_close();
        osc_admin_field(array(
            'type'     => 'secret',
            'name'     => 'password',
            'id'       => 'api-webhook-password',
            'label'    => __('Your password'),
            'required' => true,
            'help'     => __('Asked again before an endpoint is added.'),
        ));
        if ($twoStep) {
            osc_admin_text(array(
                'name'     => 'code',
                'id'       => 'api-webhook-code',
                'label'    => __('Code from your app, or a backup code'),
                'required' => true,
                'width'    => 'num',
                'attrs'    => array('inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => '10'),
            ));
        }
        osc_admin_form_close(array(
            array('label' => __('Add endpoint'), 'type' => 'submit'),
        )); ?>
    </div>
</div>
<?php
osc_admin_confirm_dialog(array(
    'id'        => 'api-rotate-dialog',
    'tone'      => 'plain',
    'title'     => __('Make a new key in place of this one?'),
    'text'      => __('The new key gets the same type, scopes and expiry. The old key keeps working until you revoke it, so you can switch over first.'),
    'body_html' => $reauthFields('api-rotate'),
    'confirm'   => __('Rotate'),
    'fields'    => array('page' => 'settings', 'action' => 'api_key_rotate', 'id' => ''),
));
osc_admin_confirm_dialog(array(
    'id'        => 'api-revoke-dialog',
    'title'     => __('Revoke this key?'),
    'text'      => __('Every app or script using it stops working at once. This cannot be undone.'),
    'body_html' => $reauthFields('api-revoke'),
    'confirm'   => __('Revoke'),
    'fields'    => array('page' => 'settings', 'action' => 'api_key_revoke', 'id' => ''),
));

osc_admin_confirm_dialog(array(
    'id'        => 'api-webhook-rotate-dialog',
    'tone'      => 'plain',
    'title'     => __('Make a new signing secret for this endpoint?'),
    'text'      => __('For 24 hours each delivery carries two signatures, one with each secret, so the receiver can switch over.'),
    'body_html' => $reauthFields('api-webhook-rotate'),
    'confirm'   => __('Rotate secret'),
    'fields'    => array('page' => 'settings', 'action' => 'api_webhook_rotate', 'webhook' => ''),
));
osc_admin_confirm_dialog(array(
    'id'      => 'api-webhook-delete-dialog',
    'title'   => __('Delete this webhook endpoint?'),
    'text'    => __('Nothing more is sent to it, and deliveries still waiting are dropped. This cannot be undone.'),
    'confirm' => __('Delete'),
    'fields'  => array('page' => 'settings', 'action' => 'api_webhook_delete', 'webhook' => ''),
));

osc_add_hook('admin_footer', static function () { ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var root = document.getElementById('api-settings');
            if (!root) { return; }
            root.addEventListener('click', function (event) {
                var opener = event.target.closest('[data-api-key]');
                if (opener) {
                    var dialog = document.querySelector(opener.getAttribute('data-osc-dialog-open'));
                    dialog.querySelector('input[name="id"]').value = opener.getAttribute('data-api-key');
                }
                var hook = event.target.closest('[data-api-webhook]');
                if (hook) {
                    document.querySelector(hook.getAttribute('data-osc-dialog-open')).querySelector('input[name="webhook"]').value = hook.getAttribute('data-api-webhook');
                }
                var copy = event.target.closest('[data-api-copy]');
                if (copy && navigator.clipboard) {
                    navigator.clipboard.writeText(document.getElementById(copy.getAttribute('data-api-copy')).textContent).then(function () {
                        var label = copy.textContent;
                        copy.textContent = <?php echo json_encode(__('Copied'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                        setTimeout(function () { copy.textContent = label; }, 1500);
                    });
                }
            });
            var scopes = document.getElementById('api-key-scopes');
            document.querySelectorAll('input[name="key_kind"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    scopes.classList.toggle('d-none', radio.checked && radio.value === <?php echo json_encode(CredentialKind::PUBLIC, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
                });
            });
        });
    </script>
<?php }, 10);

osc_current_admin_theme_path('parts/footer.php');
