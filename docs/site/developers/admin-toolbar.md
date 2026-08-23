---
title: Admin toolbar
description: Add shortcuts to the ShopClass admin toolbar from a plugin using AdminToolbar and the add_admin_toolbar_menus hook.
sidebar:
  order: 6
---

The toolbar across the top of the admin panel holds shortcuts — publish a
listing, jump to the front end, see pending comments. Plugins can add their own
nodes to it.

## Adding a node

```php
AdminToolbar::newInstance()->add_menu(array(
    'id'    => 'my-node',
    'title' => 'My shortcut',
    'href'  => osc_admin_render_plugin_url('myplugin/admin/index.php'),
    'meta'  => array('class' => 'my-node', 'target' => '_blank'),
));
```

| Key | Meaning |
|---|---|
| `id` | Unique identifier for the node. Prefix it with your plugin folder. |
| `title` | The visible label. HTML is accepted, so escape anything user-supplied. |
| `href` | Where it links. Optional — omit for a plain label. |
| `meta` | Extra attributes: `class`, `onclick`, `target`, `title`, `tabindex`. |

## Hooking it up

Nodes must be added while the toolbar is being built, which is what the
`add_admin_toolbar_menus` hook is for:

```php
function myplugin_toolbar()
{
    AdminToolbar::newInstance()->add_menu(array(
        'id'    => 'myplugin-home',
        'title' => osc_page_title(),
        'href'  => osc_base_url(),
        'meta'  => array('class' => 'user-profile', 'target' => '_blank'),
    ));
}

osc_add_hook('add_admin_toolbar_menus', 'myplugin_toolbar', 0);
```

The third argument is priority — lower runs earlier, so `0` puts your node near
the front of the bar.

## Keep it to one

The toolbar is shared by every plugin on the install and it is narrow. One node
per plugin, with a short label; put everything else behind an
[admin menu entry](/docs/developers/admin-menus/).
