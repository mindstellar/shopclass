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
 * Class Breadcrumb
 */
class Breadcrumb
{
    protected $aLevel;
    private $location;
    private $section;
    private $title;

    /**
     * Breadcrumb constructor.
     *
     * @param array $lang
     */
    public function __construct($lang = array())
    {
        $this->location = Rewrite::newInstance()->get_location();
        $this->section  = Rewrite::newInstance()->get_section();
        $this->aLevel   = array();
        $this->setTitles($lang);
    }

    /**
     * Set the texts for the breadcrumb
     *
     * @param $lang
     *
     * @since 3.1
     *
     */
    public function setTitles($lang)
    {
        // default titles
        $this->title['item_add']               = __('Publish a listing');
        $this->title['item_edit']              = __('Edit your listing');
        $this->title['item_send_friend']       = __('Send to a friend');
        $this->title['item_contact']           = __('Contact publisher');
        $this->title['search']                 = __('Search results');
        $this->title['search_pattern']         = __('Search results: %s');
        $this->title['user_dashboard']         = __('Dashboard');
        $this->title['user_dashboard_profile'] = __("%s's profile");
        $this->title['user_account']           = __('Account');
        $this->title['user_items']             = __('My listings');
        $this->title['user_alerts']            = __('My alerts');
        $this->title['user_profile']           = __('Update my profile');
        $this->title['user_change_email']      = __('Change my email');
        $this->title['user_change_username']   = __('Change my username');
        $this->title['user_change_password']   = __('Change my password');
        $this->title['login']                  = __('Login');
        $this->title['login_recover']          = __('Recover your password');
        $this->title['login_forgot']           = __('Change your password');
        $this->title['register']               = __('Create a new account');
        $this->title['contact']                = __('Contact');


        if (!is_array($lang)) {
            return;
        }

        foreach ($lang as $k => $v) {
            if (array_key_exists($k, $this->title)) {
                $this->title[$k] = $v;
            }
        }
    }

    public function init()
    {
        if (in_array(
            $this->getLocation(),
            array('item', 'page', 'search', 'login', 'register', 'user', 'contact', 'custom')
        )
        ) {
            $l = array(
                'url'   => osc_base_url(),
                'title' => __('Home')
            );
            $this->addLevel($l);
        }

        switch ($this->getLocation()) {
            case ('item'):
                if ($this->getSection() === 'item_add') {
                    $l = array('title' => $this->title['item_add']);
                    $this->addLevel($l);
                    break;
                }

                try {
                    $aCategory = osc_get_category('id', osc_item_category_id());
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                }
                // remove
                View::newInstance()->_erase('categories');
                View::newInstance()->_erase('subcategories');
                View::newInstance()->_exportVariableToView('category', $aCategory);

                try {
                    $l = array(
                        'url'   => osc_search_category_url(),
                        'title' => osc_category_name()
                    );
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                }
                $this->addLevel($l);

                switch ($this->getSection()) {
                    case ('item_edit'):
                        try {
                            $l = array('url' => osc_item_url(), 'title' => osc_item_title());
                        } catch (Exception $e) {
                            trigger_error($e->getMessage(), E_USER_WARNING);
                        }
                        $this->addLevel($l);
                        $l = array('title' => $this->title['item_edit']);
                        $this->addLevel($l);
                        break;
                    case ('send_friend'):
                        try {
                            $l = array('url' => osc_item_url(), 'title' => osc_item_title());
                        } catch (Exception $e) {
                            trigger_error($e->getMessage(), E_USER_WARNING);
                        }
                        $this->addLevel($l);
                        $l = array('title' => $this->title['item_send_friend']);
                        $this->addLevel($l);
                        break;
                    case ('contact'):
                        try {
                            $l = array('url' => osc_item_url(), 'title' => osc_item_title());
                        } catch (Exception $e) {
                            trigger_error($e->getMessage(), E_USER_WARNING);
                        }
                        $this->addLevel($l);
                        $l = array('title' => $this->title['item_contact']);
                        $this->addLevel($l);
                        break;
                    case (''):
                        $l = array('title' => osc_item_title());
                        $this->addLevel($l);
                        break;
                    default:
                        $l = array('title' => Rewrite::newInstance()->get_title());
                        $this->addLevel($l);
                        break;
                }
                break;
            case ('search'):
                $region  = osc_search_region();
                $city    = osc_search_city();
                $pattern = osc_search_pattern();
                try {
                    $category = osc_search_category_id();
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                }
                $category = ((count($category) == 1) ? $category[0] : '');

                $b_show_all = ($pattern == '' && $category == '' && $region == '' && $city == '');
                $b_category = ($category != '');
                $b_pattern  = ($pattern != '');
                $b_region   = ($region != '');
                $b_city     = ($city != '');
                $b_location = ($b_region || $b_city);

                // show all
                if ($b_show_all) {
                    $l = array('title' => $this->title['search']);
                    $this->addLevel($l);
                    break;
                }

                // category
                if ($b_category) {
                    try {
                        $aCategories = Category::newInstance()->toRootTree($category);
                    } catch (Exception $e) {
                        trigger_error($e->getMessage(), E_USER_WARNING);
                    }
                    foreach ($aCategories as $c) {
                        View::newInstance()->_erase('categories');
                        View::newInstance()->_erase('subcategories');
                        View::newInstance()->_exportVariableToView('category', $c);

                        try {
                            $l = array(
                                'url'   => osc_search_category_url(),
                                'title' => osc_category_name()
                            );
                        } catch (Exception $e) {
                        }
                        $this->addLevel($l);
                    }
                }

                // location
                if ($b_location) {
                    $params = array();
                    if ($b_category) {
                        $params['sCategory'] = $category;
                    }

                    if ($b_city) {
                        $aCity = array();
                        if ($b_region) {
                            $_region = Region::newInstance()->findByName($region);
                            if (isset($_region['pk_i_id'])) {
                                $aCity = City::newInstance()->findByName($city, $_region['pk_i_id']);
                            }
                        } else {
                            $aCity = City::newInstance()->findByName($city);
                        }

                        if (count($aCity) == 0) {
                            $params['sCity'] = $city;
                            try {
                                $l = array(
                                    'url'   => osc_search_url($params),
                                    'title' => $city
                                );
                            } catch (Exception $e) {
                                trigger_error($e->getMessage(), E_USER_WARNING);
                            }
                            $this->addLevel($l);
                        } else {
                            $aRegion = Region::newInstance()->findByPrimaryKey($aCity['fk_i_region_id']);

                            $params['sRegion'] = $aRegion['s_name'];
                            try {
                                $l = array(
                                    'url'   => osc_search_url($params),
                                    'title' => $aRegion['s_name']
                                );
                            } catch (Exception $e) {
                                trigger_error($e->getMessage(), E_USER_WARNING);
                            }
                            $this->addLevel($l);

                            $params['sCity'] = $aCity['s_name'];
                            try {
                                $l = array(
                                    'url'   => osc_search_url($params),
                                    'title' => $aCity['s_name']
                                );
                            } catch (Exception $e) {
                                trigger_error($e->getMessage(), E_USER_WARNING);
                            }
                            $this->addLevel($l);
                        }
                    } elseif ($b_region) {
                        $params['sRegion'] = $region;
                        try {
                            $l = array(
                                'url'   => osc_search_url($params),
                                'title' => $region
                            );
                        } catch (Exception $e) {
                            trigger_error($e->getMessage(), E_USER_WARNING);
                        }
                        $this->addLevel($l);
                    }
                }

                // pattern
                if ($b_pattern) {
                    $l = array('title' => sprintf($this->title['search_pattern'], $pattern));
                    $this->addLevel($l);
                }

                // remove url from the last node
                $nodes = $this->getaLevel();
                if (($nodes > 0) && array_key_exists('url', $nodes[count($nodes) - 1])) {
                    unset($nodes[count($nodes) - 1]['url']);
                }
                $this->setaLevel($nodes);
                break;
            case ('user'):
                // use dashboard without url if you're in the dashboards
                if ($this->getSection() === 'dashboard') {
                    $l = array('title' => $this->title['user_dashboard']);
                    $this->addLevel($l);
                    break;
                }

                // use dashboard without url if you're in the dashboards
                if ($this->getSection() === 'pub_profile') {
                    $l = array('title' => sprintf($this->title['user_dashboard_profile'], osc_user_name()));
                    $this->addLevel($l);
                    break;
                }

                $l = array(
                    'url'   => osc_user_dashboard_url(),
                    'title' => $this->title['user_account']
                );
                $this->addLevel($l);

                switch ($this->getSection()) {
                    case ('items'):
                        $l = array('title' => $this->title['user_items']);
                        $this->addLevel($l);
                        break;
                    case ('alerts'):
                        $l = array('title' => $this->title['user_alerts']);
                        $this->addLevel($l);
                        break;
                    case ('profile'):
                        $l = array('title' => $this->title['user_profile']);
                        $this->addLevel($l);
                        break;
                    case ('change_email'):
                        $l = array('title' => $this->title['user_change_email']);
                        $this->addLevel($l);
                        break;
                    case ('change_password'):
                        $l = array('title' => $this->title['user_change_password']);
                        $this->addLevel($l);
                        break;
                    case ('change_username'):
                        $l = array('title' => $this->title['user_change_username']);
                        $this->addLevel($l);
                        break;
                    default:
                        $l = array('title' => Rewrite::newInstance()->get_title());
                        $this->addLevel($l);
                        break;
                }
                break;
            case ('login'):
                switch ($this->getSection()) {
                    case ('recover'):
                        $l = array('title' => $this->title['login_recover']);
                        $this->addLevel($l);
                        break;
                    case ('forgot'):
                        $l = array('title' => $this->title['login_forgot']);
                        $this->addLevel($l);
                        break;
                    case (''):
                        $l = array('title' => $this->title['login']);
                        $this->addLevel($l);
                        break;
                }
                break;
            case ('register'):
                $l = array('title' => $this->title['register']);
                $this->addLevel($l);
                break;
            case ('page'):
                $l = array('title' => osc_static_page_title());
                $this->addLevel($l);
                break;
            case ('contact'):
                $l = array('title' => $this->title['contact']);
                $this->addLevel($l);
                break;
            case ('custom'):
                $l = array('title' => Rewrite::newInstance()->get_title());
                $this->addLevel($l);
                break;
        }
    }

    /**
     * @return string
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
     * @param $location
     */
    public function setLocation($location)
    {
        $this->location = $location;
    }

    /**
     * @param $level
     */
    public function addLevel($level)
    {
        if (!is_array($level)) {
            return;
        }
        $this->aLevel[] = $level;
    }

    /**
     * @return string
     */
    public function getSection()
    {
        return $this->section;
    }

    /**
     * @param $section
     */
    public function setSection($section)
    {
        $this->section = $section;
    }

    /**
     * @return array
     */
    public function getaLevel()
    {
        return $this->aLevel;
    }

    /**
     * @param $aLevel
     */
    public function setaLevel($aLevel)
    {
        $this->aLevel = $aLevel;
    }

    /**
     * @param string $separator
     *
     * @return string
     */
    public function render($separator = '&raquo;')
    {
        if (count($this->aLevel) == 0) {
            return '';
        }

        $node = array();
        foreach ($this->aLevel as $i => $iValue) {
            // set a class style for first and last <li>
            $class = '';
            if ($i == 0) {
                $class .= 'class="first-child" ';
            }
            if (($i == (count($this->aLevel) - 1)) && ($i != 0)) {
                $class .= 'class="last-child" ';
            }

            $text = '<li ' . $class . ' itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">';

            // set separator
            if ($i > 0) {
                $text .= ' ' . $separator . ' ';
            }

            // create anchor/span tag
            if (array_key_exists('url', $iValue)) {
                $title = '<a itemprop="item" href="' . osc_esc_html($iValue['url']) . '">';
                $title .= '<span itemprop="name">' . $iValue['title'] . '</span>';
                $title .= '</a>';
            } else {
                $title = '<span itemprop="name">' . $iValue['title'] . '</span>';
            }

            $node[] = $text . $title . '</li>' . PHP_EOL;
        }

        $result = '<ul class="breadcrumb">' . PHP_EOL;
        $result .= implode(PHP_EOL, $node);
        $result .= '</ul>' . PHP_EOL;

        return $result;
    }
}

/* file end: ./oc-includes/osclass/classes/Breadcrumb.php */
