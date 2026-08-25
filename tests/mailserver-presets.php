<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Mail Settings used to keep one live mailserver_* row. Saving Gmail therefore
 * erased Custom (and there was no slot for Brevo or SES). The merge helper is
 * the invariant: an empty posted slot for a type that is not being saved must
 * not replace what is already stored.
 *
 * DB-free. Usage:  php tests/mailserver-presets.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hMailserver.php';

harness_section('type names');
pin('custom stays', 'custom', osc_mailserver_normalize_type('custom'));
pin('other is custom', 'custom', osc_mailserver_normalize_type('other'));
pin('gmail', 'gmail', osc_mailserver_normalize_type('Gmail'));
pin('aws aliases ses', 'ses', osc_mailserver_normalize_type('aws_ses'));
pin('unknown falls back to custom', 'custom', osc_mailserver_normalize_type('sendgrid'));
pin('pref name', 'mailserver_preset_gmail', osc_mailserver_slot_pref_name('gmail'));

harness_section('factory defaults have hosts, never credentials');
$brevo = osc_mailserver_type_defaults('brevo');
pin('brevo host', 'smtp-relay.brevo.com', $brevo['host']);
pin('brevo port', '587', $brevo['port']);
pin('brevo username empty', '', $brevo['username']);
pin('brevo password empty', '', $brevo['password']);
$ses = osc_mailserver_type_defaults('ses');
pin('ses host is a region placeholder', 'email-smtp.us-east-1.amazonaws.com', $ses['host']);
pin('ses port 587', '587', $ses['port']);
$gmail = osc_mailserver_type_defaults('gmail');
pin('gmail matches the previous admin fill', 'smtp.gmail.com', $gmail['host']);
pin('gmail port 465', '465', $gmail['port']);
pin('gmail ssl', 'ssl', $gmail['ssl']);

harness_section('sanitize');
$cleaned = osc_mailserver_sanitize_slot(array(
    'host'     => 'mail.example.com',
    'ssl'      => 'STARTTLS',
    'auth'     => '0',
    'pop'      => 'yes',
    'username' => 'user',
));
pin('starttls becomes tls', 'tls', $cleaned['ssl']);
pin('auth zero is off', '', $cleaned['auth']);
pin('pop truthy is on', '1', $cleaned['pop']);
check('empty slot', osc_mailserver_slot_is_empty(osc_mailserver_empty_slot()));
check('host counts as filled', !osc_mailserver_slot_is_empty($cleaned));

harness_section('factory fill leaves saved host alone');
$filled = osc_mailserver_fill_factory(
    array('host' => 'smtp-relay.brevo.com', 'port' => '2525', 'ssl' => '', 'username' => 'abc'),
    'brevo'
);
pin('saved port kept', '2525', $filled['port']);
pin('blank ssl filled', 'tls', $filled['ssl']);
pin('saved username kept', 'abc', $filled['username']);

harness_section('saving brevo does not wipe stored smtp2go');
$stored = array(
    'custom'  => osc_mailserver_empty_slot(),
    'gmail'   => osc_mailserver_empty_slot(),
    'brevo'   => osc_mailserver_empty_slot(),
    'smtp2go' => osc_mailserver_sanitize_slot(array(
        'host'     => 'mail.smtp2go.com',
        'port'     => '2525',
        'username' => 'site.example',
        'password' => 'secret',
        'ssl'      => 'tls',
    )),
    'ses'     => osc_mailserver_empty_slot(),
);
$posted = array(
    'brevo'   => array(
        'host'     => 'smtp-relay.brevo.com',
        'port'     => '587',
        'username' => 'brevo-user',
        'password' => 'brevo-pass',
        'ssl'      => 'tls',
        'auth'     => '1',
    ),
    'smtp2go' => osc_mailserver_empty_slot(),
);
$merged = osc_mailserver_merge_posted_presets(
    $stored,
    $posted,
    'brevo',
    array(
        'host'     => 'smtp-relay.brevo.com',
        'port'     => '587',
        'username' => 'brevo-user',
        'password' => 'brevo-pass',
        'ssl'      => 'tls',
        'auth'     => '1',
    )
);
pin('brevo username saved', 'brevo-user', $merged['brevo']['username']);
pin('smtp2go username kept', 'site.example', $merged['smtp2go']['username']);
pin('smtp2go password kept', 'secret', $merged['smtp2go']['password']);

harness_section('decode rejects junk');
check('empty raw is null', osc_mailserver_decode_slot('') === null);
check('invalid json is null', osc_mailserver_decode_slot('{not json') === null);
$decoded = osc_mailserver_decode_slot('{"host":"mail.example.com","username":"a"}');
pin('decoded host', 'mail.example.com', $decoded['host']);

exit(harness_result());
