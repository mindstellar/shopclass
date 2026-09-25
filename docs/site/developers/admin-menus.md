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
`$capability` comes **before** `$icon_url`. Older Osclass documentation had
these two the other way round. Passing an icon where a capability is expected
silently hides your menu from every user.
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

Removing core entries is a blunt instrument: another plugin may be linking to
what you just deleted. Use capabilities to hide a menu instead.

## Adding to an existing core section

Most plugins belong under a section that already exists, not one of their
own. There is a helper for each core section:

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

Use a **unique** `$menu_id`. Prefix it with your plugin folder — two plugins
claiming the same id overwrite each other's menus.

:::caution[Settings screens]
Pointing a menu at a hand-written `admin/settings.php` is deprecated for
settings. [Declare a settings page](/docs/developers/settings-pages/) instead
— core adds its menu entry for you. For a screen that edits one record, build
it from the [editor components](/docs/developers/admin-editors/).
:::

## The header of your own screen

Core draws the page header before your screen's file runs, so the file cannot add to it.
Declare it when the plugin loads instead, for a screen on an admin route:

```php
osc_add_hook('init_admin', function () {
    osc_admin_plugin_page('myplugin-records', array(
        'title'   => __('Records', 'myplugin'),
        'help'    => __('What this screen is for, in a sentence or two.', 'myplugin'),
        'actions' => array(
            array('icon' => 'bi-plus-circle-fill', 'url' => osc_route_admin_url('myplugin-records-edit'), 'title' => __('Add', 'myplugin')),
        ),
    ));
});
```

`title` is the browser title. `help` puts the **?** beside the heading, as core screens
have; it may be a callable that prints the help. `actions` are the icon buttons beside it.

Group a plugin's entries under Plugins with a divider, as core groups its own:

```php
osc_add_admin_submenu_divider('plugins', __('My plugin', 'myplugin'), 'myplugin', 'administrator');
```
