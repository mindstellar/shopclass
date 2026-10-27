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
 * The address form marks a refused field through the shared Form helper, not hand-written
 * markup, and the uploader only hides its file input once the script has taken over.
 *
 * DB-free.  Usage: php tests/address-form-errors.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

$attrs = Form::markInvalidWith('phone', 'Enter a phone.', array('id' => 'oe-addr-phone'));
pin('a refused field gets aria-invalid', 'true', $attrs['aria-invalid'] ?? '');
pin('it points at the -error id', 'oe-addr-phone-error', $attrs['aria-describedby'] ?? '');
pin('a fine field is left alone', array('id' => 'x'), Form::markInvalidWith('x', '', array('id' => 'x')));
pin(
    'the error line carries the same id',
    '<p class="oe-field-error" id="oe-addr-phone-error">Enter a phone.</p>',
    Form::errorLineWith('phone', 'Enter a phone.', $attrs)
);
pin('a fine field has no error line', '', Form::errorLineWith('phone', '', $attrs));

$view = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/gui/account/user-addresses-content.php');
pin('the address view writes no aria-invalid itself', false, str_contains($view, 'aria-invalid'));
pin('the address view uses the Form helper', true, str_contains($view, 'Form::markInvalidWith'));

$css = (string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/osc-uploader.css');
pin('the file input is hidden only inside an enhanced uploader', true, (bool) preg_match('/\.osc-uploader\.is-enhanced\s+\.osc-uploader-input\s*\{/', $css));
pin('no rule hides the bare file input', false, (bool) preg_match('/^\.osc-uploader-input\s*\{/m', $css));
$js = (string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/osc-uploader.js');
pin('the script marks the uploader enhanced', true, str_contains($js, "classList.add('is-enhanced')"));

exit(harness_result());
