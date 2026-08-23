---
title: Static pages
description: Create About, Terms and Contact pages in ShopClass with the rich-text editor or the block-based page builder, and place forms on them.
sidebar:
  order: 9
---

Every classifieds site needs a handful of pages that are not listings — About,
Terms, Privacy, How it works, Contact. As the admin puts it: *static pages like
"About Us" or "Info" live here.*

**Pages** in the admin panel.

## Creating a page

**Pages → Add page**. A page has a title and body per active language, and a
URL slug derived from the title.

Pages are linked from the footer automatically, which is also why deleting one
warns you: *this permanently removes the page and any link to it in the footer.*

## Two ways to build one

**The text editor** is the default: a rich-text field, right for prose. Terms
and Privacy want exactly this.

**Page builder (blocks)** composes the page from widget blocks instead — *"compose
this page from widget blocks instead of the text editor"*. Right for a landing
page or a How-it-works page with images and sections.

Pick the template when creating the page. The available blocks are the same
widget types used elsewhere:

| Block | What it is |
|---|---|
| **Rich text** | Formatted text. Blank lines become paragraphs. |
| **Image** | An image from your media library, optionally linking somewhere. |
| **Custom Code (HTML / JavaScript)** | Raw markup and script. |

Plugins can register further block types.

A page builder page renders correctly on **any** theme. If the active theme
ships its own `template-widgets.php` the theme owns the layout; otherwise core
wraps the theme's header and footer around the blocks.

## Forms on a page

A [form](/docs/use/forms-and-custom-fields/) can be placed on a page to collect
submissions — a contact form, an application, an enquiry form. Responses arrive
in **Forms → Submissions** rather than only by e-mail, so nothing is lost if
mail delivery fails.

## Pages worth having

Beyond taste, some of these are load-bearing:

- **Terms** and **Privacy** — required by most payment providers and by law in
  many jurisdictions, and the first thing a user checks before posting personal
  contact details.
- **Contact** — an actual route to a human. A marketplace with no contact page
  reads as a scam.
- **How it works** — the page that converts a visitor into a first-time poster.
- **About** — who runs the site. On a local marketplace this is worth more than
  any feature.

## Editing safely

- **Changing a title changes the URL.** Rename early or not at all.
- **Custom Code blocks run on visitors' browsers.** Paste only what you
  understand, from a source you trust.
- **Write each language.** A page with an empty translation shows empty to
  visitors in that language — see [languages](/docs/use/languages/).
