---
title: E-mail layout
description: Every HTML e-mail Shopclass sends goes out in one layout, with a header, a card for the message and a footer. A theme replaces it with one file; a plugin changes it with two filters.
sidebar:
  order: 28
---

Every HTML e-mail goes through `osc_sendMail()`, and it wraps the message in one
layout: the site name or logo on top, the message in a card, and a footer with
the site's address. The plain-text copy is made from the message alone.

## In a theme

Ship `templates/email-layout.php` in the theme. Core looks in the active theme,
then its parent, then uses its own `oc-includes/osclass/gui/templates/email-layout.php`.
Copy core's file as a start.

The file gets one array, `$mail`:

| Key | What |
|---|---|
| `body` | The message, as HTML that is safe to print |
| `subject` | The e-mail's subject |
| `preheader` | The first 120 characters of the message, for the inbox preview |
| `site_name`, `site_url` | The site |
| `logo_url` | A logo to show instead of the name; empty by default |
| `accent` | A hex colour for the top border and links |
| `footer` | The footer line |

Use tables and inline styles: many mail apps ignore a `<style>` block, and none
run scripts.

## In a plugin

```php
// Change what the layout gets: a logo, a colour, a footer line.
osc_add_filter('mail_layout_vars', function (array $mail, array $params) {
    $mail['logo_url'] = 'https://example.com/logo.png';
    $mail['accent']   = '#c2410c';

    return $mail;
});

// Or replace the finished HTML.
osc_add_filter('mail_layout', function (string $html, array $mail, array $params) {
    return $html;
});
```

`$params` is what was passed to `osc_sendMail()`.

## Sending without it

A body that is already a whole HTML document (it contains `<html`) is sent as it
is. To skip the layout on purpose, pass `'layout' => false` to `osc_sendMail()`.
