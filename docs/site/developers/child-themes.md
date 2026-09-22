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

## Make one, start to finish

Say you want storefront with your own colours and a different listing card.

**1. Make the folder.** Anything under `oc-content/themes/`. The name is the slug, so keep
it lowercase and hyphenated.

```
oc-content/themes/storefront-blue/
```

**2. Give it an `index.php`.** This file is the header block and nothing else — the child
does not need to render anything.

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

At this point it is already a working theme. It looks exactly like storefront.

**3. Change what you want.** Each of these is optional and independent:

| To change | Do this |
|---|---|
| A page's markup | Copy that view from the parent and edit it |
| The styling | Add a stylesheet — see [Assets](#assets) |
| What a parent function returns | Redeclare it in `functions.php` — see [functions.php](#functionsphp) |

**4. Try it before you switch.** Appearance lists the child with a **Preview** link, which
is `index.php?theme=storefront-blue`. That renders the whole site in the child for you
only — signed-in admins — while visitors keep seeing the live theme. Activate when it
looks right.

**5. Add a screenshot** so the Appearance card is not a placeholder. `screenshot.png`,
`.jpg` or `.webp`, in the child's own folder — screenshots are not inherited.

## Parent Theme

`Parent Theme` is the parent's **directory name**, not its display name. `storefront`, not
`Storefront`. It must be a plain name: a value carrying a slash or `..` is refused before
it reaches the filesystem, and one naming a theme that is not installed is skipped.

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
| `css/`, `js/`, images | yes | Per file — the child's copy, else the parent's |

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

Appearance checks for this before you switch: a theme whose `functions.php` declares a
name its parent declares **unguarded** carries a warning on its card, naming the
functions. A guarded name is not reported — that one is the override working.

**The guard belongs on the parent, and only there.** Putting one in the child does
nothing: the child loads first, so `function_exists()` is always false at that point, the
function is declared anyway, and the parent still hits the fatal.

So:

- **Writing a parent theme:** wrap every function in `function_exists()`. Every one. It is
  the only thing that lets a child override anything.
- **Writing a child theme:** do not redeclare a function the parent does not guard, and do
  not bother guarding your own. Prefix them with your theme's slug so they cannot collide.

### A worked example

Storefront wraps all 29 of its functions, so any of them can be replaced. To change how a
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

Stylesheets, scripts and images walk like views do. `osc_current_web_theme_url()`,
`osc_current_web_theme_styles_url()` and `osc_current_web_theme_js_url()` each answer
**per file**: the child's copy when it ships one, the parent's when it does not.

So there are two ways to change the styling.

**Replace the sheet.** Ship a file at the same path as the parent's and yours is served
instead. Nothing to register — the walk finds it.

```
storefront/css/style.css        <- parent
storefront-blue/css/style.css   <- yours wins
```

Check the exact filename the parent asks for. A theme that prefers a minified build
requests `style.min.css`, and a child shipping only `style.css` will not be matched.

**Add on top.** Keep the parent's sheet and enqueue your own after it, so your rules come
last and win on equal specificity:

```php
// CHILD functions.php
function storefront_blue_styles()
{
    osc_enqueue_style('storefront-blue', osc_current_web_theme_styles_url('child.css'));
}
// The parent enqueues on `header` at priority 5, so a later number prints after it.
osc_add_hook('header', 'storefront_blue_styles', 6);
```

This is usually what you want: the parent keeps updating its stylesheet and you carry only
your differences.

## Writing a parent that children can actually extend

Most of what a child can override depends on the parent having been written for it. None
of this costs anything; all of it is invisible until somebody tries.

**Guard every function.** This is the big one. A child cannot replace a function the
parent declares unguarded — redeclaring is a fatal, and because the child loads first it
is the parent that crashes. Appearance names the clashes on the child's card, so an
unguarded parent shows up as somebody else's theme being marked broken.

```php
if (!function_exists('mytheme_listing_card')) {
    function mytheme_listing_card($item) { /* … */ }
}
```

Every function. One unguarded name is one thing no child can ever change.

**Guard every constant.** Two `define()` calls for the same name is a PHP warning on every
request, and the child's value is the one that stands whatever the parent intended.

```php
if (!defined('MYTHEME_VERSION')) {
    define('MYTHEME_VERSION', '1.2.0');
}
```

**Never resolve an asset against your own directory.** Core resolves each asset per file,
child first. A parent that picks the *filename* by looking in `__DIR__` defeats that:

```php
// WRONG — decides on the parent's folder, then asks for that name
$min = str_replace('.css', '.min.css', $file);
if (file_exists(__DIR__ . '/' . $min)) { $file = $min; }
return osc_current_web_theme_url($file);
```

A child shipping `css/style.css` is never consulted here, because the parent already
decided the name is `style.min.css`. Check the same stack the URL walks, or pick one
filename and stay with it.

**Use the theme URL helpers, never a hardcoded slug.** `osc_current_web_theme_url()`,
`osc_current_web_theme_styles_url()` and `osc_current_web_theme_js_url()` all walk. A
literal `oc-content/themes/mytheme/…` pins the child to the parent's files forever.

**Enqueue on a hook with a priority**, so a child can add its own sheet after yours:

```php
osc_add_hook('header', 'mytheme_enqueue_base', 5);
```

**Fire hooks and filters at the points somebody will want to change** — a filter around
the price string, the card markup, the meta line. A child that can use a filter never has
to redeclare a function, which sidesteps the guard problem entirely.

**One page, one view file.** A child replaces whole files, so a page built from several
small views can be customised a piece at a time. A theme that renders everything from one
file forces a child to copy all of it and inherit none of your updates.

Storefront follows all of this: all 29 of its functions are guarded, it enqueues on `header` at
priority 5, and it resolves assets through the helpers. It is worth reading as a model.

## Things to know

**The parent must stay installed.** Deleting a theme another theme depends on leaves the
child rendering on storefront. The Appearance screen warns before you do it.

**A child is a theme.** It appears in the Appearance list, needs its own `Version`, and
updates on its own schedule.

**Screenshots are not inherited.** Ship a `screenshot.png`, `.jpg` or `.webp` in the
child's own folder, or the Appearance card falls back to a generic placeholder.

**Preview is admin-only, and only names an installed theme.** `?theme=` is ignored for
visitors, so it cannot be used to force a theme on anyone, and a name that is not an
installed theme is ignored rather than followed.

## See also

- [Package specification](/docs/developers/package-spec/) — every header field
- [Template hierarchy](/docs/developers/template-hierarchy/) — how one view is chosen
- [Theme chrome](/docs/developers/theme-chrome/) — the header/footer pair
- [Scripts and styles](/docs/developers/scripts-and-styles/) — enqueueing assets
