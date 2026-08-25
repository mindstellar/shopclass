---
title: Upgrade an Osclass site
description: Step-by-step upgrade path from Osclass 3.x, 5.x or 5.2.2 to ShopClass — what carries over, what to check, and how to roll back.
sidebar:
  order: 3
---

ShopClass is Osclass, continued under its own name. Upgrading is **not** a
migration: there is no export, no re-import and no rebuild. It is the same
application continuing at a higher version number, against the same database.

For the history — why the rename happened and what became of the old project —
see [what happened to Osclass](/osclass/). This page is the mechanical upgrade.

## Which path is yours

| You are on | What to do |
|---|---|
| **Osclass 5.2.2** | Nothing special. 5.2.2 points its built-in updater at the ShopClass releases — open **Admin → Tools → Update** and take the update as you always have. |
| **Osclass 5.0 – 5.2.1** | Update to 5.2.2 first through the built-in updater, then let it carry you across to 6.x. |
| **Osclass 3.x** | Update up the 3.x line to 3.9.0, then to the 5.x line, then to 5.2.2. Each step migrates the schema; skipping steps does not. |
| **Nothing yet** | Skip Osclass entirely — [install ShopClass](/docs/install/). |

:::caution[Back up before the first step, not after the third]
Copy the database and `oc-content/` while the site is still working. An upgrade
chain has several stopping points, and a backup is only useful if it predates
all of them.
:::

## Unpacking a release by hand

If you are moving a long-frozen install and would rather not chain updaters,
unpack the ShopClass release over the site the way
[Updating ShopClass](/docs/updating/) describes, then reconcile the schema:

```bash
php oc-cli.php db:upgrade
```

This is the same migration the updater runs. It repairs a drifted schema first,
which matters on old installs where a plugin once added or dropped a column.

## What carries over untouched

- **Your database** — listings, users, categories, comments, custom fields and
  preferences all stay where they are. The table prefix does not change.
- **Your uploads** — everything under `oc-content/uploads/`.
- **The extension API** — the `osc_*` helper functions, hook names, admin CSS
  class names and `oc-includes/assets/` paths are treated as a public API and
  were deliberately not renamed.
- **Your URLs** — permalink structure is unchanged, so your search rankings are
  not affected by the upgrade.

## What is worth testing

Extensions that reach past the public API into legacy internals, or that assume
jQuery is loaded in the admin panel, may need attention — the core no longer
loads jQuery on the front end and the admin theme is Bootstrap 5.

Test on a copy of the site first:

1. Clone the database and files to a staging URL.
2. Upgrade the copy.
3. Walk the paths that matter to you — publish a listing, register a user, run a
   search, open each admin screen a plugin adds.
4. Note anything that breaks, then upgrade production.

If a third-party theme is the problem, switch to the bundled one to confirm:

```bash
php oc-cli.php theme:list
php oc-cli.php theme:activate --theme=storefront
```

## Rolling back

Restore the database dump and the file copy you took at the start. Because the
upgrade never rewrites your content, a rollback is a plain restore — there is no
data to un-migrate.

## Frequently asked

**Do I need a new licence or account?**
No. ShopClass is GPLv3 and free, the same as Osclass was, with no account and no
paid tier.

**Will my Osclass plugins keep working?**
Most do — the extension API was kept deliberately. Plugins that used
undocumented internals are the exception. Test on a copy.

**Does my site have to change domain or URLs?**
No. Only the software's name changed.
