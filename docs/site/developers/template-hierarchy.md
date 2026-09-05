---
title: Template hierarchy
description: Every front-end page now resolves through an ordered list of candidate views, so a theme can specialise a page or add a view of its own without a patch to ShopClass.
sidebar:
  order: 17
---

Every front-end controller used to name exactly one view. Rendering a listing
meant `item.php`, and that was the whole conversation — a theme that wanted a
different layout for one category had nowhere to put it.

Controllers now name an **ordered list**, most specific first, and core renders
the first one a theme actually ships.

## What core offers

| Page | Candidates, in order |
|---|---|
| Listing | `item-{categoryId}.php`, `item.php` |
| Search / category results | `search-{category}.php`, `search.php` |
| Static page | `page-{slug}.php`, the picked template, `page.php` |
| Everything else | one candidate, the name it has always had |

`{category}` on a results page is the token from the URL — the slug for
`/jobs`, the id when the search was made by id. It is offered only when the
search is filtered to exactly one category.

Ship `item-12.php` and listings in category 12 render through it. Ship nothing
and every page resolves exactly as before.

## Locating a view yourself

```php
osc_locate_template(array $candidates, string $context = ''): string
```

Returns the **view name** of the first candidate any theme in the stack can
render — not a filesystem path, because rendering goes through
`osc_current_web_theme_path()`, which also points the theme's own asset URLs at
whichever theme answered.

When nothing matches, the last candidate comes back. A caller that names one
view therefore behaves exactly as it did before there was a list.

### Two orderings, and which one wins

Views are resolved against a stack: your theme, its parent when it declares one,
then the theme core keeps as a last resort.

**Candidate order applies within one theme, and theme order comes first.** If
your theme ships `item.php` and a fallback theme happens to ship `item-12.php`,
your generic view wins. Otherwise adding a candidate could hand one of your pages
to a theme the site is not running.

`osc_theme_template_paths()` returns that stack as absolute directory paths.

## Adding a view without a core patch

The candidate list passes through the `template_candidates` filter, with the
route's context slug as the second argument:

```php
osc_add_filter('template_candidates', 'mytheme_templates', 5, 2);

function mytheme_templates(array $candidates, string $context): array
{
    if ($context === 'item' && osc_item_is_premium()) {
        array_unshift($candidates, 'item-premium.php');
    }

    return $candidates;
}
```

Context slugs are the route names: `home`, `search`, `item`, `item-post`,
`item-edit`, `item-contact`, `item-send-friend`, `page`, `contact`, `custom`,
`user-custom`, `404`, and one per account view (`user-profile`,
`user-dashboard`, `user-items`, `user-alerts`, `user-login`, `user-register`,
`user-recover`, `user-forgot_password`, `user-change_email`,
`user-change_username`, `user-change_password`, `user-delete_account`,
`user-public-profile`).

A filter that returns anything other than an array is ignored, and candidates
that are absolute or contain `..` are dropped — neither can blank a page or
reach outside the theme directories.

## Editing reuses the publishing form

Core asks for `item-edit.php` first and falls back to `item-post.php`, because the
two carry the same fields — `ItemForm` hands both the same list, and the view can
tell them apart with `osc_is_edit_page()`.

So a theme ships **one** publishing form and gets editing for free. Ship
`item-edit.php` as well and yours still wins for that route.

`ItemForm` supplies everything that differs between the two, so the view does not
have to work out which one it is:

| Call | What it gives you |
|---|---|
| `ItemForm::route_hidden()` | `page`, `action`, and on edit the listing's `id` and `secret` |
| `ItemForm::location_record()` | the listing when editing, the seller's own address when publishing |
| `ItemForm::selected_country()` | the country the region list should be built from |
| `ItemForm::selected_region()` | the region the city list should be built from |
| `ItemForm::plugin_item_fields()` | the plugin fields for whichever form this is |

The two `selected_*` calls prefer the **posted** value, which matters without
JavaScript: change country, submit, and the re-rendered form offers that
country's regions rather than the previous one's.

The title and description inputs pick their own locale — the visitor's when
publishing, and when editing the one the listing's text actually came from, so an
edit updates the translation it is showing instead of copying it into a second
one. `osc_item_content_locale()` reports that locale and
`ItemForm::locale_field_id()` the id these fields carry, which is not their name.

Core loads the uploader and the location combobox from the head on both routes,
so you no longer enqueue `osc-uploader` or `osc-ui-common` before the form.
