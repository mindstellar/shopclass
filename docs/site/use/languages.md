---
title: Languages
description: Add, import and manage languages in a ShopClass site, and translate categories, pages and e-mail templates.
sidebar:
  order: 12
---

ShopClass is multilingual throughout: categories, static pages, custom fields
and e-mail templates all carry a value per active language.

**Settings → Languages.**

## Adding a language

**Add language** installs from the official translations. What is available
comes from
[**mindstellar/shopclass-i18n**](https://github.com/mindstellar/shopclass-i18n),
which is where translations are maintained and where you contribute a fix.

If the language you want is not offered — the screen says *no official languages
available* when it cannot reach the list — you can **upload** a translation file
directly.

Languages are enabled and disabled independently, so you can prepare one before
making it visible to visitors.

## Setting the default

The default language is set in **Settings → General**. It is what visitors see
before they choose, and what content falls back to.

## What you have to translate yourself

Installing a language translates the **interface**. Your own content is not
translated by anyone but you:

| Content | Where |
|---|---|
| Category names | **Categories** — one name per active language |
| Static pages | **Pages** — title and body per language |
| Field and form labels | **Forms** — the admin requires a name for the default locale |
| E-mail templates | **Settings → E-mail templates** — per language |

:::caution[An empty translation shows as empty]
A category or page with no text for an active language renders blank to visitors
using it. Either translate everything, or do not enable the language yet.
:::

## Deciding how many to run

Each active language multiplies the content you maintain: every new category and
every new page needs another translation, forever.

Two languages done properly beat five done half-way. And on a local marketplace,
one language done well usually beats both.

## Contributing a translation

Translations live in
[shopclass-i18n](https://github.com/mindstellar/shopclass-i18n). Fixing an
awkward string in a language you speak takes minutes and is one of the most
useful contributions to the project — see
[contributing](/docs/developers/contributing/).
