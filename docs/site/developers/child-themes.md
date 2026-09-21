---
title: Child themes
description: Extend a theme without forking it — what a child inherits, the order Shopclass looks in, how assets and functions.php resolve, and the two rules that stop a child breaking its parent.
sidebar:
  order: 17
---

A child theme is a theme that names another as its `Parent Theme`. It ships only the files
it changes; everything else comes from the parent, which keeps updating underneath.

That is the whole point. Edit a theme's files directly and the next update overwrites your
work. Put the same changes in a child and they survive.

## Declaring one

A child is an ordinary theme with one extra header line. In its `index.php`:

```php
<?php
/*
Theme Name: Storefront Blue
Description: Storefront with our colours and a different listing card.
Version: 1.0.0
Author: Example Ltd
Parent Theme: storefront
*/
```

`Parent Theme` is the parent's **directory name**, not its display name. `storefront`, not
`Storefront`. It must be a plain name — a value containing a slash or `..` is refused
before it reaches the filesystem.

The child needs a directory of its own under `oc-content/themes/` and nothing else. A
child with only an `index.php` header block is valid and renders exactly like its parent.

## The order Shopclass looks in

Every view resolves through an ordered list of directories:

1. the **active** theme (the child)
2. its **parent**, when one is declared and installed
3. **storefront**, the bundled default

The first directory that has the file wins. So a child shipping `item.php` renders its own
listing page and inherits `search.php` from the parent without mentioning it.

The walk is **one level deep**. A parent that itself declares a parent does not add a
fourth directory — a grandparent is not consulted. Neither is a theme that names itself,
and neither is an A-declares-B, B-declares-A pair; both resolve once and stop.

A `Parent Theme` naming a directory that is not installed is skipped silently. The child
still renders, falling through to storefront. This is deliberate: a missing parent should
degrade, not white-screen. The Appearance screen tells you when it happens.

## What a child may override

Anything the walk reaches:

| What | Inherited? | Notes |
|---|---|---|
| Views (`item.php`, `search.php`, `user/*.php`, …) | yes | First theme in the walk that has the file |
| Chrome (`header.php` + `footer.php`, or `common/`) | yes | The pair is taken from one theme, never split |
| `functions.php` | **both run** | See below — this one is not a fallback |
| `osc_add_theme_support()` declarations | yes | The child wins a contested feature |
| `css/`, `js/`, images | **no walk** | See [Assets](#assets) |

## functions.php

This is the one file that does not fall back. **Both** run, child first, then parent.

That ordering is what lets a child replace a parent's function — but only if the parent
guards it:

```php
// In the PARENT's functions.php
if (!function_exists('storefront_listing_card')) {
    function storefront_listing_card($item) {
        // …
    }
}
```

The child declares `storefront_listing_card()` first and the parent's guard then skips its
own copy.

**Without that guard, PHP stops the request.** Two files declaring the same function name
is `Cannot redeclare storefront_listing_card()` — a fatal the site cannot catch or recover
from, and because the child loads first it is the *parent* that crashes. The page is blank.

**The guard belongs on the parent, and only there.** Putting one in the child does
nothing: the child loads first, so `function_exists()` is always false at that point, the
function is declared anyway, and the parent still hits the fatal.

So:

- **Writing a parent theme:** wrap every function in `function_exists()`. Every one. It is
  the only thing that lets a child override anything.
- **Writing a child theme:** do not redeclare a function the parent does not guard, and do
  not bother guarding your own. Prefix them with your theme's slug so they cannot collide.

### A worked example

Storefront wraps all 33 of its functions, so any of them can be replaced. To change how a
listing description is trimmed, copy the signature into the child and write your own body:

```php
// oc-content/themes/storefront-blue/functions.php
function storefront_excerpt($html, $len = 180)
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));

    return $text === '' ? '' : mb_substr($text, 0, 40) . '…';
}
```

That is the whole override. No registration, no hook, no call to the parent. The child's
copy is declared first, storefront's guard sees the name is taken and skips its own, and
every `storefront_excerpt()` call in the parent's templates now runs yours.

### When the parent did not guard

Then you cannot replace that function, and there is no workaround — declaring it is a
fatal either way. Two things still work:

- **Hooks and filters.** Both themes may add to the same hook and both run, so a child can
  change behaviour without touching the function. This is the escape hatch.
- **Copy the view.** If the function is only called from one template, copy that template
  into the child and call something else.

If you own the parent, add the guards. It costs nothing and it is the only thing that
makes the theme extensible.

Hooks have no such problem — a child and parent may both add to the same hook, and both
run.

## Theme support

`osc_add_theme_support()` works from either file, and the **child wins** anything both
declare:

```php
// PARENT functions.php
osc_add_theme_support('views', array('item-compact'));
osc_add_theme_support('widgets', array('sidebar', 'footer'));

// CHILD functions.php
osc_add_theme_support('views', array('item-compact', 'item-wide'));
```

The site ends up with the child's two views and the parent's two widget zones. A feature
the child says nothing about is inherited whole.

## Assets

Stylesheets, scripts and images do **not** walk. `osc_current_web_theme_styles_url()` and
its siblings always point at the active theme, so a child that ships no `css/style.css`
gets nothing rather than the parent's.

This is on purpose: a child usually wants the parent's stylesheet *and* its own, in that
order, which a silent fallback cannot express. Enqueue both:

```php
// CHILD functions.php
osc_enqueue_style(
    'parent-style',
    osc_base_url() . 'oc-content/themes/storefront/css/style.css'
);
osc_enqueue_style(
    'child-style',
    osc_current_web_theme_styles_url('style.css')
);
```

Registering the parent's stylesheet first means the child's rules come after it and win on
equal specificity.

## Things to know

**The parent must stay installed.** Deleting a theme another theme depends on leaves the
child rendering on storefront. The Appearance screen warns before you do it.

**A child is a theme.** It appears in the Appearance list, needs its own `Version`, and
updates on its own schedule.

**Screenshots are not inherited.** Ship a `screenshot.png` or the child shows a blank card.

## See also

- [Package specification](/docs/developers/package-spec/) — every header field
- [Template hierarchy](/docs/developers/template-hierarchy/) — how one view is chosen
- [Theme chrome](/docs/developers/theme-chrome/) — the header/footer pair
- [Scripts and styles](/docs/developers/scripts-and-styles/) — enqueueing assets
