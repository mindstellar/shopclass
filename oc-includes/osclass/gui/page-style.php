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
 * The one stylesheet behind every page core renders itself.
 *
 * Every selector is .oe-* prefixed on purpose. These rules can land inside a
 * theme's page -- a content partial rendered between the theme's own header and
 * footer -- so a bare `table` or `input` rule here would restyle the theme's
 * markup on the same page.
 *
 * Two things make these rules defaults rather than an obstacle, and a theme
 * should never have to know which selector core happened to write:
 *
 * 1. Everything is in `@layer shopclass`. A layered rule loses to any unlayered
 *    rule at any specificity, so a theme overrides `.oe-btn` by writing
 *    `.oe-btn {}` once -- no `!important`, and no need to mirror core's own
 *    selector shape. A theme that says nothing still gets these defaults.
 *    This is the whole-sheet form of what item-comments-style.php does per rule
 *    with :where().
 * 2. The component vocabulary -- buttons, actions, fields -- is not scoped under
 *    .oe-page, because core emits it outside .oe-page too: osc_alert_form()
 *    renders into a theme's own search page. Scoped, those controls fell back to
 *    the user agent. The .oe-* prefix, not the ancestor, is what keeps these
 *    rules off a theme's markup. Shell rules and anything targeting a bare
 *    element keep the .oe-page ancestor and must keep it.
 *
 * The palette is a hand-copied snapshot of oc-admin/themes/modern/scss/_brand.scss.
 * Copied rather than imported because the card layout renders when the database
 * is unreachable and no build output can be assumed. Change the brand there and
 * copy the values across.
 *
 * $accent and $band are set by the caller (page.php) from the page's tone.
 */

/**
 * @var string $accent
 * @var string $band
 */
?>
<style>
@layer shopclass {
  :root {
    --oe-bench-warm:#f7f9fb; --oe-bench:#fff; --oe-bench-sunk:#eef1f5;
    --oe-rule:#dde3ea; --oe-ink:#14181f; --oe-ink-muted:#5f6b7a;
    --oe-teal:#0b7269; --oe-teal-deep:#09625c;
    --oe-on-teal:#fff; --oe-scrim:rgba(0,0,0,.45);
    --oe-danger:#c22826; --oe-danger-deep:#9b201e; --oe-warning:#7a6716; --oe-success:#1d7d3e;
    --oe-accent:<?php echo $accent; ?>; --oe-band:<?php echo $band; ?>;
  }
  body.oe-page {
    color:var(--oe-ink); line-height:1.55;
    font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
  }
  .oe-page *,.oe-page *::before,.oe-page *::after{box-sizing:border-box;}
  body.oe-page a,body.oe-page .oe-link-btn{color:var(--oe-teal);}
  .oe-page .oe-h1{font-size:1.5rem;font-weight:600;letter-spacing:-.01em;margin:0 0 20px;overflow-wrap:anywhere;}
  .oe-page h2{font-size:1.0625rem;font-weight:600;margin:0 0 12px;}
  .oe-page .oe-panel__title{font-size:1.0625rem;font-weight:600;}
  .oe-page .oe-band{
    background:var(--oe-band); border-bottom:1px solid var(--oe-rule);
    padding:16px 32px; display:flex; align-items:center; gap:12px;
  }
  .oe-page .oe-band svg{flex:none;}
  .oe-page .oe-band-plain{background:var(--oe-bench);}
  .oe-page .oe-brand{font-weight:600;color:var(--oe-ink);letter-spacing:-.01em;text-decoration:none;}
  .oe-page .oe-brand span{color:var(--oe-teal);}
  .oe-page .oe-logo{max-height:28px;width:auto;display:block;}
  .oe-page .oe-panel,.oe-page .oe-bill-card{
    background:var(--oe-bench);border:1px solid var(--oe-rule);border-radius:6px;
    padding:20px 24px;margin-bottom:20px;
  }
  .oe-page .oe-card{
    background:var(--oe-bench); border:1px solid var(--oe-rule); border-radius:6px;
    box-shadow:0 1px 3px rgba(20,24,31,.06),0 8px 24px rgba(20,24,31,.05);
    max-width:640px; width:100%; overflow:hidden; margin:48px auto; padding:0;
  }
  .oe-page .oe-card .oe-body{padding:32px;}
  .oe-page .oe-title{font-size:1.375rem;font-weight:600;letter-spacing:-.005em;margin:0 0 12px;}
  .oe-page .oe-lead{font-size:1rem;margin:0 0 20px;max-width:60ch;}
  .oe-page .oe-lead p{margin:0 0 12px;}
  .oe-page .oe-lead code{
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    background:var(--oe-bench-sunk);border-radius:4px;padding:2px 6px;font-size:.9em;
  }
  .oe-page .oe-doc{max-width:880px;margin:0 auto;padding:32px 16px 64px;}
  .oe-btn,.oe-btn--primary,.oe-bill-btn,.oe-lead a.button,.oe-lead a.btn,.oe-actions a.oe-primary,.oe-actions a.oe-secondary{
    display:inline-block;text-decoration:none;border:1px solid var(--oe-teal);border-radius:4px;
    padding:9px 18px;font:inherit;font-size:.9375rem;font-weight:500;cursor:pointer;
    background:var(--oe-teal);color:#fff;
    background-image:none;text-shadow:none;box-shadow:none;
  }
  .oe-btn:hover,.oe-btn--primary:hover,.oe-bill-btn:hover,.oe-lead a.button:hover,.oe-actions a.oe-primary:hover{
    background:var(--oe-teal-deep);
  }
  .oe-btn-danger,.oe-btn.oe-btn-danger{
    background:var(--oe-danger);background-image:none;border-color:var(--oe-danger);color:#fff;
  }
  .oe-btn-danger:hover,.oe-btn.oe-btn-danger:hover{background:var(--oe-danger-deep,#9b201e);}
  .oe-actions,.oe-bill-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px;}
  .oe-actions a.oe-secondary,.oe-btn.oe-secondary{
    background:var(--oe-bench);color:var(--oe-ink);border-color:var(--oe-rule);
  }
  .oe-actions a.oe-secondary:hover,.oe-btn.oe-secondary:hover{border-color:var(--oe-ink-muted);}
  .oe-field{margin:0 0 16px;}
  .oe-label{display:block;font-size:.9375rem;font-weight:500;margin-bottom:6px;}
  .oe-input{
    font:inherit;font-size:.9375rem;width:100%;max-width:320px;padding:8px 10px;
    background:var(--oe-bench);color:var(--oe-ink);border:1px solid var(--oe-rule);border-radius:4px;
  }
  .oe-input:focus-visible{outline:2px solid var(--oe-teal);outline-offset:1px;}
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
  @media (max-width:40rem){
    .oe-page .oe-bill thead{position:absolute;inline-size:1px;block-size:1px;overflow:hidden;clip-path:inset(50%);}
    .oe-page .oe-bill :is(table,tbody,tr,td){display:block;}
    .oe-page .oe-bill tr{padding-block:8px;border-block-end:1px solid var(--oe-rule);}
    .oe-page .oe-bill td{display:flex;justify-content:space-between;gap:16px;padding:4px 0;border:0;text-align:end;}
    .oe-page .oe-bill td[data-label]::before{content:attr(data-label);color:var(--oe-ink-muted);font-size:.8125rem;text-align:start;}
  }
  .oe-muted,.oe-bill-sub{color:var(--oe-ink-muted);margin:4px 0 0;font-size:.875rem;}
  .oe-page .oe-empty,.oe-page .oe-bill-empty{color:var(--oe-ink-muted);padding:24px 0;text-align:center;}
  .oe-page .oe-pager,.oe-page .oe-bill-pager{display:flex;justify-content:space-between;margin-top:16px;font-size:.875rem;}
  .oe-page .oe-pager a,.oe-page .oe-bill-pager a{text-decoration:none;}
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
  .oe-page .oe-account{
    display:grid; gap:32px; align-items:start;
    grid-template-columns:minmax(0,1fr) minmax(13rem,16rem);
  }
  .oe-page .oe-account > *{min-inline-size:0;}
  .oe-page .oe-account-main > :last-child{margin-block-end:0;}
  @media (max-width:60rem){.oe-page .oe-account{grid-template-columns:minmax(0,1fr);gap:28px;}}
  .oe-page .oe-account-nav h2{
    font-size:.8125rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase;
    color:var(--oe-ink-muted); margin:0 0 8px; padding-block-end:8px;
    border-block-end:1px solid var(--oe-rule);
  }
  .oe-page .oe-account-nav ul{list-style:none;margin:0;padding:0;}
  .oe-page .oe-account-nav a{
    display:block; padding:8px 10px; margin-inline:-10px; border-radius:4px;
    text-decoration:none; color:inherit;
  }
  .oe-page .oe-account-nav a:hover{background:var(--oe-bench-sunk);text-decoration:underline;}
  .oe-page .oe-account-nav [aria-current]{background:var(--oe-bench-sunk);font-weight:600;}
  .oe-page .flashmessage{
    border:1px solid var(--oe-rule); border-radius:6px; background:var(--oe-bench);
    padding:12px 16px; margin:0 0 20px;
  }
  .oe-page .flashmessage-ok{background:#e4f8e7;border-color:var(--oe-success);}
  .oe-page .flashmessage-error{background:#ffe9e5;border-color:var(--oe-danger);}
  .oe-page .flashmessage-warning{background:#fdf4d2;border-color:var(--oe-warning);}
  .oe-page .flashmessage-info{background:#e6f6f4;border-color:var(--oe-teal);}
  .oe-page .flashmessage > :last-child{margin-block-end:0;}
  .oe-page .oe-list{list-style:none;margin:0;padding:0;}
  .oe-page .oe-list-item{
    display:flex; flex-wrap:wrap; align-items:flex-start; gap:8px 16px;
    padding:14px 0; border-block-end:1px solid var(--oe-bench-sunk);
  }
  .oe-page .oe-list-item > .oe-list-body{flex:1 1 16rem;min-inline-size:0;}
  .oe-page .oe-list-item h2,.oe-page .oe-list-item h3{margin:0;font-size:1.0625rem;overflow-wrap:anywhere;}
  .oe-page .oe-meta{
    display:flex; flex-wrap:wrap; align-items:center; gap:4px 10px;
    color:var(--oe-ink-muted); font-size:.875rem; margin:6px 0 0;
  }
  .oe-page .oe-row-actions{margin-block-start:4px;}
  .oe-page .oe-dashboard-intro{margin-block-end:12px;}
  .oe-page .oe-dashboard-actions{margin-block-end:24px;}
  .oe-page .oe-group{border:0;padding:0;margin:0 0 28px;min-inline-size:0;}
  .oe-page .oe-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,14rem),1fr));gap:0 16px;}
  .oe-page .oe-avatar-field{display:flex;flex-wrap:wrap;align-items:flex-start;gap:16px;}
  .oe-page .oe-avatar-empty{opacity:.7;}
  .oe-page .oe-dashboard-title{margin-block-start:8px;}
  .oe-page .oe-dashboard-more{margin-block-start:12px;}
  .oe-page .oe-danger-link{color:var(--oe-danger);}
  .oe-page .oe-row-promote{font-size:.8125rem;}
  .oe-page .oe-inline-form{display:inline;margin:0;}
  .oe-page .oe-link-btn{font:inherit;color:inherit;background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;}
  .oe-page .oe-link-btn.oe-danger-link{color:var(--oe-danger);}
  .oe-page .oe-tabs{display:flex;flex-wrap:wrap;gap:4px;margin:0 0 16px;border-block-end:1px solid var(--oe-rule);}
  .oe-page .oe-tabs a{padding:8px 12px;text-decoration:none;color:var(--oe-ink-muted);border-block-end:2px solid transparent;margin-block-end:-1px;}
  .oe-page .oe-tabs a:hover{color:inherit;}
  .oe-page .oe-tabs [aria-current]{color:inherit;font-weight:600;border-block-end-color:currentColor;}
  .oe-page .oe-danger{margin-block-start:3rem;padding-block-start:1.5rem;border-block-start:1px solid var(--oe-rule);}
  .oe-page .oe-danger h2{color:var(--oe-danger);}
  .oe-page .oe-profile-head{margin-block-end:1.5rem;}
  .oe-page .oe-contact-open{white-space:nowrap;}
  .oe-page .oe-field-error{color:var(--oe-danger);}
  .oe-page .oe-address-lines{margin:6px 0 0;overflow-wrap:anywhere;}
  .oe-page .oe-address h2 .oe-badge{vertical-align:middle;margin-inline-start:6px;}
  .oe-page .oe-form-error{margin:0 0 16px;padding:10px 14px;border:1px solid var(--oe-danger);border-radius:6px;background:#ffe9e5;color:var(--oe-danger);}
  .oe-page .oe-dialog:not([data-osc-dialog-ready]){display:block;position:static;inset:auto;margin:1.5rem 0 0;padding:0;border:0;max-inline-size:none;background:none;color:inherit;}
  .oe-page .oe-dialog:not([data-osc-dialog-ready]) .oe-dialog-close{display:none;}
  .oe-dialog[data-osc-dialog-ready]{inline-size:min(34rem,calc(100% - 32px));padding:0;border:1px solid var(--oe-rule);border-radius:8px;background:var(--oe-bench,#fff);color:inherit;}
  .oe-dialog[data-osc-dialog-ready]::backdrop{background:var(--oe-scrim);}
  .oe-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-block-end:1px solid var(--oe-rule);}
  .oe-dialog-head h2{margin:0;font-size:1.0625rem;}
  .oe-dialog-close{font:inherit;font-size:1.5rem;line-height:1;background:none;border:0;padding:4px;cursor:pointer;color:inherit;}
  .oe-dialog-body{padding:16px 20px 20px;}
  .oe-page .oe-avatar{display:block;inline-size:96px;block-size:96px;object-fit:cover;border-radius:50%;background:var(--oe-bench-sunk);margin-block-end:.6rem;}
  .oe-page .oe-thumb{
    flex:none; display:block; inline-size:5.5rem; block-size:auto; aspect-ratio:6/5;
    object-fit:cover; background:var(--oe-bench-sunk);
    border:1px solid var(--oe-rule); border-radius:4px;
  }
  .oe-page .oe-thumb-empty{
    display:grid; place-items:center; text-align:center; padding:4px;
    border-style:dashed; color:var(--oe-ink-muted); font-size:.75rem;
  }
  .oe-page .oe-price{
    flex:none; margin:0; font-weight:600; font-size:1.0625rem;
    font-variant-numeric:tabular-nums; margin-inline-start:auto; text-align:end;
  }
  .oe-page .oe-form-page{max-inline-size:30rem;}
  .oe-page .oe-contact{display:grid;gap:32px;align-items:start;grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr));}
  .oe-page .oe-contact-main{max-inline-size:30rem;min-inline-size:0;}
  .oe-page #error_list{list-style:none;margin:0;padding:0;}
  .oe-page #error_list:not(:empty){
    background:#ffe9e5; border:1px solid var(--oe-danger); border-radius:6px;
    margin:0 0 20px; padding:12px 16px; padding-inline-start:32px; list-style:disc;
  }
  .oe-hint{display:block;font-size:.8125rem;color:var(--oe-ink-muted);margin-block-start:6px;}
  .oe-check{display:flex;align-items:center;gap:10px;margin:0 0 16px;font-size:.9375rem;}
  .oe-check input{inline-size:auto;flex:none;}
  :where(.oe-field, .oe-check) :where(input,select,textarea){
    font:inherit; font-size:.9375rem; inline-size:100%; max-inline-size:22rem;
    padding:8px 10px; background:var(--oe-bench); color:var(--oe-ink);
    border:1px solid var(--oe-rule); border-radius:4px;
  }
  :where(.oe-field) :where(textarea){min-block-size:7rem;max-inline-size:34rem;line-height:1.55;}
  .oe-field :is(input,select,textarea):focus-visible{outline:2px solid var(--oe-teal);outline-offset:1px;}
  .oe-page .oe-threads,.oe-page .oe-messages{list-style:none;margin:0;padding:0;}
  .oe-page .oe-thread{margin-block-end:12px;}
  .oe-page .oe-thread-title{font-size:1.0625rem;font-weight:600;margin:0 0 6px;overflow-wrap:anywhere;}
  .oe-page .oe-thread .oe-thread-title{margin:0;}
  .oe-page .oe-thread-last{white-space:nowrap;}
  .oe-page .oe-badge.oe-unread{background:var(--oe-teal);color:#fff;}
  .oe-page .oe-messages{display:flex;flex-direction:column;gap:10px;margin:16px 0 20px;}
  .oe-page .oe-message{
    align-self:flex-start;max-inline-size:min(34rem,85%);padding:10px 14px;
    background:var(--oe-bench-sunk);border:1px solid var(--oe-rule);border-radius:12px 12px 12px 2px;
  }
  .oe-page .oe-message.is-mine{
    align-self:flex-end;background:var(--oe-bench);border-color:var(--oe-teal);border-radius:12px 12px 2px 12px;
  }
  .oe-page .oe-message.is-held{border-style:dashed;opacity:.85;}
  .oe-page .oe-message-body{overflow-wrap:anywhere;white-space:pre-wrap;}
  .oe-page .oe-message-meta{
    display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px;margin:4px 0 0;
    color:var(--oe-ink-muted);font-size:.8125rem;
  }
  .oe-page .oe-compose{margin-block-end:12px;}
  .oe-page .oe-compose textarea{inline-size:100%;max-inline-size:34rem;min-block-size:5rem;}
  .oe-page .oe-push{display:flex;flex-wrap:wrap;align-items:center;gap:4px 12px;margin-block:12px;}
  .oe-page .oe-push[hidden]{display:none;}
  .oe-page .oe-push .oe-muted{margin:0;}
  .oe-page .oe-push .oe-muted:empty{display:none;}
  .oe-page .oe-thread-actions{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px 20px;margin-block-start:12px;font-size:.875rem;}
  .oe-page .oe-thread-actions > .oe-row-actions{margin:0;font-size:inherit;}
  .oe-page .oe-thread-actions summary{display:inline;list-style:none;cursor:pointer;color:var(--oe-teal);text-decoration:underline;}
  .oe-page .oe-thread-actions summary::-webkit-details-marker{display:none;}
  .oe-page .oe-thread-actions details form{margin-block-start:12px;}
  .oe-sr-only{position:absolute;inline-size:1px;block-size:1px;margin:-1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0;}
  .oe-btn.oe-icon-btn{
    display:inline-grid;place-items:center;inline-size:36px;block-size:36px;padding:0;border-color:transparent;
    border-radius:6px;background:transparent;color:var(--oe-ink-muted);
  }
  .oe-btn.oe-icon-btn:hover{background:var(--oe-bench-sunk);color:var(--oe-ink);}
  .oe-btn.oe-icon-btn[aria-pressed=true]{color:var(--oe-teal);}
  .oe-btn.oe-icon-btn svg{inline-size:20px;block-size:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
  .oe-btn:focus-visible,.oe-menu summary:focus-visible,.oe-thread-link:focus-visible{outline:2px solid var(--oe-teal);outline-offset:2px;}
  .oe-page .oe-messenger{
    --oe-msg-pane:var(--oe-bench-warm); --oe-msg-theirs:var(--oe-bench-sunk);
    --oe-msg-hover:color-mix(in srgb,var(--oe-ink) 5%,var(--oe-bench));
    --oe-msg-active:color-mix(in srgb,var(--oe-teal) 11%,var(--oe-bench));
    display:grid;grid-template-columns:minmax(16rem,1fr) minmax(0,2fr);
    block-size:clamp(28rem,calc(100dvh - 14rem),52rem);margin:0 0 20px;
    background:var(--oe-bench);border:1px solid var(--oe-rule);border-radius:12px;overflow:hidden;
  }
  .oe-page .oe-messenger.is-empty{grid-template-columns:minmax(0,1fr);}
  .oe-page .oe-messenger-list,.oe-page .oe-messenger-chat{min-block-size:0;min-inline-size:0;display:flex;flex-direction:column;position:relative;}
  .oe-page .oe-messenger-list{border-inline-end:1px solid var(--oe-rule);}
  .oe-page .oe-messenger-chat{background:var(--oe-msg-pane);}
  .oe-page .oe-messenger.is-empty .oe-messenger-list{border-inline-end:0;}
  .oe-page .oe-list-head{flex:none;display:grid;gap:10px;padding:14px 14px 10px;}
  .oe-page .oe-list-head h2{margin:0;font-size:1.25rem;}
  .oe-page .oe-list-head > :first-child{display:flex;align-items:center;justify-content:space-between;gap:8px;}
  .oe-page .oe-list-head .oe-push{margin:0;}
  .oe-page .oe-list-filter{display:block;}
  .oe-page .oe-list-filter input{
    inline-size:100%;max-inline-size:none;min-block-size:40px;padding:8px 10px;font:inherit;font-size:.875rem;
    background:var(--oe-bench-warm);color:var(--oe-ink);border:1px solid var(--oe-rule);border-radius:6px;
  }
  .oe-page .oe-list-filter input:focus-visible{outline:2px solid var(--oe-teal);outline-offset:1px;}
  .oe-page .oe-list-filter[hidden],.oe-page .oe-list-tabs[hidden]{display:none;}
  .oe-page .oe-list-tabs{display:flex;gap:4px;padding:4px;background:var(--oe-bench-sunk);border-radius:6px;}
  .oe-page .oe-list-tabs button{
    flex:1;min-block-size:32px;border:0;border-radius:4px;background:transparent;color:var(--oe-ink-muted);
    font:inherit;font-size:.8125rem;font-weight:600;cursor:pointer;
  }
  .oe-page .oe-list-tabs button[aria-pressed=true]{background:var(--oe-bench);color:var(--oe-teal);}
  .oe-page .oe-list-tabs-count{margin-inline-start:6px;font-variant-numeric:tabular-nums;}
  .oe-page .oe-list-tabs-count[hidden]{display:none;}
  .oe-page .oe-messenger .oe-threads{flex:1;min-block-size:0;overflow-y:auto;padding:0 8px 8px;}
  .oe-page .oe-messenger .oe-thread{margin:0;}
  .oe-page .oe-messenger .oe-thread + .oe-thread{margin-block-start:2px;}
  .oe-page .oe-messenger .oe-thread[hidden]{display:none;}
  .oe-page .oe-thread-link{
    display:grid;grid-template-columns:56px minmax(0,1fr) auto;column-gap:12px;align-items:center;
    padding:10px 8px;border-radius:6px;color:inherit;text-decoration:none;
  }
  .oe-page .oe-thread-link:hover{background:var(--oe-msg-hover);text-decoration:none;}
  .oe-page .oe-thread.is-current .oe-thread-link,.oe-page .oe-thread-link[aria-current]{background:var(--oe-msg-active);}
  .oe-page .oe-thread-photo{
    grid-area:1/1/4/2;inline-size:56px;block-size:56px;border-radius:6px;object-fit:cover;background:var(--oe-bench-sunk);
  }
  .oe-page .oe-thread-initial{
    grid-area:1/1/4/2;align-self:end;justify-self:end;display:grid;place-items:center;
    inline-size:26px;block-size:26px;margin:0 -5px -5px 0;border-radius:4px;
    background:var(--oe-teal);color:var(--oe-on-teal);font-size:.75rem;font-weight:700;line-height:1;
    box-shadow:0 0 0 2px var(--oe-bench);
  }
  .oe-page .oe-thread-name{grid-column:2;grid-row:1;min-inline-size:0;font-size:.875rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .oe-page .oe-thread-time{grid-column:3;grid-row:1;font-size:.75rem;color:var(--oe-ink-muted);font-variant-numeric:tabular-nums;white-space:nowrap;}
  .oe-page .oe-messenger .oe-thread-title{
    grid-column:2/4;grid-row:2;margin:0;font-size:.8125rem;font-weight:400;color:var(--oe-ink-muted);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.35;
  }
  .oe-page .oe-thread-preview{grid-column:2;grid-row:3;min-inline-size:0;font-size:.8125rem;color:var(--oe-ink-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.35;}
  .oe-page .oe-thread-preview.is-typing{color:var(--oe-teal);font-weight:600;}
  .oe-page .oe-thread-link .oe-unread,.oe-page .oe-thread-flag{grid-column:3;grid-row:3;justify-self:end;margin-inline-start:8px;}
  .oe-page .oe-thread-flag{font-size:.6875rem;font-weight:700;padding:1px 6px;border-radius:4px;background:var(--oe-bench-sunk);color:var(--oe-ink-muted);}
  .oe-page .oe-thread.is-unread .oe-thread-name{font-weight:800;}
  .oe-page .oe-thread.is-unread .oe-thread-preview{color:var(--oe-ink);font-weight:600;}
  .oe-page .oe-thread.is-unread .oe-thread-time{color:var(--oe-teal);font-weight:700;}
  .oe-page .oe-chat-empty{flex:1;display:grid;place-content:center;gap:4px;padding:24px;text-align:center;color:var(--oe-ink-muted);}
  .oe-page .oe-chat-head{
    flex:none;display:flex;align-items:center;gap:12px;padding:10px 12px 10px 16px;
    background:var(--oe-bench);border-block-end:1px solid var(--oe-rule);
  }
  .oe-page .oe-chat-back{display:none;}
  .oe-page .oe-chat-name{flex:1;min-inline-size:0;margin:0;font-size:1rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .oe-page .oe-chat-role{font-size:.8125rem;color:var(--oe-ink-muted);}
  .oe-page .oe-menu{position:relative;margin-inline-start:auto;}
  .oe-page .oe-menu summary{
    display:grid;place-items:center;inline-size:36px;block-size:36px;border-radius:6px;list-style:none;
    cursor:pointer;color:var(--oe-ink-muted);
  }
  .oe-page .oe-menu summary::-webkit-details-marker{display:none;}
  .oe-page .oe-menu summary:hover,.oe-page .oe-menu[open] summary{background:var(--oe-bench-sunk);color:var(--oe-ink);}
  .oe-page .oe-menu-panel{
    position:absolute;inset-inline-end:0;top:calc(100% + 6px);z-index:30;min-inline-size:15rem;padding:6px;
    background:var(--oe-bench);border:1px solid var(--oe-rule);border-radius:10px;
    box-shadow:0 8px 24px color-mix(in srgb,var(--oe-ink) 16%,transparent);
  }
  .oe-page .oe-menu-panel form{margin:0;}
  .oe-page .oe-menu-item{
    display:flex;align-items:center;gap:10px;inline-size:100%;padding:9px 10px;border:0;border-radius:6px;
    background:none;color:var(--oe-ink);font:inherit;font-size:.875rem;font-weight:600;text-align:start;text-decoration:none;cursor:pointer;
  }
  .oe-page .oe-menu-item:hover,.oe-page .oe-menu-item:focus-visible{background:var(--oe-bench-sunk);}
  .oe-page .oe-menu-item.oe-danger-link{color:var(--oe-danger);}
  .oe-page .oe-menu-item[hidden],.oe-page .oe-menu-note[hidden]{display:none;}
  .oe-page .oe-menu-note{max-inline-size:16rem;margin:4px 12px 8px;font-size:.8125rem;line-height:1.4;color:var(--oe-ink-muted);}
  .oe-page .oe-menu-report > summary{list-style:none;cursor:pointer;}
  .oe-page .oe-menu-report > summary::-webkit-details-marker{display:none;}
  .oe-page .oe-menu-report > form{padding:8px 12px;}
  .oe-page .oe-listing-card{
    flex:none;display:flex;align-items:center;gap:12px;padding:8px 16px;
    background:var(--oe-bench);border-block-end:1px solid var(--oe-rule);
  }
  .oe-page .oe-listing-photo{flex:none;inline-size:44px;block-size:44px;border-radius:6px;object-fit:cover;background:var(--oe-bench-sunk);}
  .oe-page .oe-listing-title{flex:1;min-inline-size:0;font-size:.875rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .oe-page .oe-listing-price{flex:none;font-size:.875rem;font-weight:700;color:var(--oe-teal);font-variant-numeric:tabular-nums;}
  .oe-page .oe-listing-link{flex:none;font-size:.8125rem;}
  .oe-page .oe-listing-card.is-removed .oe-listing-photo{filter:grayscale(1);opacity:.45;}
  .oe-page .oe-listing-card.is-removed .oe-listing-title{color:var(--oe-ink-muted);}
  .oe-page .oe-messenger .oe-messages{flex:1;min-block-size:0;overflow-y:auto;overscroll-behavior:contain;margin:0;padding:12px 20px 8px;gap:0;}
  .oe-page .oe-day{align-self:center;margin:16px 0 10px;padding:2px 10px;border-radius:6px;font-size:.75rem;font-weight:700;color:var(--oe-ink-muted);background:var(--oe-bench-sunk);}
  .oe-page .oe-messenger .oe-message{margin-block-start:2px;padding:8px 12px;border:0;border-radius:4px 16px 16px 4px;background:var(--oe-msg-theirs);box-shadow:0 0 0 1px var(--oe-rule);line-height:1.45;}
  .oe-page .oe-messenger .oe-message.is-mine{border-radius:16px 4px 4px 16px;background:var(--oe-teal);color:var(--oe-on-teal);box-shadow:none;}
  .oe-page .oe-messenger .oe-message.is-first{margin-block-start:12px;border-start-start-radius:16px;}
  .oe-page .oe-messenger .oe-message.is-last{border-end-start-radius:16px;}
  .oe-page .oe-messenger .oe-message.is-mine.is-first{border-start-start-radius:16px;border-start-end-radius:16px;}
  .oe-page .oe-messenger .oe-message.is-mine.is-last{border-end-start-radius:16px;border-end-end-radius:16px;}
  .oe-page .oe-messenger .oe-message.is-held{background:color-mix(in srgb,var(--oe-teal) 10%,var(--oe-msg-pane));color:var(--oe-ink);box-shadow:inset 0 0 0 1px var(--oe-teal);opacity:1;}
  .oe-page .oe-message-meta{margin:4px 0 0;font-size:.6875rem;color:var(--oe-ink-muted);}
  .oe-page .oe-message.is-mine .oe-message-meta{color:inherit;opacity:.85;}
  .oe-page .oe-seen{display:inline-flex;align-items:center;gap:3px;font-weight:700;}
  .oe-page .oe-held{display:inline-flex;align-items:center;gap:4px;font-weight:700;}
  .oe-page .oe-typing{margin:12px 20px 0;}
  .oe-page .oe-typing-bubble{display:flex;align-items:center;inline-size:max-content;padding:12px 14px;border-radius:16px 16px 16px 4px;background:var(--oe-msg-theirs, var(--oe-bench-sunk));box-shadow:0 0 0 1px var(--oe-rule);}
  .oe-page .oe-typing[hidden]{display:none;}
  .oe-page .oe-typing-bubble > i{
    display:inline-block;inline-size:7px;block-size:7px;margin-inline:2px;border-radius:50%;background:var(--oe-ink-muted);
    animation:oe-blink 1.2s ease-in-out infinite;
  }
  .oe-page .oe-typing-bubble > i:nth-of-type(2){animation-delay:.15s;}
  .oe-page .oe-typing-bubble > i:nth-of-type(3){animation-delay:.3s;}
  @keyframes oe-blink{0%,60%,100%{opacity:.35;transform:translateY(0);}30%{opacity:1;transform:translateY(-3px);}}
  .oe-page .oe-messenger .oe-compose{flex:none;margin:0;padding:10px 16px 14px;background:var(--oe-bench);border-block-start:1px solid var(--oe-rule);}
  .oe-page .oe-compose-row{display:flex;align-items:flex-end;gap:8px;}
  .oe-page .oe-messenger .oe-compose textarea{
    flex:1;min-inline-size:0;inline-size:auto;max-inline-size:none;min-block-size:44px;max-block-size:10rem;resize:none;
    padding:10px 12px;font:inherit;line-height:1.45;background:var(--oe-bench-warm);color:var(--oe-ink);
    border:1px solid var(--oe-rule);border-radius:12px;
  }
  .oe-page .oe-messenger .oe-compose textarea:focus-visible{outline:2px solid var(--oe-teal);outline-offset:1px;}
  .oe-page .oe-compose-send{
    flex:none;display:grid;place-items:center;inline-size:44px;block-size:44px;padding:0;border-radius:12px;
  }
  .oe-page .oe-compose-send:disabled{opacity:.4;cursor:not-allowed;}
  .oe-page .oe-compose-foot{display:flex;justify-content:space-between;gap:12px;margin-block-start:6px;min-block-size:16px;font-size:.75rem;color:var(--oe-ink-muted);}
  .oe-page .oe-compose-counter{margin-inline-start:auto;font-variant-numeric:tabular-nums;font-weight:700;}
  .oe-page .oe-compose-counter.is-tight{color:var(--oe-danger);}
  .oe-page .oe-stopbar{
    flex:none;display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--oe-bench);
    border-block-start:1px solid var(--oe-rule);font-size:.875rem;color:var(--oe-ink-muted);
  }
  .oe-page .oe-stopbar p{flex:1;margin:0;}
  .oe-page .oe-stopbar form{margin:0;}
  @media (max-width:899px){
    .oe-page .oe-messenger{grid-template-columns:minmax(0,1fr);border-radius:0;block-size:clamp(26rem,calc(100dvh - 9rem),60rem);}
    .oe-page .oe-messenger:not(.is-chat) .oe-messenger-chat{display:none;}
    .oe-page .oe-messenger.is-chat .oe-messenger-list{display:none;}
    .oe-page .oe-messenger-list{border-inline-end:0;}
    .oe-page .oe-chat-back{display:inline-grid;place-items:center;inline-size:44px;block-size:44px;margin-inline-start:-6px;color:var(--oe-ink);}
    .oe-page .oe-chat-head{padding:6px 6px 6px 10px;gap:8px;}
    .oe-page .oe-listing-link{display:none;}
    .oe-page .oe-messenger .oe-messages{padding:8px 12px;}
    .oe-page .oe-messenger .oe-compose textarea{font-size:1rem;}
    .oe-page .oe-compose-hint{display:none;}
  }
  @media (prefers-reduced-motion:reduce){
    .oe-page .oe-typing-bubble > i{animation:none;opacity:.6;}
  }
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
}
.oe-btn-danger,.oe-btn.oe-btn-danger{
  background:var(--oe-danger,#c22826);background-image:none;border:1px solid var(--oe-danger,#c22826);
  border-radius:4px;padding:9px 18px;font:inherit;font-size:.9375rem;font-weight:500;
  color:#fff!important;text-shadow:none;box-shadow:none;cursor:pointer;
}
.oe-btn-danger:hover,.oe-btn.oe-btn-danger:hover{
  background:var(--oe-danger-deep,#9b201e);border-color:var(--oe-danger-deep,#9b201e);color:#fff;
}
</style>
