<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * AdminToolbar class
 *
 * @since      3.0
 * @package    Shopclass
 * @subpackage classes
 * @author     Shopclass
 */
class AdminToolbar
{
    private static $instance;
    private $nodes = array();

    public function __construct()
    {
    }

    /**
     * @return \AdminToolbar
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function init()
    {
    }

    /**
     * Add toolbar menus and add menus running hook add_admin_toolbar_menus
     */
    public function add_menus()
    {
        // User related, aligned right.
        //osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_menu', 0);
        osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_comments', 0);
        osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_spam', 0);

        osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_update_core', 0);

        osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_update_themes', 0);
        osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_update_plugins', 0);
        osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_update_languages', 0);

        //osc_add_hook('add_admin_toolbar_menus', 'osc_admin_toolbar_logout', 0);

        osc_run_hook('add_admin_toolbar_menus');
    }

    /**
     * Add a node to the menu.
     *
     * @param $array
     *
     * @todo implement parent nodes
     *
     */
    public function add_menu($array)
    {
        if (isset($array['id'])) {
            $this->nodes[$array['id']] = (object)$array;
        }
    }

    /**
     * Add a submenu to the menu.
     *
     * @param $array $args - The arguments for each subitem.
     *               - id         - string    - The ID of the mainitem.
     *               - parentid   - string    - The ID of the parent item.
     *               - title      - string    - The title of the node.
     *               - href       - string    - The link for the item. Optional.
     *               - meta       - array     - Meta data including the following keys: html, class, onclick, target,
     *               title, tabindex.
     *               - target     - string    - _blank
     */
    public function add_submenu($array)
    {
        if (isset($array['parentid'], $array['id'])) {
            $this->nodes[$array['parentid']]->submenu[$array['id']] = (object)$array;
        }
    }

    /**
     * Remove entry with id $id
     *
     * @param string $id
     */
    public function remove_menu($id)
    {
        unset($this->nodes[$id]);
    }

    /**
     * Remove entry with id $id
     *
     * @param string $parentid
     * @param string $id
     */
    public function remove_submenu($parentid, $id)
    {
        if (isset($this->nodes[$parentid], $this->nodes[$parentid]->submenu[$id])) {
            unset($this->nodes[$parentid]->submenu[$id]);
        }
    }

    /**
     * Render admin toolbar
     *
     * <div>
     *   <a></a>
     * </div>
     */
    public function render()
    {
        if (count($this->nodes) > 0) {
            foreach ($this->nodes as $value) {
                $hasSubmenu = false;
                if (isset($value->submenu) && is_array($value->submenu)) {
                    $hasSubmenu = true;
                }
                $meta = '';
                if (isset($value->meta)) {
                    foreach ($value->meta as $k => $v) {
                        if ($k === 'class') {
                            $v = "nav-link " . $v;
                            if ($hasSubmenu) {
                                $v .= ' dropdown';
                            }
                        }
                        $meta .= $k . '="' . $v . '" ';
                    }
                }
                echo '<li class="nav-item" id="osc_toolbar_' . $value->id . '" ><a ' . $meta . ' href="' . $value->href . '" '
                     . ((isset($value->target)) ? 'target="' . $value->target . '"' : '') . '>' . $value->title . '</a>';
                if ($hasSubmenu === true) {
                    echo '<ul class="osc_admin_submenu" id="osc_toolbar_sub_' . $value->id . '">';
                        //echo '<ul class="osc_admin_submenu" id="osc_toolbar_sub_' . $value->id . '"></ul>';
                    foreach ($value->submenu as $subvalue) {
                        if (isset($subvalue->subid)) {
                            $submeta = '';
                            if (isset($subvalue->meta)) {
                                foreach ($subvalue->meta as $sk => $sv) {
                                    $submeta .= $sk . '="' . $sv . '" ';
                                }
                            }
                            echo '<li><a ' . $submeta . ' href="' . $subvalue->href . '" ' . ((isset($subvalue->target))
                                    ? 'target="' . $subvalue->target . '"' : '') . '>' . $subvalue->title . '</a><li>';
                        }
                    }


                    echo '</ul>';
                }
                echo '</li>';
            }
            osc_run_hook('render_admintoolbar');
        }
    }
}
