<?php
if (!defined('ABS_PATH')) {
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

/**
 * The layout every HTML e-mail is sent in. A theme replaces it with its own
 * templates/email-layout.php; $mail holds what osc_mail_layout() passes, and 'body' is
 * HTML that is already safe to print.
 *
 * @var array<string,string> $mail
 */
$esc    = static fn ($v): string => osc_esc_html((string) $v);
$accent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($mail['accent'] ?? '')) ? $mail['accent'] : '#0b7269';
?>
<!DOCTYPE html>
<html lang="<?php echo $esc(substr((string) osc_current_user_locale(), 0, 2)); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?php echo $esc($mail['subject'] ?? ''); ?></title>
<style>
    body { margin: 0; padding: 0; background: #f4f5f7; }
    a { color: <?php echo $accent; ?>; }
    .oe-mail-body p { margin: 0 0 14px; }
    @media (prefers-color-scheme: dark) {
        body, .oe-mail-bg { background: #15181c !important; }
        .oe-mail-card { background: #1f2329 !important; border-color: #30353d !important; }
        .oe-mail-body, .oe-mail-brand { color: #e6e8eb !important; }
        .oe-mail-foot { color: #9aa1aa !important; }
    }
    @media (max-width: 620px) {
        .oe-mail-card { border-radius: 0 !important; }
        .oe-mail-pad { padding: 22px 18px !important; }
    }
</style>
</head>
<body>
<div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo $esc($mail['preheader'] ?? ''); ?></div>
<table role="presentation" class="oe-mail-bg" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                <tr>
                    <td class="oe-mail-brand" style="padding:0 4px 18px;font:700 20px/1.3 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#14181f;">
                        <a href="<?php echo $esc($mail['site_url'] ?? ''); ?>" style="color:inherit;text-decoration:none;">
                            <?php if (!empty($mail['logo_url'])) { ?>
                                <img src="<?php echo $esc($mail['logo_url']); ?>" alt="<?php echo $esc($mail['site_name'] ?? ''); ?>" height="36" style="display:block;height:36px;width:auto;border:0;">
                            <?php } else {
                                echo $esc($mail['site_name'] ?? '');
                            } ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <td class="oe-mail-card" style="background:#ffffff;border:1px solid #e3e6ea;border-top:4px solid <?php echo $accent; ?>;border-radius:10px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td class="oe-mail-pad oe-mail-body" style="padding:30px 32px;font:400 15px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2329;">
                                    <?php echo $mail['body'] ?? ''; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="oe-mail-foot" style="padding:18px 4px 0;font:400 12px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#6b7280;">
                        <?php echo $esc($mail['footer'] ?? ''); ?><br>
                        <a href="<?php echo $esc($mail['site_url'] ?? ''); ?>" style="color:#6b7280;"><?php
                            echo $esc(rtrim((string) preg_replace('#^https?://#', '', (string) ($mail['site_url'] ?? '')), '/')); ?></a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
