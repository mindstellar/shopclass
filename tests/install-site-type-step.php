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

function _n($single, $plural, $count)
{
    return (int) $count === 1 ? $single : $plural;
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

$sample = static function (string $html): string {
    return preg_match('/<input[^>]*id="sample_data"[^>]*>/', $html, $m) ? $m[0] : '';
};
$group = static function (string $html): string {
    return preg_match('/<div class="ins-addons-pay".*?<\/fieldset>/s', $html, $m) ? $m[0] : '';
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
        'stripe'   => array($release('0.1.0')),
        'paypal'   => array($release('0.1.0')),
        'razorpay' => array($release('0.1.0')),
        'tax-gst'  => array($release('0.1.0')),
        'promotions' => array($release('0.2.0', '99.0.0')),
    ),
    array('shipping' => array('name' => 'Shipping <b>', 'short_description' => 'Delivery zones')),
);

harness_section('offline');
$html = $render(new ShopSetup(array(), array(), array(), array()));
check('the step posts to step 4 with the nonce', str_contains($html, 'name="step" value="4"') && str_contains($html, 'value="nonce123"'));
check('both cards and their text', str_contains($html, 'Visitors post their own ads: buy and sell, jobs, homes, cars.') && str_contains($html, 'You sell your own products, with a cart and checkout.'));
check('the change-later note', str_contains($html, 'You can switch later in the admin.'));
check('the Shop card cannot be chosen', str_contains($shopRadio($html), 'disabled'));
check('it says why', str_contains($html, 'Needs internet. You can switch to shop mode later in the admin.'));
check('no plugin list', !str_contains($html, 'ins-shop-plugins'));
check('classifieds is picked', (bool) preg_match('/value="classifieds" checked/', $html));
check('the example content switch, unticked', (bool) preg_match('/<input type="checkbox"[^>]*id="sample_data"[^>]*role="switch"[^>]*value="1"\s*\/>/', $html));
check('the switch has no checked attribute', !str_contains($sample($html), 'checked'));
check('"Install sample data" is gone', !str_contains($html, 'Install sample data'));
check('the bar says the database is connected', str_contains($html, 'Database connected'));

harness_section('Aisle listed but no release this core can run');
$tooNew = new ShopSetup(array('aisle' => array($release('2.0.0', '99.0.0'))), array('aisle' => array('slug' => 'aisle')), array(), array());
check('the Shop card cannot be chosen', str_contains($shopRadio($render($tooNew)), 'disabled'));

harness_section('online');
$html = $render($online);
check('the Shop card can be chosen', $shopRadio($html) !== '' && !str_contains($shopRadio($html), 'disabled'));
check('no offline note', !str_contains($html, 'Needs internet'));
check('the plugin list starts hidden while classifieds is picked', (bool) preg_match('/id="ins-shop-plugins"\s+hidden/', $html));
check('shipping is offered and ticked', str_contains($box($html, 'shipping'), 'checked'));
check('stripe, paypal and razorpay are offered in that order, all unticked', array_column($online->offer()['plugins'], 'slug') === array('shipping', 'tax', 'stripe', 'paypal', 'razorpay')
    && array_column(array_filter($online->offer()['plugins'], static fn (array $p): bool => in_array($p['slug'], array('stripe', 'paypal', 'razorpay'), true)), 'checked') === array(false, false, false));
check('a gateway the catalogue lacks is left out', array_search('paypal', array_column((new ShopSetup(array('aisle' => array($release('1.0.0'))), array('aisle' => array('slug' => 'aisle')), array('stripe' => array($release('0.1.0'))), array()))->offer()['plugins'], 'slug'), true) === false);
check('razorpay is offered unticked', $box($html, 'razorpay') !== '' && !str_contains($box($html, 'razorpay'), 'checked'));
check('a plugin the catalogue lacks is not offered', $box($html, 'analytics') === '');
check('a plugin Aisle does not ask for is not offered', $box($html, 'tax-gst') === '');
check('a plugin with no release this core can run is not offered', $box($html, 'promotions') === '');
$pay = $group($html);
check('the payments sit in .ins-addons-pay, labelled Take payments', $pay !== '' && str_contains($pay, 'id="ins-pay-label"') && str_contains($pay, 'Take payments'));
check('stripe, paypal and razorpay are inside it', $box($pay, 'stripe') !== '' && $box($pay, 'paypal') !== '' && $box($pay, 'razorpay') !== '');
check('the others are not inside it', $box($pay, 'shipping') === '' && $box($pay, 'tax') === '');
check('the payments come after the others', strpos($html, 'ins-plugin-razorpay') > strpos($html, 'ins-plugin-tax') && strpos($html, 'ins-addons-pay') > strpos($html, 'ins-plugin-tax'));
check('payments are unticked by default', !str_contains($box($html, 'stripe'), 'checked') && !str_contains($box($html, 'paypal'), 'checked') && !str_contains($box($html, 'razorpay'), 'checked'));
check('our text replaces the catalogue text', str_contains($html, 'Rates by zone and weight') && !str_contains($html, 'Delivery zones') && str_contains($html, 'Sales tax and VAT rules') && str_contains($html, 'Cards, Apple Pay, Google Pay') && str_contains($html, 'PayPal and cards') && str_contains($html, 'UPI and cards, India'));
check('the sample data switch has role=switch', str_contains($sample($html), 'role="switch"'));
check('the section is called Add to your shop', str_contains($html, 'Add to your shop'));
check('the boxes fold into one closed summary line that counts the ticked ones', (bool) preg_match('/<details class="ins-addons-pick">\s*<summary>\s*<span id="ins-addons-count"[^>]*>2 of 5 add-ons selected<\/span>\s*<span class="ins-addons-change" aria-hidden="true">Change<\/span>/', $html));
check('every box sits inside the summary, so all of them are still posted', preg_match('/<details class="ins-addons-pick">.*<\/details>/s', $html, $m) === 1 && substr_count($m[0], 'name="shop_plugins[]"') === 5);
check('the count follows a saved choice', str_contains($render($online, array('type' => 'shop', 'plugins' => array('stripe'))), '1 of 5 add-ons selected'));
check('the Shop card has no New badge', !str_contains($html, 'ins-badge'));
check('ShopSetup::PAYMENTS lists the three gateways', ShopSetup::PAYMENTS === array('stripe', 'paypal', 'razorpay'));
check('catalogue names are escaped', str_contains($html, 'Shipping &lt;b&gt;') && !str_contains($html, 'Shipping <b>'));

harness_section('a slug we have no text for');
$odd = new ShopSetup(
    array('aisle' => array($release('1.0.0'))),
    array('aisle' => array('slug' => 'aisle')),
    array('shipping' => array($release('0.1.0'))),
    array('shipping' => array('name' => 'Shipping', 'short_description' => 'Catalogue words'))
);
check('it keeps the catalogue text for the known slug', str_contains($render($odd), 'Rates by zone and weight'));
$fb = static function (): string {
    $setup = new ShopSetup(
        array('aisle' => array(array('version' => '1.0.0', 'requires' => '7.0.0', 'requires_php' => '8.0', 'url' => 'https://github.com/x/1.zip', 'sha256' => str_repeat('a', 64)))),
        array('aisle' => array('slug' => 'aisle')),
        array(),
        array()
    );
    $offer = $setup->offer();
    $offer['plugins'] = array(array('slug' => 'mystery', 'name' => 'Mystery <i>', 'description' => 'From the catalogue', 'checked' => false));
    $chosen = array('type' => 'shop', 'sample' => false, 'plugins' => null);
    ob_start();
    include LIB_PATH . 'osclass/installer/gui/install-site-type.php';

    return (string) ob_get_clean();
};
$html = $fb();
check('an unknown slug shows the catalogue text', str_contains($html, 'From the catalogue'));
check('and its name, escaped', str_contains($html, 'Mystery &lt;i&gt;'));
check('with no payments there is no payments group', !str_contains($html, 'ins-addons-pay'));

harness_section('online, coming back with Shop picked');
$html = $render($online, array('type' => 'shop', 'sample' => true, 'plugins' => array('razorpay')));
check('Shop stays picked', str_contains($shopRadio($html), 'checked'));
check('the plugin list shows', !preg_match('/id="ins-shop-plugins"\s+hidden/', $html));
check('the earlier ticks are kept', str_contains($box($html, 'razorpay'), 'checked') && !str_contains($box($html, 'shipping'), 'checked'));
check('sample data stays ticked', str_contains($sample($html), 'checked'));

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
foreach (array('Needs internet. You can switch to shop mode later in the admin.') as $msg) {
    check('core.pot has "' . $msg . '"', str_contains($pot, 'msgid "' . $msg . '"'));
}

harness_section('shared check row');
$stSrc = (string) file_get_contents(LIB_PATH . 'osclass/installer/gui/install-site-type.php');
check('the addon box uses the helper', !str_contains($stSrc, '<label class="ins-check"') && str_contains($stSrc, 'install_check('));
check('the addon box keeps its name and value', str_contains($box($render($online, array('plugins' => array('shipping'))), 'shipping'), 'name="shop_plugins[]" value="shipping" checked="checked"'));
$guiFiles = glob(LIB_PATH . 'osclass/installer/gui/*.php') ?: array();
$byHand   = array_filter($guiFiles, static fn (string $f): bool => basename($f) !== 'fields.php' && str_contains((string) file_get_contents($f), 'label class="ins-check"'));
check('no gui file writes label class="ins-check" by hand', $byHand === array());

exit(harness_result());
