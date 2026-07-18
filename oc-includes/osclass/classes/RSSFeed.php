<?php

/*
 * This file is part of Osclass (Mindstellar).
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
 * This class takes items descriptions and generates a RSS feed from that information.
 *
 * @author Osclass
 */
class RSSFeed
{
    private $title;
    private $link;
    private $description;
    private $items;

    public function __construct()
    {
        $this->items = array();
    }

    /**
     * @param $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @param $link
     */
    public function setLink($link)
    {
        $this->link = $link;
    }

    /**
     * @param $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * @param $item
     */
    public function addItem($item)
    {
        $this->items[] = $item;
    }

    public function dumpXML()
    {
        echo '<?xml version="1.0" encoding="UTF-8"?>', PHP_EOL;
        echo '<rss version="2.0">', PHP_EOL;
        echo '<channel>', PHP_EOL;
        echo '<title>', $this->title, '</title>', PHP_EOL;
        echo '<link>', $this->link, '</link>', PHP_EOL;
        echo '<description>', $this->description, '</description>', PHP_EOL;
        foreach ($this->items as $item) {
            echo '<item>', PHP_EOL;
            echo '<title><![CDATA[', $item['title'], ']]></title>', PHP_EOL;
            echo '<link>', $item['link'], '</link>', PHP_EOL;
            echo '<guid>', $item['link'], '</guid>', PHP_EOL;

            echo '<description><![CDATA[';
            if (isset($item['image']) && !empty($item['image'])) {
                echo '<a href="' . $item['image']['link'] . '" title="' . $item['image']['title'] . '" rel="nofollow">';
                echo '<img style="float:left;border:0px;" src="' . $item['image']['url'] . '" alt="'
                    . $item['image']['title'] . '"/> </a>';
            }
            echo $item['description'], ']]>';
            echo '</description>', PHP_EOL;

            echo '<country><![CDATA[', $item['country'], ']]></country>', PHP_EOL;
            echo '<region><![CDATA[', $item['region'], ']]></region>', PHP_EOL;
            echo '<city><![CDATA[', $item['city'], ']]></city>', PHP_EOL;
            echo '<cityArea><![CDATA[', $item['city_area'], ']]></cityArea>', PHP_EOL;
            echo '<category><![CDATA[', $item['category'], ']]></category>', PHP_EOL;

            echo '<pubDate>', date('r', strtotime($item['dt_pub_date'])), '</pubDate>', PHP_EOL;

            echo '</item>', PHP_EOL;
        }
        echo '</channel>', PHP_EOL;
        echo '</rss>', PHP_EOL;
    }
}
