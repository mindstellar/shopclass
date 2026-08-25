<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Mail-server provider slots.
 *
 * Live sending still uses the existing `mailserver_*` preferences. Each Server
 * type also has its own JSON slot (`mailserver_preset_{type}`) so that saving
 * Brevo does not erase Gmail or SMTP2GO. Factory host/port/encryption values
 * fill empty fields in memory; they are not written until Save.
 *
 * Pure helpers (normalize, sanitize, factory, merge) are safe to call from
 * characterization tests. Readers that hit preferences need a database.
 *
 * @package    Shopclass
 * @subpackage Helpers
 */

/**
 * @return string[]
 */
function osc_mailserver_allowed_types()
{
    return array('custom', 'gmail', 'brevo', 'smtp2go', 'ses');
}

/**
 * @param mixed $type
 *
 * @return string
 */
function osc_mailserver_normalize_type($type)
{
    $type = strtolower(trim((string)$type));
    if ($type === 'other') {
        return 'custom';
    }
    if ($type === 'aws' || $type === 'amazon' || $type === 'aws_ses') {
        return 'ses';
    }

    return in_array($type, osc_mailserver_allowed_types(), true) ? $type : 'custom';
}

/**
 * @return array<string,string>
 */
function osc_mailserver_empty_slot()
{
    return array(
        'host'      => '',
        'port'      => '',
        'username'  => '',
        'password'  => '',
        'ssl'       => '',
        'auth'      => '1',
        'pop'       => '',
        'mail_from' => '',
        'name_from' => '',
    );
}

/**
 * Typical host/port/encryption for a type. Never credentials or From addresses.
 *
 * @param mixed $type
 *
 * @return array<string,string>
 */
function osc_mailserver_type_defaults($type)
{
    $type     = osc_mailserver_normalize_type($type);
    $defaults = array(
        'gmail'   => array('host' => 'smtp.gmail.com', 'port' => '465', 'ssl' => 'ssl', 'auth' => '1', 'pop' => ''),
        'brevo'   => array('host' => 'smtp-relay.brevo.com', 'port' => '587', 'ssl' => 'tls', 'auth' => '1', 'pop' => ''),
        'smtp2go' => array('host' => 'mail.smtp2go.com', 'port' => '2525', 'ssl' => 'tls', 'auth' => '1', 'pop' => ''),
        'ses'     => array(
            'host' => 'email-smtp.us-east-1.amazonaws.com',
            'port' => '587',
            'ssl'  => 'tls',
            'auth' => '1',
            'pop'  => '',
        ),
        'custom'  => array('host' => '', 'port' => '', 'ssl' => '', 'auth' => '1', 'pop' => ''),
    );
    $slot = osc_mailserver_empty_slot();
    if (isset($defaults[$type])) {
        foreach ($defaults[$type] as $k => $v) {
            $slot[$k] = $v;
        }
    }

    return $slot;
}

/**
 * @param mixed $slot
 *
 * @return array<string,string>
 */
function osc_mailserver_sanitize_slot($slot)
{
    $out = osc_mailserver_empty_slot();
    if (!is_array($slot)) {
        return $out;
    }
    foreach ($out as $k => $v) {
        if (isset($slot[$k])) {
            $out[$k] = substr((string)$slot[$k], 0, ($k === 'password' || $k === 'username') ? 255 : 200);
        }
    }
    $out['ssl'] = strtolower(trim($out['ssl']));
    if ($out['ssl'] === 'starttls') {
        $out['ssl'] = 'tls';
    }
    $out['auth'] = ($out['auth'] !== '' && $out['auth'] !== '0') ? '1' : '';
    $out['pop']  = ($out['pop'] !== '' && $out['pop'] !== '0') ? '1' : '';

    return $out;
}

/**
 * @param mixed $type
 *
 * @return string
 */
function osc_mailserver_slot_pref_name($type)
{
    return 'mailserver_preset_' . osc_mailserver_normalize_type($type);
}

/**
 * @param mixed $raw
 *
 * @return array<string,string>|null
 */
function osc_mailserver_decode_slot($raw)
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $tmp = json_decode($raw, true);

    return is_array($tmp) ? osc_mailserver_sanitize_slot($tmp) : null;
}

/**
 * @param mixed $slot
 *
 * @return bool
 */
function osc_mailserver_slot_is_empty($slot)
{
    if (!is_array($slot)) {
        return true;
    }

    return ($slot['host'] === '' && $slot['username'] === '');
}

/**
 * Fill blank fields from the factory defaults for that type.
 *
 * @param mixed $slot
 * @param mixed $type
 *
 * @return array<string,string>
 */
function osc_mailserver_fill_factory($slot, $type)
{
    $factory = osc_mailserver_type_defaults($type);
    $slot    = osc_mailserver_sanitize_slot($slot);
    foreach ($factory as $k => $v) {
        if ($slot[$k] === '' && $v !== '') {
            $slot[$k] = $v;
        }
    }

    return $slot;
}

/**
 * Merge a posted map of slots into what is already stored.
 *
 * An empty posted slot for a type that is not the active one is ignored, so a
 * browser that omitted a provider (or sent blanks) cannot wipe a saved one.
 * The active type always takes the live form values.
 *
 * Pure: no database.
 *
 * @param array       $stored     type => slot
 * @param mixed       $posted     decoded JSON, or null
 * @param string      $activeType
 * @param array|mixed $activeSlot live form fields
 *
 * @return array<string,array<string,string>>
 */
function osc_mailserver_merge_posted_presets($stored, $posted, $activeType, $activeSlot)
{
    $activeType = osc_mailserver_normalize_type($activeType);
    $out        = array();
    foreach (osc_mailserver_allowed_types() as $type) {
        $out[$type] = isset($stored[$type]) && is_array($stored[$type])
            ? osc_mailserver_sanitize_slot($stored[$type])
            : osc_mailserver_empty_slot();
    }
    if (is_array($posted)) {
        foreach (osc_mailserver_allowed_types() as $type) {
            if (!isset($posted[$type]) || !is_array($posted[$type])) {
                continue;
            }
            $incoming    = osc_mailserver_sanitize_slot($posted[$type]);
            $keepPosted  = ($type === $activeType) || !osc_mailserver_slot_is_empty($incoming);
            if ($keepPosted) {
                $out[$type] = $incoming;
            }
        }
    }
    $out[$activeType] = osc_mailserver_sanitize_slot($activeSlot);

    return $out;
}

/**
 * @return array<string,array<string,string>>
 */
function osc_mailserver_read_stored()
{
    $out = array();
    foreach (osc_mailserver_allowed_types() as $type) {
        $slot       = osc_mailserver_decode_slot(getPreference(osc_mailserver_slot_pref_name($type)));
        $out[$type] = ($slot !== null) ? $slot : osc_mailserver_empty_slot();
    }

    return $out;
}

/**
 * @return array<string,string>
 */
function osc_mailserver_live_slot()
{
    return osc_mailserver_sanitize_slot(array(
        'host'      => (string)osc_mailserver_host(),
        'port'      => (string)osc_mailserver_port(),
        'username'  => (string)osc_mailserver_username(),
        'password'  => (string)osc_mailserver_password(),
        'ssl'       => (string)osc_mailserver_ssl(),
        'auth'      => osc_mailserver_auth() ? '1' : '',
        'pop'       => osc_mailserver_pop() ? '1' : '',
        'mail_from' => (string)osc_mailserver_mail_from(),
        'name_from' => (string)osc_mailserver_name_from(),
    ));
}

/**
 * @param mixed $type
 * @param mixed $slot
 *
 * @return bool|int
 */
function osc_mailserver_save_slot($type, $slot)
{
    return osc_set_preference(
        osc_mailserver_slot_pref_name($type),
        json_encode(osc_mailserver_sanitize_slot($slot))
    );
}

/**
 * Slots for the Mail Settings form: stored values, factory fill, live overlay.
 *
 * @return array<string,array<string,string>>
 */
function osc_mailserver_presets()
{
    $out = osc_mailserver_read_stored();
    foreach ($out as $type => $slot) {
        $out[$type] = osc_mailserver_fill_factory($slot, $type);
    }
    $active         = osc_mailserver_normalize_type(osc_mailserver_type());
    $out[$active]   = osc_mailserver_fill_factory(osc_mailserver_live_slot(), $active);

    return $out;
}

/**
 * @param mixed $presets
 *
 * @return bool
 */
function osc_mailserver_save_presets($presets)
{
    if (!is_array($presets)) {
        return false;
    }
    $ok = true;
    foreach (osc_mailserver_allowed_types() as $type) {
        if (isset($presets[$type]) && is_array($presets[$type])) {
            $saved = osc_mailserver_save_slot($type, $presets[$type]);
            $ok    = ($saved !== false) && $ok;
        }
    }

    return $ok;
}
