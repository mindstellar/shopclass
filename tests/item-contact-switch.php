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
 * Pins the contact form's switch to on-site messaging: guarded by osc_item_uses_messaging(),
 * after the captcha, before the e-mail path, and the e-mail path left whole.
 *
 * DB-free and source-level.  Usage: php tests/item-contact-switch.php
 */

require_once __DIR__ . '/lib/harness.php';

$file = __DIR__ . '/../oc-includes/osclass/classes/controller/CWebItem.php';
$body = harness_method_source($file, 'contactPost');
$conv = harness_method_source($file, 'startConversation');

harness_section('contactPost');
$captcha = strpos($body, 'Captcha::passes()');
$guard   = strpos($body, 'if (osc_item_uses_messaging())');
$mail    = strpos($body, 'contactSeller(');
check('the method was parsed', $body !== '');
check('the switch is guarded by osc_item_uses_messaging()', $guard !== false);
check('the switch sits after the captcha', $captcha !== false && $guard !== false && $guard > $captcha);
check('the switch sits before the e-mail path', $guard !== false && $mail !== false && $guard < $mail);
check('the e-mail path is still there', $mail !== false);
check('its three catch blocks are still there', substr_count($body, 'catch (ConflictException') === 1
    && substr_count($body, 'catch (InvalidException') === 1 && substr_count($body, 'catch (RefusedException') === 1);
check('CSRF is checked first', strpos($body, 'osc_csrf_check()') < $captcha);
check('no untyped read of the message', strpos($body . $conv, "getParam('message'") === false);
check('the thread gets the message raw, as the inbox reply reads it', strpos($body, "Params::getParamString('message', false, false, false)") !== false);

harness_section('startConversation');
check('the method was parsed', $conv !== '');
check('it starts the thread through MessagingService', strpos($conv, 'MessagingService::class') !== false && strpos($conv, '->start(') !== false);
check('it acts as the visitor', strpos($conv, 'Actor::visitor()') !== false);
check('it lands on the thread', strpos($conv, 'osc_messaging_thread_url(') !== false);
check('a rate limit uses the shared wording', strpos($conv, 'osc_message_throttle_message($e->retryAfter())') !== false);
check('a rate limit and a bad body go back to the form', strpos($conv, 'catch (BlockedException') !== false && strpos($conv, 'catch (InvalidException') !== false);

exit(harness_result());
