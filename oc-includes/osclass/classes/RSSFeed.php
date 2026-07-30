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
 * Serializes a set of listings into an RSS 2.0 feed.
 *
 * The feed is one of two serializers over the same live-listing set as the XML
 * sitemap (Sitemap::liveItemCondition()): the on-site search already applies the
 * identical live predicate, so category / search / seller feeds fall out of the
 * search filters, and a custom search backend (e.g. Manticore) overrides the item
 * set through the existing before_search / pre_show_items hooks — no RSS-specific
 * seam is required for that. The per-item extension seam is the rss_feed_item
 * filter applied by the caller before addItem().
 *
 * Item arrays passed to addItem() carry raw (unescaped) values; this serializer is
 * responsible for correct XML escaping. Recognised keys:
 *   title, link, description, country, region, city, city_area, category,
 *   dt_pub_date, image (url/title/link — thumbnail rendered into the description),
 *   enclosure (url/type/length — a spec-correct RSS enclosure).
 *
 * @author Shopclass
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

    /**
     * Build the feed and return it as a well-formed XML string.
     *
     * Reusable and testable: dumpXML() is a thin echo wrapper over this.
     *
     * @return string
     */
    public function getXML()
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $dom->appendChild($rss);

        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);

        $channel->appendChild($dom->createElement('title', htmlspecialchars((string)$this->title, ENT_XML1, 'UTF-8')));
        $channel->appendChild($dom->createElement('link', htmlspecialchars((string)$this->link, ENT_XML1, 'UTF-8')));
        $channel->appendChild(
            $dom->createElement('description', htmlspecialchars((string)$this->description, ENT_XML1, 'UTF-8'))
        );

        foreach ($this->items as $item) {
            $node = $dom->createElement('item');
            $channel->appendChild($node);

            $this->appendCData($dom, $node, 'title', $item['title'] ?? '');

            $link = (string)($item['link'] ?? '');
            $node->appendChild($dom->createElement('link', htmlspecialchars($link, ENT_XML1, 'UTF-8')));

            // Stable, canonical permalink GUID (was a bare <link> duplicate before).
            $guid = $dom->createElement('guid', htmlspecialchars($link, ENT_XML1, 'UTF-8'));
            $guid->setAttribute('isPermaLink', 'true');
            $node->appendChild($guid);

            // Spec-correct enclosure for the first item resource (feed readers /
            // search engines consume this instead of the description <img> blob).
            if (!empty($item['enclosure']['url'])) {
                $enclosure = $dom->createElement('enclosure');
                $enclosure->setAttribute('url', (string)$item['enclosure']['url']);
                $enclosure->setAttribute('type', (string)($item['enclosure']['type'] ?? ''));
                $enclosure->setAttribute('length', (string)((int)($item['enclosure']['length'] ?? 0)));
                $node->appendChild($enclosure);
            }

            // Description CDATA: keep the legacy thumbnail anchor+img blob, then body.
            $descHtml = '';
            if (!empty($item['image']) && !empty($item['image']['url'])) {
                $imgLink  = htmlspecialchars((string)($item['image']['link'] ?? ''), ENT_QUOTES, 'UTF-8');
                $imgTitle = htmlspecialchars((string)($item['image']['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                $imgUrl   = htmlspecialchars((string)$item['image']['url'], ENT_QUOTES, 'UTF-8');
                $descHtml .= '<a href="' . $imgLink . '" title="' . $imgTitle . '" rel="nofollow">';
                $descHtml .= '<img style="float:left;border:0px;" src="' . $imgUrl . '" alt="' . $imgTitle . '"/> </a>';
            }
            $descHtml .= (string)($item['description'] ?? '');
            $this->appendCData($dom, $node, 'description', $descHtml);

            // Non-standard, back-compat location elements.
            $this->appendCData($dom, $node, 'country', $item['country'] ?? '');
            $this->appendCData($dom, $node, 'region', $item['region'] ?? '');
            $this->appendCData($dom, $node, 'city', $item['city'] ?? '');
            $this->appendCData($dom, $node, 'cityArea', $item['city_area'] ?? '');
            $this->appendCData($dom, $node, 'category', $item['category'] ?? '');

            $pubDate = isset($item['dt_pub_date']) ? date('r', strtotime($item['dt_pub_date'])) : date('r');
            $node->appendChild($dom->createElement('pubDate', $pubDate));
        }

        return $dom->saveXML();
    }

    /**
     * Emit the feed to the output buffer. Callers that already sent the
     * Content-type header keep working unchanged.
     */
    public function dumpXML()
    {
        echo $this->getXML();
    }

    /**
     * Append a child element carrying a CDATA section, splitting any literal
     * "]]>" so the section can never terminate early.
     *
     * @param DOMDocument $dom
     * @param DOMElement  $parent
     * @param string      $name
     * @param string      $value
     */
    private function appendCData($dom, $parent, $name, $value)
    {
        $element = $dom->createElement($name);
        // Do NOT pre-split a literal "]]>": createCDATASection() already splits
        // it across CDATA boundaries at serialization, so it round-trips exactly
        // and can never terminate the section early. Splitting it by hand as well
        // makes libxml split the replacement a second time and corrupts the value.
        $element->appendChild($dom->createCDATASection((string) $value));
        $parent->appendChild($element);
    }
}
