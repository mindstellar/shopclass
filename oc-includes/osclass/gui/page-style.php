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
  /* Link colour is text colour, so it follows the same rule as the type above it:
     set only on the standalone shell. Inside a theme the page's links are the
     theme's links, which is what makes a core-rendered page read as part of the
     site rather than as a panel dropped into it. */
  body.oe-page a{color:var(--oe-teal);}
  /* Headings carry user text -- a member's name, a listing title -- so one
     long token must wrap rather than widen the page. */
  .oe-page .oe-h1{font-size:1.5rem;font-weight:600;letter-spacing:-.01em;margin:0 0 20px;overflow-wrap:anywhere;}
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
  .oe-page .oe-actions a.oe-secondary,.oe-page .oe-btn.oe-secondary{
    background:var(--oe-bench);color:var(--oe-ink);border-color:var(--oe-rule);
  }
  .oe-page .oe-actions a.oe-secondary:hover,.oe-page .oe-btn.oe-secondary:hover{border-color:var(--oe-ink-muted);}

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

  /* ------------------------------------------------------------------ account --
     The vocabulary the account and auth partials emit. Role names, not page
     names: .oe-account-nav is what a thing IS, .oe-dashboard-box would be where
     it happened to sit. Documented in docs/site/developers/account-pages.md,
     which is the contract -- these names cannot be renamed once released. */

  /* Content column plus the section nav. One column below 60rem, nav last in the
     source either way, so it reads after the page on a narrow screen and in a
     screen reader. */
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
  /* aria-current is the accessible signal; the weight and tint follow it rather
     than being set separately, so the two can never disagree. */
  .oe-page .oe-account-nav [aria-current]{background:var(--oe-bench-sunk);font-weight:600;}

  /* Core's own flash output, whose class names are frozen and are the notice
     surface for the whole product -- so there is no second .oe-* name for the
     same role. Scoped inside .oe-page so these rules can only reach a message
     rendered within core's own block; a theme's header-rendered flash is
     untouched. The message text carries the meaning, so the tint ranks it
     rather than being the only signal. */
  .oe-page .flashmessage{
    border:1px solid var(--oe-rule); border-radius:6px; background:var(--oe-bench);
    padding:12px 16px; margin:0 0 20px;
  }
  .oe-page .flashmessage-ok{background:#e4f8e7;border-color:var(--oe-success);}
  .oe-page .flashmessage-error{background:#ffe9e5;border-color:var(--oe-danger);}
  .oe-page .flashmessage-warning{background:#fdf4d2;border-color:var(--oe-warning);}
  .oe-page .flashmessage-info{background:#e6f6f4;border-color:var(--oe-teal);}
  .oe-page .flashmessage > :last-child{margin-block-end:0;}

  /* A list of records -- listings, alerts. A list, not a table: the rows carry a
     picture and wrap, which a table row does badly on a phone. */
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
  /* Set apart from everything routine above it, not decorated. */
  .oe-page .oe-danger{margin-block-start:3rem;padding-block-start:1.5rem;border-block-start:1px solid var(--oe-rule);}
  .oe-page .oe-danger h2{color:var(--oe-danger);}
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

  /* A page that is one form: sign in, register, reset a password. Bounded so a
     single column of fields still reads as one object at any window width. */
  .oe-page .oe-form-page{max-inline-size:30rem;}
  /* Core's register validator writes <li> into #error_list and never changes its
     visibility, so the box is its own switch: nothing at all until it has one. */
  .oe-page #error_list{list-style:none;margin:0;padding:0;}
  .oe-page #error_list:not(:empty){
    background:#ffe9e5; border:1px solid var(--oe-danger); border-radius:6px;
    margin:0 0 20px; padding:12px 16px; padding-inline-start:32px; list-style:disc;
  }

  /* Help text bound to its field with aria-describedby. */
  .oe-page .oe-hint{display:block;font-size:.8125rem;color:var(--oe-ink-muted);margin-block-start:6px;}
  /* A checkbox and its label on one line -- the label wraps the control, so the
     whole row is the target and no `for` can drift. */
  .oe-page .oe-check{display:flex;align-items:center;gap:10px;margin:0 0 16px;font-size:.9375rem;}
  .oe-page .oe-check input{inline-size:auto;flex:none;}

  /* The floor under a control core did not put a class on: UserForm renders bare
     <input>/<select>/<textarea>. Zero specificity on purpose (:where), so ANY
     rule a theme writes -- even a bare `input {}` -- wins. Inside a theme these
     should look like the theme's fields, not like core's. */
  :where(.oe-page .oe-field, .oe-page .oe-check) :where(input,select,textarea){
    font:inherit; font-size:.9375rem; inline-size:100%; max-inline-size:22rem;
    padding:8px 10px; background:var(--oe-bench); color:var(--oe-ink);
    border:1px solid var(--oe-rule); border-radius:4px;
  }
  :where(.oe-page .oe-field) :where(textarea){min-block-size:7rem;max-inline-size:34rem;line-height:1.55;}
  .oe-page .oe-field :is(input,select,textarea):focus-visible{outline:2px solid var(--oe-teal);outline-offset:1px;}

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
