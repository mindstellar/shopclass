<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/**
 * The address book -- markup only. Reads `addresses`, `address_form` (the address being edited or
 * the fields posted back) and `address_errors` (field => message). Every POST is addresses_post.
 */

$addrList   = (array) __get('addresses');
$addrForm   = (array) __get('address_form');
$addrErrors = (array) __get('address_errors');
$addrId     = (string) ($addrForm['id'] ?? '');
$addrFull   = $addrId === '' && count($addrList) >= \mindstellar\user\AddressBook::MAX;
$addrCountries = array();
foreach ((array) osc_get_countries() as $addrRow) {
    $addrCountries[(string) $addrRow['pk_c_code']] = (string) $addrRow['s_name'];
}
$addrPost = static function (string $do, string $id, string $label, string $class = '', string $confirm = ''): void { ?>
    <form class="oe-inline-form nocsrf" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="page" value="user" />
        <input type="hidden" name="action" value="addresses_post" />
        <input type="hidden" name="do" value="<?php echo osc_esc_html($do); ?>" />
        <input type="hidden" name="id" value="<?php echo osc_esc_html($id); ?>" />
        <button class="oe-link-btn<?php echo $class !== '' ? ' ' . osc_esc_html($class) : ''; ?>" type="submit"<?php
            echo $confirm !== '' ? ' data-osc-confirm="' . osc_esc_html($confirm) . '"' : ''; ?>><?php echo osc_esc_html($label); ?></button>
    </form>
<?php };
$addrField = static function (string $name, string $label, string $autocomplete, int $max, bool $required = true) use ($addrForm, $addrErrors): void {
    $id    = 'oe-addr-' . $name;
    $error = (string) ($addrErrors[$name] ?? '');
    osc_gui_field($id, $label, static function () use ($id, $name, $addrForm, $max, $autocomplete, $required, $error): void { ?>
        <input class="oe-input" id="<?php echo osc_esc_html($id); ?>" type="<?php echo $name === 'phone' ? 'tel' : 'text'; ?>"
               name="<?php echo osc_esc_html($name); ?>" value="<?php echo osc_esc_html((string) ($addrForm[$name] ?? '')); ?>"
               maxlength="<?php echo $max; ?>" autocomplete="<?php echo osc_esc_html($autocomplete); ?>"<?php
            echo $required ? ' required' : '';
            echo $error !== '' ? ' aria-invalid="true" aria-describedby="' . osc_esc_html($id) . '-error"' : ''; ?> />
    <?php }, '', $error);
};
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-addresses'); ?>

        <p class="oe-muted"><?php echo osc_esc_html(
            _m('Where your orders can be delivered. The default address is offered first at checkout.')
        ); ?></p>

        <?php if ($addrList === array()) {
            osc_gui_empty(_m('You have no saved addresses yet.'));
        }
        foreach ($addrList as $addr) {
            $addrTitle = trim((string) $addr['label']) !== '' ? (string) $addr['label'] : (string) $addr['name']; ?>
            <section class="oe-panel oe-address" data-address-id="<?php echo osc_esc_html((string) $addr['id']); ?>">
                <h2 class="oe-panel__title"><?php echo osc_esc_html($addrTitle); ?>
                    <?php if ($addr['default']) { ?>
                        <span class="oe-badge paid"><?php echo osc_esc_html(_m('Default')); ?></span>
                    <?php } ?>
                </h2>
                <p class="oe-address-lines"><?php echo osc_esc_html(osc_address_text($addr)); ?></p>
                <div class="oe-meta oe-row-actions">
                    <a href="<?php echo osc_esc_html(osc_user_addresses_url() . (str_contains(osc_user_addresses_url(), '?') ? '&' : '?') . 'edit=' . rawurlencode((string) $addr['id'])); ?>#oe-addr-form"><?php
                        echo osc_esc_html(_m('Edit')); ?></a>
                    <?php if (!$addr['default']) {
                        $addrPost('default', (string) $addr['id'], _m('Make default'));
                    }
                    $addrPost('delete', (string) $addr['id'], _m('Delete'), 'oe-danger-link', _m('Delete this address?')); ?>
                </div>
            </section>
        <?php }
        if ($addrList !== array()) {
            osc_gui_print_confirm_script();
        } ?>

        <?php if ($addrFull) { ?>
            <p class="oe-muted"><?php echo osc_esc_html(sprintf(_m('You can keep up to %d addresses. Delete one to add another.'), \mindstellar\user\AddressBook::MAX)); ?></p>
        <?php } else { ?>
            <form class="nocsrf" id="oe-addr-form" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>" novalidate>
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="addresses_post" />
                <input type="hidden" name="do" value="save" />
                <input type="hidden" name="id" value="<?php echo osc_esc_html($addrId); ?>" />
                <fieldset class="oe-group">
                    <legend><?php echo osc_esc_html($addrId === '' ? _m('Add an address') : _m('Edit address')); ?></legend>
                    <div class="oe-grid">
                        <?php
                        $addrField('label', _m('Label (Home, Work)'), 'off', 40, false);
                        $addrField('name', _m('Full name'), 'name', 100);
                        $addrField('phone', _m('Mobile'), 'tel', 40);
                        $addrField('line1', _m('Address'), 'street-address', 100);
                        ?>
                    </div>
                    <div class="oe-grid">
                        <?php
                        $addrField('city', _m('City'), 'address-level2', 100);
                        $addrField('state', _m('State'), 'address-level1', 100);
                        $addrField('postcode', _m('PIN code or postcode'), 'postal-code', 15);
                        $addrCountry = strtoupper((string) ($addrForm['country'] ?? ''));
                        if (!isset($addrCountries[$addrCountry])) {
                            $addrCountry = (string) array_key_first($addrCountries);
                        }
                        $addrCountryError = (string) ($addrErrors['country'] ?? '');
                        osc_gui_field('oe-addr-country', _m('Country'), static function () use ($addrCountries, $addrCountry, $addrCountryError): void { ?>
                            <select class="oe-input" id="oe-addr-country" name="country" autocomplete="country"<?php
                                echo $addrCountryError !== '' ? ' aria-invalid="true" aria-describedby="oe-addr-country-error"' : ''; ?>>
                                <?php foreach ($addrCountries as $addrCode => $addrName) { ?>
                                    <option value="<?php echo osc_esc_html($addrCode); ?>"<?php echo $addrCode === $addrCountry ? ' selected' : ''; ?>><?php
                                        echo osc_esc_html($addrName); ?></option>
                                <?php } ?>
                            </select>
                        <?php }, '', $addrCountryError); ?>
                    </div>
                    <?php if ($addrId === '' && $addrList !== array()) { ?>
                        <label class="oe-check">
                            <input type="checkbox" name="default" value="1" />
                            <span><?php echo osc_esc_html(_m('Make this my default address')); ?></span>
                        </label>
                    <?php } ?>
                </fieldset>
                <?php $addrButtons = array(array('label' => $addrId === '' ? _m('Save address') : _m('Save changes')));
                if ($addrId !== '') {
                    $addrButtons[] = array('label' => _m('Cancel'), 'href' => osc_user_addresses_url(), 'style' => 'link', 'class' => 'oe-secondary');
                }
                osc_gui_actions($addrButtons); ?>
            </form>
        <?php } ?>

        <?php osc_run_hook('account_page_after', 'user-addresses'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
