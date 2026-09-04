<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The one stylesheet behind every page core renders itself.
 *
 * Every selector is .oe-* prefixed on purpose. These rules can land inside a
 * theme's page -- a content partial rendered between the theme's own header and
 * footer -- so a bare `table` or `input` rule here would restyle the theme's
 * markup on the same page.
 *
 * The palette is a hand-copied snapshot of oc-admin/themes/modern/scss/_brand.scss.
 * Copied rather than imported because the card layout renders when the database
 * is unreachable and no build output can be assumed. Change the brand there and
 * copy the values across.
 *
 * $accent and $band are set by the caller (page.php) from the page's tone.
 */
?>
<style>
  .oe-page {
    --oe-bench-warm:#f7f9fb; --oe-bench:#fff; --oe-bench-sunk:#eef1f5;
    --oe-rule:#dde3ea; --oe-ink:#14181f; --oe-ink-muted:#5f6b7a;
    --oe-teal:#0b7269; --oe-teal-deep:#09625c;
    --oe-danger:#c22826; --oe-warning:#7a6716; --oe-success:#1d7d3e;
    --oe-accent:<?php echo $accent; ?>; --oe-band:<?php echo $band; ?>;
  }
  /* Typography only on the standalone shell, where .oe-page is the <body> and
     core owns the whole document. Inside a theme it is a <div>, and the theme's
     own type and text colour should carry through to the page core rendered. */
  body.oe-page {
    color:var(--oe-ink); line-height:1.55;
    font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
  }
  .oe-page *,.oe-page *::before,.oe-page *::after{box-sizing:border-box;}
  .oe-page a{color:var(--oe-teal);}
  .oe-page .oe-h1{font-size:1.5rem;font-weight:600;letter-spacing:-.01em;margin:0 0 20px;}
  .oe-page h2{font-size:1.0625rem;font-weight:600;margin:0 0 12px;}

  /* The strip that tells a visitor whose site this is. */
  .oe-page .oe-band{
    background:var(--oe-band); border-bottom:1px solid var(--oe-rule);
    padding:16px 32px; display:flex; align-items:center; gap:12px;
  }
  .oe-page .oe-band svg{flex:none;}
  .oe-page .oe-band-plain{background:var(--oe-bench);}
  .oe-page .oe-brand{font-weight:600;color:var(--oe-ink);letter-spacing:-.01em;text-decoration:none;}
  .oe-page .oe-brand span{color:var(--oe-teal);}
  .oe-page .oe-logo{max-height:28px;width:auto;display:block;}

  /* layout: card -- a system message. One centred column. */
  .oe-page .oe-card{
    background:var(--oe-bench); border:1px solid var(--oe-rule); border-radius:6px;
    box-shadow:0 1px 3px rgba(20,24,31,.06),0 8px 24px rgba(20,24,31,.05);
    max-width:640px; width:100%; overflow:hidden; margin:48px auto;
  }
  .oe-page .oe-card .oe-body{padding:32px;}
  .oe-page .oe-title{font-size:1.375rem;font-weight:600;letter-spacing:-.005em;margin:0 0 12px;}
  .oe-page .oe-lead{font-size:1rem;margin:0 0 20px;max-width:60ch;}
  .oe-page .oe-lead p{margin:0 0 12px;}
  .oe-page .oe-lead code{
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    background:var(--oe-bench-sunk);border-radius:4px;padding:2px 6px;font-size:.9em;
  }

  /* layout: document -- a real page. Wider column under the brand band. */
  .oe-page .oe-doc{max-width:880px;margin:0 auto;padding:32px 16px 64px;}

  /* Furniture. .oe-bill* are the names the billing partials already ship with:
     themes reach them through the registered render targets, so they stay. */
  .oe-page .oe-panel,.oe-page .oe-bill-card{
    background:var(--oe-bench);border:1px solid var(--oe-rule);border-radius:6px;
    padding:20px 24px;margin-bottom:20px;
  }
  .oe-page .oe-btn,.oe-page .oe-bill-btn,.oe-page .oe-lead a.button,.oe-page .oe-lead a.btn,.oe-page .oe-actions a.oe-primary,.oe-page .oe-actions a.oe-secondary{
    display:inline-block;text-decoration:none;border:1px solid var(--oe-teal);border-radius:4px;
    padding:9px 18px;font:inherit;font-size:.9375rem;font-weight:500;cursor:pointer;
    background:var(--oe-teal);color:#fff;
  }
  .oe-page .oe-btn:hover,.oe-page .oe-bill-btn:hover,.oe-page .oe-lead a.button:hover,.oe-page .oe-actions a.oe-primary:hover{
    background:var(--oe-teal-deep);
  }
  .oe-page .oe-btn-danger{background:var(--oe-danger);border-color:var(--oe-danger);}
  .oe-page .oe-actions,.oe-page .oe-bill-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px;}
  .oe-page .oe-actions a.oe-secondary{background:var(--oe-bench);color:var(--oe-ink);border-color:var(--oe-rule);}
  .oe-page .oe-actions a.oe-secondary:hover{border-color:var(--oe-ink-muted);}

  .oe-page .oe-field{margin:0 0 16px;}
  .oe-page .oe-label{display:block;font-size:.9375rem;font-weight:500;margin-bottom:6px;}
  .oe-page .oe-input{
    font:inherit;font-size:.9375rem;width:100%;max-width:320px;padding:8px 10px;
    background:var(--oe-bench);color:var(--oe-ink);border:1px solid var(--oe-rule);border-radius:4px;
  }
  .oe-page .oe-input:focus-visible{outline:2px solid var(--oe-teal);outline-offset:1px;}
  .oe-page fieldset{border:0;margin:0 0 20px;padding:0;}
  .oe-page legend{font-weight:600;font-size:1.0625rem;margin:0 0 12px;padding:0;}
  .oe-page input[type=radio],.oe-page input[type=checkbox]{accent-color:var(--oe-teal);}

  .oe-page .oe-table,.oe-page .oe-bill table{width:100%;border-collapse:collapse;font-size:.9375rem;}
  .oe-page .oe-table th,.oe-page .oe-table td,.oe-page .oe-bill th,.oe-page .oe-bill td{
    text-align:left;padding:10px 8px;border-bottom:1px solid var(--oe-bench-sunk);
  }
  .oe-page .oe-table th,.oe-page .oe-bill th{
    color:var(--oe-ink-muted);font-weight:500;font-size:.8125rem;
    text-transform:uppercase;letter-spacing:.02em;
  }
  .oe-page .oe-num,.oe-page .oe-bill-num{text-align:right;font-variant-numeric:tabular-nums;}
  .oe-page .oe-scroll{overflow-x:auto;}

  .oe-page .oe-muted,.oe-page .oe-bill-sub{color:var(--oe-ink-muted);margin:4px 0 0;font-size:.875rem;}
  .oe-page .oe-empty,.oe-page .oe-bill-empty{color:var(--oe-ink-muted);padding:24px 0;text-align:center;}
  .oe-pager,.oe-bill-pager{display:flex;justify-content:space-between;margin-top:16px;font-size:.875rem;}
  .oe-pager a,.oe-bill-pager a{text-decoration:none;}
  .oe-page .oe-figure,.oe-page .oe-bill-balance{font-size:2rem;font-weight:700;margin:0;}
  .oe-page .oe-figure.neg,.oe-page .oe-bill-balance.neg{color:var(--oe-danger);}

  .oe-page .oe-badge,.oe-page .oe-bill-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:500;}
  .oe-page .oe-badge.paid,.oe-page .oe-bill-badge.paid{background:#e4f8e7;color:var(--oe-success);}
  .oe-page .oe-badge.pending,.oe-page .oe-bill-badge.pending{background:#fdf4d2;color:var(--oe-warning);}
  .oe-page .oe-badge.failed,.oe-page .oe-badge.cancelled,.oe-page .oe-bill-badge.failed,.oe-page .oe-bill-badge.cancelled{
    background:#ffe9e5;color:var(--oe-danger);
  }
  .oe-page .oe-badge.refunded,.oe-page .oe-bill-badge.refunded{background:var(--oe-bench-sunk);color:var(--oe-ink-muted);}

  .oe-page .oe-choice,.oe-page .oe-bill-pkg,.oe-page .oe-bill-gw{
    border:1px solid var(--oe-rule);border-radius:6px;padding:14px 16px;margin-bottom:10px;
  }
  .oe-page .oe-choice label,.oe-page .oe-bill-pkg label,.oe-page .oe-bill-gw label{
    display:flex;align-items:center;gap:12px;width:100%;margin:0;cursor:pointer;font-weight:400;
  }
  .oe-page .oe-bill-pkg-name{font-weight:600;}
  .oe-page .oe-bill-pkg-credits{color:var(--oe-ink-muted);font-size:.875rem;}
  .oe-page .oe-bill-pkg-price{margin-left:auto;font-weight:600;white-space:nowrap;}
  .oe-page .oe-bill-instructions{white-space:pre-line;}

  .oe-page .oe-ref{font-size:.8125rem;color:var(--oe-ink-muted);margin:16px 0 0;}
  .oe-page .oe-ref code{
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    background:var(--oe-bench-sunk);border-radius:4px;padding:2px 6px;color:var(--oe-ink);
  }
  .oe-page .oe-detail{margin-top:24px;border-top:1px solid var(--oe-rule);padding-top:20px;}
  .oe-page .oe-detail-head{font-size:.8125rem;font-weight:500;color:var(--oe-accent);margin-bottom:10px;}
  .oe-page .oe-detail-msg{
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:.875rem;color:var(--oe-ink);margin:0 0 6px;word-break:break-word;
  }
  .oe-page .oe-detail-loc{font-size:.8125rem;color:var(--oe-ink-muted);margin:0 0 12px;word-break:break-all;}
  .oe-page .oe-trace{
    background:var(--oe-bench-sunk);border:1px solid var(--oe-rule);border-radius:4px;
    padding:12px 14px;margin:0;overflow-x:auto;
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:.8125rem;line-height:1.5;color:var(--oe-ink-muted);
  }
</style>
