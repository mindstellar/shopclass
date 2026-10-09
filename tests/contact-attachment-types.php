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
 * Pins what a visitor may attach to a contact e-mail: pictures, PDF, text and office
 * documents whose name and content agree, no larger than the owner's limit.
 * DB-free.  Usage: php tests/contact-attachment-types.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\storage\UploadMimes;

$GLOBALS['prefs'] = array();
function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$key] ?? '';
}

$dir = sys_get_temp_dir() . '/osc-contact-attachment-' . getmypid();
@mkdir($dir);
$file = static function (string $name, string $bytes) use ($dir): string {
    file_put_contents($dir . '/' . $name, $bytes);

    return $dir . '/' . $name;
};

ob_start();
imagepng(imagecreatetruecolor(4, 4));
$png = $file('photo.png', (string) ob_get_clean());
$pdf = $file('offer.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$txt = $file('note.txt', "Hello, is the bike still for sale?\n");

harness_section('accepted');
check('a picture', UploadMimes::isAllowedAttachment($png, 'photo.png'));
check('a PDF', UploadMimes::isAllowedAttachment($pdf, 'offer.pdf'));
check('a text file', UploadMimes::isAllowedAttachment($txt, 'note.txt'));
check('an extension in capitals', UploadMimes::isAllowedAttachment($pdf, 'OFFER.PDF'));

harness_section('refused');
check('a picture named as a PDF', !UploadMimes::isAllowedAttachment($png, 'offer.pdf'));
check('a PDF named as a program', !UploadMimes::isAllowedAttachment($pdf, 'offer.exe'));
check('a file with no extension', !UploadMimes::isAllowedAttachment($pdf, 'offer'));
check('a web page named as text', !UploadMimes::isAllowedAttachment($file('page.txt', "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n"), 'page.txt'));
check('a PHP script named as text', !UploadMimes::isAllowedAttachment($file('run.txt', "<?php system(\$_GET['c']);\n"), 'run.txt'));
check('a Windows program', !UploadMimes::isAllowedAttachment($file('setup.exe', 'MZ' . str_repeat("\0", 200)), 'setup.exe'));
check('an empty file', !UploadMimes::isAllowedAttachment($file('empty.txt', ''), 'empty.txt'));
check('a file that is not there', !UploadMimes::isAllowedAttachment($dir . '/missing.pdf', 'missing.pdf'));

harness_section('the size limit');
pin('the limit is 5 MB until the owner sets one', 5, UploadMimes::attachmentMaxMb());
$GLOBALS['prefs']['attachment_max_mb'] = '0';
pin('a zero limit is read as 1 MB, never as no attachments', 1, UploadMimes::attachmentMaxMb());
$big = $file('big.txt', str_repeat("a line of text\n", 75000));
check('a file over the limit is refused', !UploadMimes::isAllowedAttachment($big, 'big.txt'));
$GLOBALS['prefs']['attachment_max_mb'] = '2';
check('and taken once the limit is raised', UploadMimes::isAllowedAttachment($big, 'big.txt'));

pin('the form offers the same extensions', true, in_array('docx', UploadMimes::attachmentExtensions(), true) && !in_array('exe', UploadMimes::attachmentExtensions(), true));

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
