---
title: Administrator menus
description: Add and remove admin panel menu entries from a ShopClass plugin or theme with the osc_add_admin_menu_page helpers.
sidebar:
  order: 5
---

Plugins and themes usually need somewhere in the admin panel for their own
screens. The menu helpers add sections and entries to the administration menu.

## Adding a top-level section

```php
osc_add_admin_menu_page(
    $menu_title,
    $url,
    $menu_id,
    $capability = 'administrator',
    $icon_url   = null,
    $position   = null
);
```

:::caution[Argument order]
`$capability` comes **before** `$icon_url`. Older Osclass documentation had these
two the other way round; passing an icon where a capability is expected silently
hides your menu from every user.
:::

## Adding an entry under it

```php
osc_add_admin_submenu_page(
    $menu_id,
    $submenu_title,
    $url,
    $submenu_id,
    $capability = 'administrator'
);
```

## Removing entries

```php
osc_remove_admin_menu_page($menu_id);
osc_remove_admin_submenu_page($menu_id, $submenu_id);
osc_remove_admin_menu();                              // clears the lot
```

Removing core entries is a blunt instrument — another plugin may be linking to
what you just deleted. Prefer capabilities.

## Adding to an existing core section

Most plugins belong under a section that already exists rather than in one of
their own. There is a helper per core section:

```php
osc_admin_menu_items($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null);
osc_admin_menu_categories(…);
osc_admin_menu_pages(…);
osc_admin_menu_appearance(…);
osc_admin_menu_plugins(…);
osc_admin_menu_settings(…);
osc_admin_menu_tools(…);
osc_admin_menu_users(…);
osc_admin_menu_stats(…);
```

They cover Listings, Categories, Pages, Appearance, Plugins, Settings, Tools,
Users and Statistics.

## Example

```php
function myplugin_admin_menu()
{
    osc_add_admin_menu_page(
        __('My plugin', 'myplugin'),
        osc_admin_render_plugin_url('myplugin/admin/settings.php'),
        'myplugin',
        'administrator'
    );

    osc_add_admin_submenu_page(
        'myplugin',
        __('Settings', 'myplugin'),
        osc_admin_render_plugin_url('myplugin/admin/settings.php'),
        'myplugin_settings',
        'administrator'
    );
}

osc_add_hook('admin_menu_init', 'myplugin_admin_menu');
```

Use a **unique** `$menu_id` — prefix it with your plugin folder. Two plugins
claiming the same id will overwrite each other's menus.
