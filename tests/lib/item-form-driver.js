/*
 * Drives the product form's show/hide script for tests/item-form-sections.php and writes
 * each check as [name, passed] into #osc-out.
 */
window.addEventListener('load', function () {
    var out = [];
    var $ = function (id) { return document.getElementById(id); };
    var check = function (name, ok) { out.push([name, !!ok]); };
    var wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
    var fields = function (html) {
        var hook = $('plugin-hook');
        hook.innerHTML = html;
        hook.dispatchEvent(new CustomEvent('osc:item-fields-loaded', { bubbles: true }));
    };

    (async function () {
        if (window.location.hash === '#sec-seller') {
            check('a hash at load opens seller', $('sec-seller').open);
            $('osc-out').textContent = btoa(JSON.stringify(out));
            return;
        }
        check('sell on: options section shown', !$('sec-options').hidden);
        check('sell on: not-selling line hidden', $('not-selling').hidden);

        $('product-has-options').click();
        check('options on: first default block hidden', $('rows-a').hidden);
        check('options on: second default block hidden', $('weight').hidden);
        check('options on: with-options note shown', !$('note').hidden);
        $('product-sell').click();
        $('product-sell').click();
        check('sell off and on with options: default block stays hidden', $('rows-a').hidden);
        check('sell off and on with options: note stays shown', !$('note').hidden);
        $('product-has-options').click();
        check('options off: both default blocks back', !$('rows-a').hidden && !$('weight').hidden);
        check('options off: note hidden', $('note').hidden);

        $('product-sell').click();
        check('sell off: options section hidden', $('sec-options').hidden);
        check('sell off: shipping section hidden', $('sec-shipping').hidden);
        check('sell off: not-selling line shown', !$('not-selling').hidden);
        check('sell off: SKU and stock wrapper hidden', $('sell-rows').hidden);
        $('product-sell').click();
        check('sell on again: shipping section shown', !$('sec-shipping').hidden);

        fields('<div class="dg-upload" data-osc-no-shipping><input type="file"></div>');
        check('digital marker: weight hidden', $('weight').hidden);
        check('digital marker: digital line shown', !$('digital-line').hidden);
        check('digital marker: details kept', !$('sec-details').hidden);
        fields('<div><select name="meta[1]"></select></div>');
        check('physical: weight shown again', !$('weight').hidden);
        fields('');
        check('empty hook: details hidden', $('sec-details').hidden);
        fields('<div class="row"></div>');
        check('a hook with only an empty wrapper: details hidden', $('sec-details').hidden);
        fields('<p>Pick a category first.</p>');
        check('a hook with text: details shown', !$('sec-details').hidden);

        // A jump to the details element itself does not open it, so only the script can.
        check('seller starts closed', !$('sec-seller').open);
        window.location.hash = '#sec-seller';
        await wait(50);
        check('hash change opens seller', $('sec-seller').open);
        $('sec-seller').open = false;

        // The browser opens a closed ancestor on its own when it jumps; stop the jump.
        $('err-link').addEventListener('click', function (e) { e.preventDefault(); });
        $('err-link').click();
        check('error link opens seller', $('sec-seller').open);
        $('sec-seller').open = false;

        $('toc-link').click();
        check('toc link opens seller', $('sec-seller').open);

        $('osc-out').textContent = btoa(JSON.stringify(out));
    })();
});
