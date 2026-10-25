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
  .oe-page .oe-band{
    background:var(--oe-band); border-bottom:1px solid var(--oe-rule);
    padding:16px 32px; display:flex; align-items:center; gap:12px;
  }
  .oe-page .oe-band svg{flex:none;}
  .oe-page .oe-band-plain{background:var(--oe-bench);}
  .oe-page .oe-brand{font-weight:600;color:var(--oe-ink);letter-spacing:-.01em;text-decoration:none;}
  .oe-page .oe-brand span{color:var(--oe-teal);}
  .oe-page .oe-logo{max-height:28px;width:auto;display:block;}
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
  .oe-page .oe-doc{max-width:880px;margin:0 auto;padding:32px 16px 64px;}
  .oe-page .oe-panel,.oe-page .oe-bill-card{
    background:var(--oe-bench);border:1px solid var(--oe-rule);border-radius:6px;
    padding:20px 24px;margin-bottom:20px;
  }
  .oe-btn,.oe-bill-btn,.oe-lead a.button,.oe-lead a.btn,.oe-actions a.oe-primary,.oe-actions a.oe-secondary{
    display:inline-block;text-decoration:none;border:1px solid var(--oe-teal);border-radius:4px;
    padding:9px 18px;font:inherit;font-size:.9375rem;font-weight:500;cursor:pointer;
    background:var(--oe-teal);color:#fff;
    background-image:none;text-shadow:none;box-shadow:none;
  }
  .oe-btn:hover,.oe-bill-btn:hover,.oe-lead a.button:hover,.oe-actions a.oe-primary:hover{
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
  .oe-dialog[data-osc-dialog-ready]::backdrop{background:rgba(0,0,0,.45);}
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
