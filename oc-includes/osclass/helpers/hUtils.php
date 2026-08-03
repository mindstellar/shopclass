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
 * Helper Utils
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Getting from View the $key index
 *
 * @param string $key
 *
 * @return mixed
 */
function __get($key)
{
    return View::newInstance()->_get($key);
}


/**
 * Get variable from $_GET or $_POST
 *
 * @param string $key
 *
 * @return mixed
 */
function osc_get_param($key)
{
    return Params::getParam($key);
}


/**
 * Generic function for view layer, return the $field of $item
 * with specific $locale
 *
 * @param array  $item
 * @param string $field
 * @param string $locale
 *
 * @return string
 */
function osc_field($item, $field, $locale)
{
    if ($item !== null) {
        if ($locale == '') {
            if (isset($item[$field])) {
                return $item[$field];
            }
        } else {
            if (isset($item['locale']) && !empty($item['locale']) && isset($item['locale'][$locale])
                && isset($item['locale'][$locale][$field])
            ) {
                return $item['locale'][$locale][$field];
            }

            if (isset($item['locale'])) {
                foreach ($item['locale'] as $locale2 => $data) {
                    if (isset($item['locale'][$locale2][$field])) {
                        return $item['locale'][$locale2][$field];
                    }
                }
            }
        }
    }

    return '';
}


/**
 * Print all widgets belonging to $location
 *
 * @param string $location
 *
 * @return void
 */
function osc_show_widgets($location)
{
    $widgets = Widget::newInstance()->findByLocation($location);
    foreach ($widgets as $w) {
        osc_render_widget($w);
    }
}


/**
 * Print all widgets named $description
 *
 * @param string $description
 *
 * @return void
 */
function osc_show_widgets_by_description($description)
{
    $widgets = Widget::newInstance()->findByDescription($description);
    foreach ($widgets as $w) {
        osc_render_widget($w);
    }
}


/**
 * Print recaptcha html.
 *
 * Every form gets the same widget. `$section` is only a per-form label kept for the
 * documented signature, so a theme passing one still works.
 *
 * It used to select a 'recover_password' branch that rendered a captcha only when a reset
 * had been requested in the last 20 minutes, recording in the session when it had not so
 * the reset action would skip validating one. Both values were session-scoped, so a client
 * discarding cookies was always on its first attempt and never saw a captcha at all — and
 * because LoginThrottle drops its per-account limit whenever a provider is configured, the
 * reset form ended up with neither. The window bought nothing a cookie jar could not
 * sidestep, so it is gone.
 *
 * @param string $section per-form label; does not change what is rendered
 *
 * @return void
 */
function osc_show_recaptcha($section = '')
{
    if (osc_recaptcha_public_key()) {
        echo _osc_recaptcha_get_html(osc_recaptcha_public_key(), substr(osc_language(), 0, 2)) . '<br />';
    }
}


/**
 * @param $siteKey
 * @param $lang
 */
function _osc_recaptcha_get_html($siteKey, $lang)
{
    echo '<div class="g-recaptcha" data-sitekey="' . $siteKey . '"></div>';
    echo '<script type="text/javascript" src="https://www.google.com/recaptcha/api.js?hl=' . $lang . '"></script>';
}


/**
 * Resolves the active captcha provider for this request.
 *
 * reCAPTCHA and Cloudflare Turnstile sit behind one abstraction. The stored
 * captchaProvider preference selects the leg; the result is memoised per
 * request. Resolution rules:
 *   - 'none'      -> 'none'
 *   - 'turnstile' -> 'turnstile' when both Turnstile keys are set, else 'none'
 *   - 'recaptcha' -> 'recaptcha' when the reCAPTCHA private key is set, else 'none'
 *   - 'auto'/''   -> 'turnstile' when Turnstile is configured AND reCAPTCHA is
 *                    not enabled; else 'recaptcha' when reCAPTCHA is enabled;
 *                    else 'none'.
 *
 * Auto prefers reCAPTCHA when both are configured because core validates
 * g-recaptcha-response natively whenever the reCAPTCHA private key is set:
 * clearing the reCAPTCHA keys is what stands core down and flips auto to
 * Turnstile.
 *
 * @return string 'recaptcha' | 'turnstile' | 'none'
 */
function osc_captcha_provider()
{
    static $provider = null;
    if ($provider !== null) {
        return $provider;
    }

    $recaptcha_enabled = osc_recaptcha_private_key() !== '';

    switch (osc_captcha_provider_pref()) {
        case 'none':
            $provider = 'none';
            break;
        case 'turnstile':
            $provider = osc_turnstile_configured() ? 'turnstile' : 'none';
            break;
        case 'recaptcha':
            $provider = $recaptcha_enabled ? 'recaptcha' : 'none';
            break;
        case 'auto':
        case '':
        default:
            if (osc_turnstile_configured() && !$recaptcha_enabled) {
                $provider = 'turnstile';
            } elseif ($recaptcha_enabled) {
                $provider = 'recaptcha';
            } else {
                $provider = 'none';
            }
            break;
    }

    return $provider;
}


/**
 * Whether a usable captcha provider is active (not 'none').
 *
 * @return bool
 */
function osc_captcha_enabled()
{
    return osc_captcha_provider() !== 'none';
}


/**
 * Whether both Cloudflare Turnstile keys are configured.
 *
 * @return bool
 */
function osc_turnstile_configured()
{
    return osc_turnstile_site_key() !== '' && osc_turnstile_secret_key() !== '';
}


/**
 * Builds the active provider's captcha widget markup as a string.
 *
 * The reCAPTCHA leg buffers the existing osc_show_recaptcha() output verbatim
 * so rendered bytes are unchanged in reCAPTCHA mode; $context is passed through
 * as its $section argument. The Turnstile leg emits the cf-turnstile div and,
 * unless $deferred, the Turnstile api.js loader.
 *
 * @param string $context per-form label. reCAPTCHA leg: osc_show_recaptcha()
 *                        section. Turnstile leg: data-action (omitted when empty).
 * @param bool   $deferred true = widget markup only, no provider <script>.
 *
 * @return string
 */
function osc_captcha_widget_html($context = '', $deferred = false)
{
    switch (osc_captcha_provider()) {
        case 'recaptcha':
            ob_start();
            osc_show_recaptcha($context);

            return ob_get_clean();
        case 'turnstile':
            $html = '<div class="cf-turnstile" data-sitekey="' . osc_esc_html(osc_turnstile_site_key()) . '"';
            if ($context !== '') {
                $html .= ' data-action="' . osc_esc_html($context) . '"';
            }
            $html .= '></div>';
            if (!$deferred) {
                $html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            }

            return $html;
        default:
            return '';
    }
}


/**
 * Echoes the active provider's captcha widget.
 *
 * @param string $context per-form label (see osc_captcha_widget_html()).
 * @param bool   $deferred true = widget markup only, no provider <script>.
 *
 * @return void
 */
function osc_show_captcha($context = '', $deferred = false)
{
    echo osc_captcha_widget_html($context, $deferred);
}


/**
 * The active provider's client script URL.
 *
 * @return string Turnstile/reCAPTCHA api.js URL; '' when no provider is active.
 */
function osc_captcha_script_url()
{
    switch (osc_captcha_provider()) {
        case 'turnstile':
            return 'https://challenges.cloudflare.com/turnstile/v0/api.js';
        case 'recaptcha':
            return 'https://www.google.com/recaptcha/api.js?hl=' . substr(osc_language(), 0, 2);
        default:
            return '';
    }
}


/**
 * Formats the date using the appropiate format.
 *
 * @param string $date
 * @param null   $dateformat
 *
 * @return string
 */
function osc_format_date($date, $dateformat = null)
{
    if ($dateformat == null) {
        $dateformat = osc_date_format();
    }

    $month       = array(
        '',
        __('January'),
        __('February'),
        __('March'),
        __('April'),
        __('May'),
        __('June'),
        __('July'),
        __('August'),
        __('September'),
        __('October'),
        __('November'),
        __('December')
    );
    $month_short = array(
        '',
        __('Jan'),
        __('Feb'),
        __('Mar'),
        __('Apr'),
        __('May'),
        __('Jun'),
        __('Jul'),
        __('Aug'),
        __('Sep'),
        __('Oct'),
        __('Nov'),
        __('Dec')
    );
    $day         = array(
        '',
        __('Monday'),
        __('Tuesday'),
        __('Wednesday'),
        __('Thursday'),
        __('Friday'),
        __('Saturday'),
        __('Sunday')
    );
    $day_short   = array('', __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun'));
    $ampm        = array('AM' => __('AM'), 'PM' => __('PM'), 'am' => __('am'), 'pm' => __('pm'));


    $time       = strtotime($date);
    $dateformat = preg_replace('|(?<!\\\)F|', osc_escape_string($month[date('n', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)M|', osc_escape_string($month_short[date('n', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)l|', osc_escape_string($day[date('N', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)D|', osc_escape_string($day_short[date('N', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)A|', osc_escape_string($ampm[date('A', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)a|', osc_escape_string($ampm[date('a', $time)]), $dateformat);

    return date($dateformat, $time);
}


/**
 * Escapes letters and numbers of a string
 *
 * @param string $string
 *
 * @return string
 * @since 2.4
 */
function osc_escape_string($string)
{
    $string = preg_replace('/^(\d)/', '\\\\\\\\\1', $string);
    $string = preg_replace('/([a-z])/i', '\\\\\1', $string);

    return $string;
}


/**
 * Prints the user's account menu
 *
 * @param array $options array with options of the form array('name' => 'display name', 'url' => 'url of link')
 *
 * @return void
 */
function osc_private_user_menu($options = null)
{
    if ($options == null) {
        $options   = array();
        $options[] = array(
            'name'  => __('Public Profile'),
            'url'   => osc_user_public_profile_url(osc_logged_user_id()),
            'class' => 'opt_publicprofile'
        );
        $options[] = array('name' => __('Dashboard'), 'url' => osc_user_dashboard_url(), 'class' => 'opt_dashboard');
        $options[] =
            array('name' => __('Manage your listings'), 'url' => osc_user_list_items_url(), 'class' => 'opt_items');
        $options[] = array('name' => __('Manage your alerts'), 'url' => osc_user_alerts_url(), 'class' => 'opt_alerts');
        $options[] = array('name' => __('My profile'), 'url' => osc_user_profile_url(), 'class' => 'opt_account');
        $options[] = array('name' => __('Logout'), 'url' => osc_user_logout_url(), 'class' => 'opt_logout');
    }

    $options = osc_apply_filter('user_menu_filter', $options);

    echo '<script type="text/javascript">';
    // Vanilla, and DOMContentLoaded-wrapped so it runs after the list below exists (the
    // old jQuery ran inline before the <ul> and matched nothing). No jQuery dependency.
    echo 'document.addEventListener("DOMContentLoaded",function(){'
         . 'var m=document.querySelector(".user_menu");if(m){'
         . 'if(m.firstElementChild){m.firstElementChild.classList.add("first");}'
         . 'if(m.lastElementChild){m.lastElementChild.classList.add("last");}}});';
    echo '</script>';
    echo '<ul class="user_menu">';

    $var_l = count($options);
    for ($var_o = 0; $var_o < ($var_l - 1); $var_o++) {
        echo '<li class="' . $options[$var_o]['class'] . '" ><a href="' . $options[$var_o]['url'] . '" >'
            . $options[$var_o]['name'] . '</a></li>';
    }

    osc_run_hook('user_menu');

    echo '<li class="' . $options[$var_l - 1]['class'] . '" ><a href="' . $options[$var_l - 1]['url'] . '" >'
        . $options[$var_l - 1]['name'] . '</a></li>';

    echo '</ul>';
}


/**
 * Gets prepared text, with:
 * - higlight search pattern and search city
 * - maxim length of text
 *
 * @param string $txt
 * @param int    $len
 * @param string $start_tag
 * @param string $end_tag
 *
 * @return string
 */
function osc_highlight($txt, $len = 300, $start_tag = '<strong>', $end_tag = '</strong>')
{
    $txt = strip_tags($txt);
    $txt = str_replace(array("\n\r", "\r\n", "\n", "\r", "\t"), ' ', $txt);
    $txt = trim($txt);
    $txt = preg_replace('/\s+/', ' ', $txt);
    if (mb_strlen($txt, 'UTF-8') > $len) {
        $txt = mb_substr($txt, 0, $len, 'UTF-8') . '...';
    }
    $query = osc_search_pattern();
    $query = str_replace(array('(', ')', '+', '-', '~', '>', '<'), array('', '', '', '', '', '', ''), $query);

    $query = str_replace(
        array('\\', '^', '$', '.', '[', '|', '?', '*', '{', '}', '/', ']'),
        array('\\\\', '\\^', '\\$', '\\.', '\\[', '\\|', '\\?', '\\*', '\\{', '\\}', '\\/', '\\]'),
        $query
    );

    $query = preg_replace('/\s+/', ' ', $query);

    $words = array();
    if (preg_match_all('/"([^"]*)"/', $query, $matches)) {
        $l = count($matches[1]);
        for ($k = 0; $k < $l; $k++) {
            $words[] = $matches[1][$k];
        }
    }

    $query = trim(preg_replace('/\s+/', ' ', preg_replace('/"([^"]*)"/', '', $query)));
    $words = array_merge($words, explode(' ', $query));

    foreach ($words as $word) {
        if ($word != '') {
            $txt =
                preg_replace("/(\PL|\s+|^)($word)(\PL|\s+|$)/i", '$01' . $start_tag . '$02' . $end_tag . '$03', $txt);
        }
    }

    return $txt;
}


/**
 * Convert plain-text line breaks into HTML paragraphs and line breaks.
 *
 * A blank line starts a new paragraph; a single newline within a block becomes
 * a line break. Existing block-level markup (<p>, <ul>, <table>, <pre>, <h2>…)
 * is left intact and never paragraph-wrapped, so the function is safe — and
 * stable when re-applied — on content that already contains HTML, such as the
 * markup the WYSIWYG editor stores. This is what lets multi-line page and
 * listing text render as real paragraphs on the public site instead of
 * collapsing onto a single line.
 *
 * @param string $text        the raw stored text
 * @param bool   $line_breaks convert single newlines to <br />
 *
 * @return string
 */
function osc_autop($text, $line_breaks = true)
{
    if ($text === null || trim($text) === '') {
        return '';
    }

    // Block-level tags that stand on their own and must never be wrapped in <p>.
    $blocks = 'table|thead|tfoot|tbody|tr|th|td|caption|col|colgroup'
        . '|ul|ol|li|dl|dt|dd|menu'
        . '|div|section|article|aside|header|footer|nav|figure|figcaption|main|details|summary'
        . '|blockquote|pre|address|hr|fieldset|legend|form|noscript'
        . '|h[1-6]|p';

    // Normalise newlines and give the text a trailing break to simplify matching.
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $text .= "\n";

    // Pull <pre> blocks out of harm's way — their whitespace is significant.
    $stash = array();
    if (stripos($text, '<pre') !== false) {
        $segments = preg_split('#(<pre[^>]*>.*?</pre>)#is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $text     = '';
        foreach ($segments as $i => $segment) {
            if ($i % 2 === 1) {
                // Bare marker so restoration does not depend on surrounding
                // newlines (which the paragraph pass strips).
                $token         = "\x02osc-pre-" . $i . "\x03";
                $stash[$token] = $segment;
                $text         .= "\n\n" . $token . "\n\n";
            } else {
                $text .= $segment;
            }
        }
    }

    // Surround block tags with blank lines so each becomes its own chunk.
    $text = preg_replace('#(<(?:' . $blocks . ')(?:\s[^>]*)?>)#i', "\n\n$1", $text);
    $text = preg_replace('#(</(?:' . $blocks . ')>)#i', "$1\n\n", $text);
    $text = preg_replace('#(<hr\s*/?>)#i', "\n\n$1\n\n", $text);

    // Split on blank lines and wrap the loose text runs in paragraphs.
    $chunks = preg_split('/\n[ \t]*\n/', $text);
    $out    = '';
    foreach ($chunks as $chunk) {
        $trimmed = trim($chunk);
        if ($trimmed === '') {
            continue;
        }
        // Leave chunks that are already block-level markup (or a stash token) alone.
        if (isset($stash[$trimmed])
            || preg_match('#^</?(?:' . $blocks . ')[\s/>]#i', $trimmed)
            || preg_match('#^<!--#', $trimmed)
        ) {
            $out .= $trimmed . "\n\n";
        } else {
            $out .= '<p>' . $trimmed . "</p>\n\n";
        }
    }
    $text = $out;

    // Convert the remaining single newlines inside paragraphs to <br />.
    if ($line_breaks) {
        // Drop newlines that merely hug a block tag — they are structural, not content.
        $text = preg_replace('#(<(?:' . $blocks . ')(?:\s[^>]*)?>)\n+#i', '$1', $text);
        $text = preg_replace('#\n+(</?(?:' . $blocks . ')(?:\s[^>]*)?>)#i', '$1', $text);
        // A lone newline that is neither preceded nor followed by another newline.
        // No trailing newline is kept, so a re-run finds nothing left to convert.
        $text = preg_replace('/(?<!\n)\n(?!\n)/', '<br />', $text);
    }

    // Tidy: kill empty paragraphs and paragraphs that only fence a block element.
    $text = preg_replace('#<p>\s*</p>#i', '', $text);
    $text = preg_replace('#<p>\s*(</?(?:' . $blocks . ')(?:\s[^>]*)?>)#i', '$1', $text);
    $text = preg_replace('#(</?(?:' . $blocks . ')(?:\s[^>]*)?>)\s*</p>#i', '$1', $text);
    $text = preg_replace('#<br />\s*(</?(?:' . $blocks . ')(?:\s[^>]*)?>)#i', '$1', $text);

    // Restore stashed <pre> blocks.
    if (!empty($stash)) {
        $text = str_replace(array_keys($stash), array_values($stash), $text);
    }

    return trim($text);
}


/**
 * Whether this request came from a crawler rather than a person.
 *
 * A denylist, not an allowlist. The allowlist this replaces named the browsers
 * of the day and treated everything matching one as human — but a crawler
 * identifies itself by appending to an ordinary browser string, so the modern
 * Googlebot, Bingbot and GPTBot user agents all contain "Safari" and "like
 * Gecko" and sailed straight through it. Anything the list does not recognise is
 * treated as a person, so a new crawler is counted until its token is added
 * (or added by a plugin through the bot_user_agents filter) rather than a new
 * browser being silently discounted.
 *
 * Deliberately cheap: one lowercase pass and a substring search per token, on a
 * request path that runs for every listing view.
 *
 * @return bool
 */
function osc_is_bot_request()
{
    static $isBot = null;

    if ($isBot !== null) {
        return $isBot;
    }

    $ua = strtolower(trim((string)Params::getServerParam('HTTP_USER_AGENT', false, false)));

    // No user agent at all is not a browser either.
    if ($ua === '') {
        return $isBot = true;
    }

    $tokens = osc_apply_filter('bot_user_agents', array(
        // Generic — catches the long tail, which is most of it.
        'bot', 'crawler', 'crawling', 'spider', 'scraper', 'archiver', 'fetcher',
        // Search engines.
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandex',
        'sogou', 'exabot', 'seznambot', 'petalbot', 'applebot', 'qwantify',
        // AI and dataset collectors.
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'ccbot', 'claudebot',
        'claude-web', 'anthropic-ai', 'perplexitybot', 'google-extended',
        'bytespider', 'amazonbot', 'meta-externalagent', 'diffbot',
        // SEO and marketing crawlers.
        'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'blexbot', 'dataforseo',
        'screaming frog', 'serpstat', 'megaindex',
        // Monitoring, previews and libraries.
        'uptimerobot', 'pingdom', 'statuscake', 'facebookexternalhit',
        'telegrambot', 'whatsapp', 'slackbot', 'discordbot', 'embedly',
        'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'java/', 'okhttp', 'libwww-perl', 'headlesschrome', 'phantomjs',
    ));

    foreach ($tokens as $token) {
        if ($token !== '' && strpos($ua, $token) !== false) {
            return $isBot = true;
        }
    }

    return $isBot = false;
}


/**
 * Whether this request should be counted in the listing view statistics.
 *
 * @return bool
 */
function osc_request_counts_as_view()
{
    if (!osc_item_views_enabled()) {
        return false;
    }

    return !osc_is_bot_request() || osc_count_bot_views();
}


/**
 *
 */
function osc_get_http_referer()
{
    $ref = Rewrite::newInstance()->get_http_referer();
    if ($ref != '') {
        return $ref;
    }

    if (Session::newInstance()->_getReferer() != '') {
        return Session::newInstance()->_getReferer();
    } elseif (Params::existServerParam('HTTP_REFERER')) {
        if (filter_var(Params::getServerParam('HTTP_REFERER', false, false), FILTER_VALIDATE_URL)) {
            return Params::getServerParam('HTTP_REFERER', false, false);
        }
    }

    return '';
}


/**
 * The unguessable token that ties temp photo uploads on a listing form to the browser that
 * made them, without a session. Read from (or minted into) the `oc_upload` cookie once per
 * request; it is the capability {@see ItemTmpUpload} checks so a visitor can only delete the
 * photos they uploaded.
 *
 * @return string 32 hex characters
 */
function osc_upload_token()
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }

    $existing = $_COOKIE['oc_upload'] ?? '';
    if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing)) {
        return $token = $existing;
    }

    try {
        $token = bin2hex(random_bytes(16));
    } catch (\Exception $e) {
        $token = md5(uniqid('', true));
    }

    if (!headers_sent()) {
        $options = array(
            // A posting session — long enough to fill out a listing, short enough to expire.
            'expires'  => time() + (4 * 3600),
            'path'     => defined('REL_WEB_URL') ? REL_WEB_URL : '/',
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if (function_exists('osc_is_ssl') && osc_is_ssl()) {
            $options['secure'] = true;
        }
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
            $options['domain'] = COOKIE_DOMAIN;
        }
        setcookie('oc_upload', $token, $options);
    }
    $_COOKIE['oc_upload'] = $token;

    return $token;
}


/**
 * Remember where a visitor came from across the login POST without a session.
 *
 * The login form used to stash the referer in $_SESSION so it could send the user back
 * after signing in — but that started a physical session on a mere GET of the login page,
 * leaving even a visitor who never logs in carrying an osclass cookie that defeats
 * reverse-proxy caching. Instead, carry the destination in a short-lived, HMAC-signed
 * cookie: set here on the login page, consumed and cleared by osc_pop_login_redirect() on
 * the login POST. Only a same-site URL (and never the login page itself) is stored, so
 * there is no open-redirect surface; the signature is defence in depth.
 *
 * @param string $url
 * @param bool   $keepExisting keep an already-stored destination instead of overwriting it,
 *                             so an explicit target (e.g. "post a listing") set before the
 *                             redirect to login survives the ambient referer captured when
 *                             the login page itself loads
 *
 * @return void
 */
function osc_set_login_redirect($url, $keepExisting = false)
{
    osc_set_signed_redirect('oc_login_redirect', $url, $keepExisting);
}


/**
 * Read, validate and clear the login-redirect cookie set by osc_set_login_redirect().
 *
 * Returns the stored same-site destination, or '' when absent, tampered, expired or
 * off-site. The cookie is always deleted so it is single-use.
 *
 * @return string
 */
function osc_pop_login_redirect()
{
    return osc_pop_signed_redirect('oc_login_redirect');
}


/**
 * Admin counterpart of osc_set_login_redirect(), under its own cookie so the front-end and
 * admin flows never collide. Used by the admin login page and by the admin auth gate, which
 * remembers the protected page an unauthenticated admin was trying to reach.
 *
 * @param string $url
 * @param bool   $keepExisting keep an already-stored destination instead of overwriting it
 *
 * @return void
 */
function osc_set_admin_login_redirect($url, $keepExisting = false)
{
    osc_set_signed_redirect('oc_admin_login_redirect', $url, $keepExisting);
}


/**
 * Admin counterpart of osc_pop_login_redirect(). Single-use.
 *
 * @return string
 */
function osc_pop_admin_login_redirect()
{
    return osc_pop_signed_redirect('oc_admin_login_redirect');
}


/**
 * Store a same-site destination in a short-lived, HMAC-signed standalone cookie — the shared
 * core behind the login/admin-login redirect helpers. Never starts a session.
 *
 * @param string $cookieName
 * @param string $url
 * @param bool   $keepExisting skip when a valid destination is already stored under this name
 *
 * @return void
 */
function osc_set_signed_redirect($cookieName, $url, $keepExisting = false)
{
    if (!is_string($url) || $url === '' || strpos($url, osc_base_url()) !== 0) {
        return;
    }
    // Never store a login page — it would only bounce the visitor back to the form.
    if (strpos($url, 'page=login') !== false) {
        return;
    }
    if ($keepExisting && osc_signed_redirect_verify($_COOKIE[$cookieName] ?? '') !== '') {
        return;
    }

    // 10 minutes: long enough to complete a login, short enough to expire promptly.
    $expiry  = time() + 600;
    $payload = $expiry . ':' . base64_encode($url);
    $value   = $payload . '.' . hash_hmac('sha256', $payload, \mindstellar\security\SigningKey::get());

    osc_write_signed_redirect_cookie($cookieName, $value, $expiry);
    $_COOKIE[$cookieName] = $value;
}


/**
 * Read, validate and clear a signed-redirect cookie. Always deletes it (single-use).
 *
 * @param string $cookieName
 *
 * @return string same-site destination, or '' when absent, tampered, expired or off-site
 */
function osc_pop_signed_redirect($cookieName)
{
    $value = $_COOKIE[$cookieName] ?? '';
    if ($value !== '') {
        osc_write_signed_redirect_cookie($cookieName, '', time() - 3600);
        unset($_COOKIE[$cookieName]);
    }

    return osc_signed_redirect_verify($value);
}


/**
 * Verify a signed-redirect cookie value and return its same-site URL, or '' if the value is
 * absent, tampered, expired or off-site. Does not touch the cookie.
 *
 * @param string $value
 *
 * @return string
 */
function osc_signed_redirect_verify($value)
{
    if (!is_string($value) || $value === '' || strpos($value, '.') === false) {
        return '';
    }
    $dot     = strrpos($value, '.');
    $payload = substr($value, 0, $dot);
    $sig     = substr($value, $dot + 1);
    if (!hash_equals(hash_hmac('sha256', $payload, \mindstellar\security\SigningKey::get()), $sig)) {
        return '';
    }
    $parts = explode(':', $payload, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int)$parts[0] < time()) {
        return '';
    }
    $url = base64_decode($parts[1], true);
    if ($url === false || strpos($url, osc_base_url()) !== 0) {
        return '';
    }

    return $url;
}


/**
 * Write (or, with a past expiry, delete) a standalone signed-redirect cookie. Standalone —
 * not the session container — so it never starts a session.
 *
 * @param string $cookieName
 * @param string $value
 * @param int    $expiry
 *
 * @return void
 */
function osc_write_signed_redirect_cookie($cookieName, $value, $expiry)
{
    if (headers_sent()) {
        return;
    }
    $options = array(
        'expires'  => $expiry,
        'path'     => defined('REL_WEB_URL') ? REL_WEB_URL : '/',
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if (function_exists('osc_is_ssl') && osc_is_ssl()) {
        $options['secure'] = true;
    }
    if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
        $options['domain'] = COOKIE_DOMAIN;
    }
    setcookie($cookieName, $value, $options);
}


/**
 * @param        $id
 * @param        $regexp
 * @param        $url
 * @param        $file
 * @param bool   $user_menu
 * @param string $location
 * @param string $section
 * @param string $title
 */
function osc_add_route(
    $id,
    $regexp,
    $url,
    $file,
    $user_menu = false,
    $location = 'custom',
    $section = 'custom',
    $title = 'Custom'
) {
    Rewrite::newInstance()->addRoute($id, $regexp, $url, $file, $user_menu, $location, $section, $title);
}


/**
 * Register a controller route dispatched by class instead of by file.
 *
 * Use this for an endpoint that acts and redirects rather than rendering: unlike
 * osc_add_route()'s file-backed routes, a hook route runs its handler without first
 * emitting the theme's custom.php chrome. Link to it with osc_route_url($id).
 *
 * @param string $id
 * @param string $regexp
 * @param string $url
 */
function osc_add_route_hook($id, $regexp, $url)
{
    Rewrite::newInstance()->addRouteHook($id, $regexp, $url);
}


/**
 *
 */
function osc_get_subdomain_params()
{
    $options = array();
    if (osc_subdomain_name() != '') {
        if (Params::getParam('sCountry') != '') {
            $options['sCountry'] = Params::getParam('sCountry');
        }
        if (Params::getParam('sRegion') != '') {
            $options['sRegion'] = Params::getParam('sRegion');
        }
        if (Params::getParam('sCity') != '') {
            $options['sCity'] = Params::getParam('sCity');
        }
        if (Params::getParam('sCategory') != '') {
            $options['sCategory'] = Params::getParam('sCategory');
        }
        if (Params::getParam('sUser') != '') {
            $options['sUser'] = Params::getParam('sUser');
        }
    }

    return $options;
}

/**
 * Get Google Analytics tracking ID.
 *
 * @return string
 */
function osc_google_analytics_id()
{
    return osc_get_preference('ga_tracking_id');
}

/**
 * Get Google Maps API key.
 *
 * @return string
 */
function osc_google_maps_api_key()
{
    return osc_get_preference('googlemaps_api_key');
}

/**
 * Get Open Street Maps API key.
 *
 * @return string
 */
function osc_openstreet_api_key()
{
    return osc_get_preference('openstreet_api_key');
}

/**
 * Get Google Maps geocode URL.
 *
 * @return string
 */
function osc_google_maps_geocode_url($address)
{
    return 'https://maps.googleapis.com/maps/api/geocode/json?address='
        . urlencode($address) . '&sensor=false&key='.osc_google_maps_api_key();
}

/**
 * Get OpenStreetMaps geocode URL.
 *
 * @return string
 */
function osc_openstreet_geocode_url($address)
{
    return 'https://www.mapquestapi.com/geocoding/v1/address?location='
        . urlencode($address) . '&key='.osc_openstreet_api_key();
}


/**
 * Get URL of location files JSON.
 *
 * @return string
 */
function osc_get_locations_json_url()
{
    return 'https://raw.githubusercontent.com/mindstellar/geodata/master/src/json-list.json';
}


/**
 * Get URL of location SQL.
 *
 * @param string $location
 *
 * @return string
 */
function osc_get_locations_sql_url($location)
{
    $location = rawurlencode($location);

    return 'https://raw.githubusercontent.com/mindstellar/Shopclass-Extras/master/locations/' . $location;
}

/**
 * Get i18n repository URL.
 * @return string
 */
function osc_get_i18n_repository_url($path = '')
{
    // Check if version tring contain dev,alpha,beta,RC set is_dev to 1
    $is_dev = false;
    // try str_replace to remove all version tags from string if string changed than it's dev
    $version = str_replace(array('dev', 'alpha', 'beta', 'rc'), '', strtolower(osc_version()));
    // if version string changed than it's dev
    if ($version !== osc_version()) {
        $is_dev = true;
    }
    if ($is_dev) {
        // get url of local_list.json from github
        $repoUrl = 'https://raw.githubusercontent.com/mindstellar/i10n-osclass/develop/';
    } else {
        $repoUrl = 'https://raw.githubusercontent.com/mindstellar/i10n-osclass/master/';
    }
    if ($path === '') {
        $path = 'locale_list.json';
    }
    ltrim($path, '/');
    $path = rawurlencode($path);

    return $repoUrl . $path;
}
