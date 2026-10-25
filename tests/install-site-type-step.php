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
 * Pins the installer's "What kind of site?" step: the Shop card is greyed out offline, with no
 * compatible Aisle, or when package installs are off; online it lists the shop plugins the
 * catalogue has, with the agreed boxes ticked. Also what the step keeps in the session.
 *
 * DB-free.  Usage: php tests/install-site-type-step.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');
define('OSCLASS_VERSION', '7.1.0');

$GLOBALS['installsOff'] = false;

function osc_market_changes_blocked()
{
    return $GLOBALS['installsOff'];
}

function __($text)
{
    return $text;
}

function _e($text)
{
    echo $text;
}

require_once LIB_PATH . 'vendor/autoload.php';
require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\install\ShopSetup;

$GLOBALS['install_nonce'] = 'nonce123';

$render = static function (ShopSetup $setup, array $chosen = array()): string {
    $offer  = $setup->offer();
    $chosen = $chosen + array('type' => 'classifieds', 'sample' => false, 'plugins' => null);
    ob_start();
    include LIB_PATH . 'osclass/installer/gui/install-site-type.php';

    return (string) ob_get_clean();
};
$shopRadio = static function (string $html): string {
    return preg_match('/<input type="radio" name="site_type" value="shop"[^>]*>/', $html, $m) ? $m[0] : '';
};
$box = static function (string $html, string $slug): string {
    return preg_match('/<input type="checkbox" id="ins-plugin-' . $slug . '"[^>]*>/', $html, $m) ? $m[0] : '';
};

$release = static fn (string $version, string $requires = '7.0.0'): array => array(
    'version' => $version, 'requires' => $requires, 'requires_php' => '8.0', 'url' => 'https://github.com/x/' . $version . '.zip', 'sha256' => str_repeat('a', 64),
);
$online = new ShopSetup(
    array('aisle' => array($release('1.0.0'))),
    array('aisle' => array('slug' => 'aisle', 'name' => 'Aisle')),
    array(
        'shipping' => array($release('0.1.1')),
        'tax'      => array($release('0.1.0')),
        'razorpay' => array($release('0.1.0')),
        'tax-gst'  => array($release('0.1.0')),
        'promotions' => array($release('0.2.0', '99.0.0')),
    ),
    array('shipping' => array('name' => 'Shipping <b>', 'short_description' => 'Delivery zones')),
);

harness_section('offline');
$html = $render(new ShopSetup(array(), array(), array(), array()));
check('the step posts to step 4 with the nonce', str_contains($html, 'name="step" value="4"') && str_contains($html, 'value="nonce123"'));
check('both cards and their text', str_contains($html, 'Local buy and sell, jobs, property, cars, services') && str_contains($html, 'A fashion or gift shop, a bakery, a craft or digital goods store'));
check('the change-later note', str_contains($html, 'You can change this later in the admin.'));
check('the Shop card cannot be chosen', str_contains($shopRadio($html), 'disabled'));
check('it says why', str_contains($html, 'Needs internet. You can switch to shop mode later in the admin.'));
check('no plugin list', !str_contains($html, 'ins-shop-plugins'));
check('classifieds is picked', (bool) preg_match('/value="classifieds" checked/', $html));
check('the sample data box, unticked', str_contains($html, 'id="sample_data" name="sample_data" value="1"  />'));

harness_section('Aisle listed but no release this core can run');
$tooNew = new ShopSetup(array('aisle' => array($release('2.0.0', '99.0.0'))), array('aisle' => array('slug' => 'aisle')), array(), array());
check('the Shop card cannot be chosen', str_contains($shopRadio($render($tooNew)), 'disabled'));

harness_section('online');
$html = $render($online);
check('the Shop card can be chosen', $shopRadio($html) !== '' && !str_contains($shopRadio($html), 'disabled'));
check('no offline note', !str_contains($html, 'Needs internet'));
check('the plugin list starts hidden while classifieds is picked', (bool) preg_match('/id="ins-shop-plugins"\s+hidden/', $html));
check('shipping is offered and ticked', str_contains($box($html, 'shipping'), 'checked'));
check('razorpay is offered unticked', $box($html, 'razorpay') !== '' && !str_contains($box($html, 'razorpay'), 'checked'));
check('a plugin the catalogue lacks is not offered', $box($html, 'analytics') === '');
check('a plugin Aisle does not ask for is not offered', $box($html, 'tax-gst') === '');
check('a plugin with no release this core can run is not offered', $box($html, 'promotions') === '');
check('catalogue names are escaped', str_contains($html, 'Shipping &lt;b&gt;') && !str_contains($html, 'Shipping <b>'));

harness_section('online, coming back with Shop picked');
$html = $render($online, array('type' => 'shop', 'sample' => true, 'plugins' => array('razorpay')));
check('Shop stays picked', str_contains($shopRadio($html), 'checked'));
check('the plugin list shows', !preg_match('/id="ins-shop-plugins"\s+hidden/', $html));
check('the earlier ticks are kept', str_contains($box($html, 'razorpay'), 'checked') && !str_contains($box($html, 'shipping'), 'checked'));
check('sample data stays ticked', str_contains($html, 'name="sample_data" value="1" checked'));

harness_section('package installs turned off');
$GLOBALS['installsOff'] = true;
$html = $render($online);
check('the Shop card cannot be chosen', str_contains($shopRadio($html), 'disabled'));
check('it says installs are off', str_contains($html, 'Package installs are turned off on this server.'));
$GLOBALS['installsOff'] = false;

harness_section('install_save_site_choice');
require_once LIB_PATH . 'osclass/install-functions.php';
$_SESSION = array();
Params::setParam('site_type', 'shop');
Params::setParam('sample_data', '1');
Params::setParam('shop_plugins', array('shipping', 'analytics', '../../evil', array('x')));
@install_save_site_choice($online->offer());
$s = Session::getInstance();
check('shop is kept when offered', $s->_get('install_site_type') === 'shop');
check('sample data is kept', $s->_get('install_sample_data') === 1);
check('only offered plugins are kept', $s->_get('install_shop_plugins') === array('shipping'));
@install_save_site_choice((new ShopSetup(array(), array(), array(), array()))->offer());
check('shop is not kept when it was not offered', $s->_get('install_site_type') === 'classifieds');
check('and no plugins are kept', $s->_get('install_shop_plugins') === array());

harness_section('translations');
$pot = (string) file_get_contents(ABS_PATH . 'oc-content/languages/core.pot');
foreach (array('What kind of site?', 'Local buy and sell, jobs, property, cars, services', 'Needs internet. You can switch to shop mode later in the admin.', 'Install sample data') as $msg) {
    check('core.pot has "' . $msg . '"', str_contains($pot, 'msgid "' . $msg . '"'));
}

exit(harness_result());
