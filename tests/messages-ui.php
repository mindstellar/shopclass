<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * What messages.js does on the messenger: typing and seen notes, the typing signal, the composer,
 * grouped messages and day lines, the list filter, desktop row clicks, and the Block and Report
 * dialogs. Driven in headless Chrome with a fake clock, fetch and EventSource.
 *
 * Usage: php tests/messages-ui.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/messages-browser.php';

$r = messages_browser_run(__DIR__ . '/lib/messages-ui-driver.js');

harness_section('typing and seen notes');
pin('a typing note shows the bubble, the role line and the row line', array('bubble' => true, 'role' => 'Typing…', 'row' => 'Typing… [typing]'), $r['typingOn'] ?? null);
pin('a typing note causes no fetch', 0, $r['typingFetches'] ?? null);
pin('the indicator stays under 5 s', true, $r['typingStill'] ?? null);
pin('and hides after 5 s, putting back the role and the preview', array('bubble' => false, 'role' => 'Seller', 'row' => 'Preview 7'), $r['typingOff'] ?? null);
pin('a note older than 5 s is ignored', false, $r['staleNote'] ?? null);
pin('their new message hides the indicator', false, $r['theirMessageHides'] ?? null);
pin('a seen note puts Seen under my last message', array('6'), $r['seen'] ?? null);
pin('a seen note causes no fetch', 0, $r['seenFetches'] ?? null);
pin('no Seen mark before the note', 0, $r['seenBefore'] ?? null);
pin('a seen note for another thread changes nothing', array(0, '1'), $r['seenOther'] ?? null);

harness_section('the typing signal');
pin('typing posts once at once, with the CSRF field and id but no body', array(array('POST', array('csrf', 'id'))), $r['typingPost'] ?? null);
pin('no second post within 3 s', 1, $r['typingWithin3s'] ?? null);
pin('one more at 3 s while still typing', 2, $r['typingAt3s'] ?? null);
pin('blur drops the pending post', 2, $r['typingAfterBlur'] ?? null);
pin('after a gap the next key posts at once', 3, $r['typingAfterGap'] ?? null);
pin('sending drops the pending post', 3, $r['typingAfterSend'] ?? null);
pin('with polling: no typing post, no stream, no indicator', array('posts' => 0, 'sources' => 0, 'bubble' => 0), $r['pollTyping'] ?? null);

harness_section('the composer');
pin('send is disabled while the box is empty', true, $r['emptyDisabled'] ?? null);
pin('and enabled with text', false, $r['filledDisabled'] ?? null);
pin('Shift + Enter makes a new line and sends nothing', array(false, 0), $r['shiftEnter'] ?? null);
pin('Enter while an IME is composing sends nothing', array(false, 0), $r['imeEnter'] ?? null);
pin('Enter sends', array(true, 1), $r['enter'] ?? null);
pin('a sent message clears the box and disables send', array('value' => '', 'disabled' => true), $r['afterSend'] ?? null);
pin('Enter on an empty box sends nothing', array(true, 1), $r['enterEmpty'] ?? null);
pin('no counter with more than 100 left', '', $r['counterFar'] ?? null);
pin('the counter shows at 100 left', array('100', false), $r['counterNear'] ?? null);
pin('and is tight at 20 left', array('20', true), $r['counterTight'] ?? null);
pin('the box grows with the text', true, $r['autosize'] ?? null);

harness_section('appended messages');
pin('they join groups and start day lines like the server', array(
    'day:Today',
    '1:is-first,is-last:11:00',
    '2:is-mine,is-first:',
    '3:is-mine,is-last:11:59',
    '4:is-first,is-last:12:30',
    'day:Today',
    '5:is-mine,is-first,is-last,is-held:08:00Awaiting review',
), $r['groups'] ?? null);
pin('message text is set as text', 'b', $r['appendText'] ?? null);
pin('markup in a message stays text and makes no elements', array(0, '<b>x</b><img src="x" onerror="window.__pwned=1">', 0), $r['hostile'] ?? null);
pin('a message already shown is not added again', false, $r['appendTwice'] ?? null);
pin('day lines read the date, Yesterday and Today', array('January 2', 'Yesterday', 'Today'), $r['dayLabels'] ?? null);
pin('the thread opens scrolled to the newest message', true, $r['scrolled'] ?? null);

harness_section('the list filter');
pin('the filter and tabs show with the script', array(true, true), $r['filterShown'] ?? null);
pin('the filter matches name and title, any case', array('8'), $r['filterText'] ?? null);
pin('Unread shows rows with unread messages and is pressed', array(array('8'), 'true'), $r['filterUnread'] ?? null);
pin('All shows every row again', array('7', '8', '9'), $r['filterAll'] ?? null);

harness_section('desktop row clicks');
pin('a row click fetches the thread page', '?page=messages&id=8', $r['navFetch'][0] ?? null);
pin('the chat pane, the current row, the URL and the title follow it', array(
    'conversation' => '8', 'chats' => 1, 'current' => array('8'), 'ariaCurrent' => 1, 'url' => '?page=messages&id=8', 'title' => 'Sam',
), $r['navSwap'] ?? null);
pin('the new composer sends', array(true, 1), $r['navComposer'] ?? null);
pin('back loads the earlier thread', '7', $r['popConversation'] ?? null);
pin('on a phone a row click is a plain link', array(false, 0), $r['phoneNav'] ?? null);

harness_section('the menu dialogs');
pin('Block opens a ready dialog instead of confirm()', array(
    'open' => true, 'ready' => true, 'title' => 'Block this conversation?',
    'text' => 'Neither of you can write until it is unblocked.', 'confirms' => 0, 'menuOpen' => false,
), $r['block'] ?? null);
pin('its Block button posts the block form', array(array('block_post'), false), $r['blockSubmit'] ?? null);
pin('Report opens its form in a ready dialog', array(
    'open' => true, 'ready' => true, 'title' => 'Report conversation', 'text' => 'The site team reviews it.', 'form' => true, 'reportOpen' => false,
), $r['report'] ?? null);
pin('Cancel closes it', false, $r['reportCancel'] ?? null);

exit(harness_result());
