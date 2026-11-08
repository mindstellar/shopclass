---
title: Categories
description: "Build and manage the ShopClass category tree: adding, nesting, reordering, disabling and translating categories, and what happens to listings when you change one."
sidebar:
  order: 2
---

Every listing belongs to exactly one category. Categories drive browsing,
filtering, [forms](/docs/use/forms-and-custom-fields/) and much of your URL
structure. They are the most consequential structural decision on the site.

**Listings → Categories** in the admin panel.

## The Categories screen

Categories are one tree table. Each row has a status (blank when enabled,
**Disabled** when not), the name, the number of subcategories and listings
(a parent counts the listings of everything below it), and how long its
listings live.

- **Tabs** show All, Enabled and Disabled, each with a count.
- **Find a category** searches names. Matches are highlighted and their
  parents stay visible in grey. When nothing matches, you can clear the
  search or add the text as a new category.
- The arrow beside a name opens or closes its subcategories. **Expand all**
  and **Collapse all** do the whole tree.
- The footer shows how many categories, groups and listings you have.

Row actions appear when you hover a row or Tab into it: **Edit**, **Add
subcategory**, **Move to…**, **Disable** (or **Enable**), and **Delete**.
On a phone every row has a **⋯** button that opens the same actions in a
sheet.

## Adding a category

**Add category** makes a top-level category. **Add subcategory** on a row
makes one inside it. In the edit panel, **Parent** can be changed at any
time. A category needs a name per active language; the URL slug is derived
from the name.

Put listings in the leaf category, not the parent. Nesting can go as deep as
you like, unless your theme sets a limit (see [Too deep](#too-deep)).

## Reordering and nesting

Drag a row by its handle. A dashed slot shows where it will land and says
**Inside Vehicles** or **Top level**. Drag up or down to pick the place, and
left or right to change the depth, one level at a time. A row moves with its
subcategories. Hover over a closed group for a moment and it opens. Press
**Esc** to cancel.

- **Keyboard:** focus the handle, then use ↑ and ↓ to move within the group, →
  to put the category under the one above, and ← to move it out. The arrows
  swap in right-to-left languages.
- **Phone:** press and hold the handle for a moment, then drag.
- **Move to…** picks a parent and a position from a list. Use it for long
  moves.
- Every move is announced, and a toast offers **Undo**. If saving fails, the
  row goes back.

Reordering is off while a search or a tab other than All is on, because a
partial list makes the order ambiguous. Clear the search and filter first.

This is the order visitors see when browsing, so put your busiest categories
at the top rather than leaving them alphabetical.

### Too deep

Some themes show only a few levels of categories. A theme that says so sets
the limit for the whole site: dragging deeper shows **Too deep**, the
keyboard and **Move to…** refuse, and **Add subcategory** is greyed out on a
row at the limit. The REST API agrees. Categories already deeper than the
limit stay where they are and are marked. Nothing moves by itself. Developers:
see [theme chrome](/docs/developers/theme-chrome/#category-depth).

## Bulk actions

Tick rows, then choose **Bulk actions**: **Enable**, **Disable**, **Move
to…**, **Set expiry…** or **Delete**. If you tick a category and one of its
subcategories, the subcategory is counted once, inside its parent.

## Listing counts

The number of listings shown for each category is recounted once a day. It
can go wrong in between after an import or a direct database change.
**Recount listings**, in the footer, counts them again now.

## Disabling versus deleting

| Action | What happens to listings in it |
|---|---|
| **Disable** | The category, **every subcategory at any depth** and their listings stop appearing on the front end. Nothing is destroyed, and enabling it brings it all back. |
| **Delete** | The category is removed **along with its subcategories and every listing in them**, including expired and unapproved ones. |

Disabling a category that has subcategories or listings asks first and says
how many are affected. A category with neither is disabled at once. A
disabled or enabled category can be undone from the toast. A category cannot
be enabled while its parent is disabled.

Deleting asks first and states the cost on the button, for example **Delete 8
categories and 30 listings**. When there are listings, it offers **Disable
instead**.

:::danger[Deleting a category deletes its listings]
There is no undo for a delete. If you are reorganising, disable the
category, move the listings out, and only then delete it.
:::

## Renaming and URLs

Renaming a category changes its slug (the name as it appears in web
addresses), and therefore the URL of its browse page. ShopClass keeps a
slug history so old category URLs keep working, but external links and your
own search rankings still point at the old address for a while.

Rename early, or not at all.

## Per-category settings

**Edit** opens a panel with the category's own rules:

- **Listings expire**: how long a listing in this category lives. This is
  where listing expiry is set, not in the global listing settings. Choose
  **Never**, 30, 60 or 90 days, or **Other**. Never means listings in this
  category do not expire.
- **Listings already posted** appears when you change the expiry. **Keep their
  end dates** (the default) changes only new and renewed listings. **Give
  them new end dates** sets each listing to end that many days after it was
  published, and **Never** removes the end dates. The panel counts how many
  listings would end at once.
- **Also apply to its N subcategories**: off by default. Tick it to give every
  subcategory the same setting, and the same choice for its listings.
- **Show the price field on listings in this category**: whether the
  price field appears at all.

**Set expiry…** in Bulk actions does the same for many categories at once.
It shows the current value, or **Mixed** when the selection differs. Saving
a category no longer re-dates its listings unless you choose **Give them new
end dates**.

Plugins can add their own per-category settings to the panel too.

## Fields per category

Fields are attached to categories through forms, not directly. A *Vehicles*
form holding Make, Model, Year and Fuel Type is attached to your vehicle
categories, and its fields then appear on the publish form and in filters
for those categories only.

See [forms and custom fields](/docs/use/forms-and-custom-fields/).

## Designing the tree

A few rules that hold up:

- **Match your visitors' words, not your industry's.** "Cars" beats
  "Automotive vehicles" every time.
- **Do not create a category you cannot fill.** Twelve empty categories
  look abandoned; three busy ones look alive. Add more as volume justifies
  them.
- **Keep it shallow.** Every extra level is another click between a
  visitor and a listing.
- **Fewer, broader categories plus good filters** beats a deep tree.
  Filters are what actually narrow a search: that is what forms and fields
  are for.
