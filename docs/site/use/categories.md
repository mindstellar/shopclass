---
title: Categories
description: Build and manage the ShopClass category tree — adding, nesting, reordering, disabling and translating categories, and what happens to listings when you change one.
sidebar:
  order: 2
---

Every listing belongs to exactly one category, and categories drive browsing,
filtering, [forms](/docs/use/forms-and-custom-fields/) and much of your URL
structure. They are the most consequential structural decision on the site.

**Listings → Categories** in the admin panel.

## Adding a category

**Categories → Add category.** A category needs a name per active language; the
URL slug is derived from the name.

Nesting is one level in practice: a top-level category with children under it.
Listings should sit in the leaf, not the parent.

## Reordering

Drag categories into the order you want. This is the order visitors see when
browsing, so put your busiest categories at the top rather than leaving them
alphabetical.

## Disabling versus deleting

| Action | What happens to listings in it |
|---|---|
| **Disable** | The category and its listings stop appearing on the front end. Nothing is destroyed, and re-enabling brings it all back. |
| **Delete** | The category is removed **along with the listings in it**. |

:::danger[Deleting a category deletes its listings]
There is no undo. If you are reorganising, disable the category, move the
listings out, and only then delete it.
:::

## Renaming and URLs

Renaming a category changes its slug, and therefore the URL of its browse page.
ShopClass keeps a slug history so old category URLs continue to resolve, but
external links and your own rankings still point at the old address for a while.

Rename early, or not at all.

## Per-category settings

Editing a category exposes its own rules:

- **Expiration (days)** — how long a listing in this category lives. This is
  where listing expiry is set, not in the global listing settings.
- **Price** settings for the category.
- **Apply the expiration and price changes to all subcategories** — the option
  that saves you editing each child by hand.

Plugins add their own per-category configuration here too, which is what
`t_plugin_category` stores.

## Fields per category

Fields are attached to categories through forms, not directly. A *Vehicles* form
holding Make, Model, Year and Fuel Type is attached to your vehicle categories,
and its fields then appear on the publish form and in filters for those
categories only.

See [forms and custom fields](/docs/use/forms-and-custom-fields/).

## Designing the tree

A few rules that hold up:

- **Match your visitors' words, not your industry's.** "Cars" beats "Automotive
  vehicles" every time.
- **Do not create a category you cannot fill.** Twelve empty categories look
  abandoned; three busy ones look alive. Add more as volume justifies them.
- **Keep it shallow.** Every extra level is another click between a visitor and
  a listing.
- **Fewer, broader categories plus good filters** beats a deep tree. Filters are
  what actually narrow a search — that is what forms and fields are for.
