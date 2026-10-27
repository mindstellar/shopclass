// Drives the product form's show/hide logic in headless Chrome. Run by hand:
//   node tests/js/item-form.mjs
import {createRequire} from 'node:module';
import {readdirSync, readFileSync} from 'node:fs';
import {homedir} from 'node:os';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const npx = join(homedir(), '.npm', '_npx');
const found = readdirSync(npx).map((d) => join(npx, d, 'node_modules', 'puppeteer-core')).find((p) => {
    try { readdirSync(p); return true; } catch { return false; }
});
if (!found) { console.error('puppeteer-core not found'); process.exit(2); }
const puppeteer = createRequire(import.meta.url)(found);

const js = join(root, 'oc-admin', 'themes', 'modern', 'js');
const html = readFileSync(join(root, 'tests', 'fixtures', 'item-form.html'), 'utf8');

const browser = await puppeteer.launch({executablePath: '/opt/google/chrome/chrome', headless: true, args: ['--no-sandbox']});
let failed = 0;
const check = (name, ok) => { console.log((ok ? 'ok   ' : 'FAIL ') + name); if (!ok) failed++; };

async function fresh(hash = '') {
    const page = await browser.newPage();
    await page.setRequestInterception(true);
    page.on('request', (req) => {
        const url = req.url().split('#')[0];
        if (url.endsWith('/fixture.html')) {
            req.respond({contentType: 'text/html', body: html});
        } else if (url.endsWith('/variants.js') || url.endsWith('/editor.js')) {
            req.respond({contentType: 'text/javascript', body: readFileSync(join(js, url.split('/').pop()), 'utf8')});
        } else {
            req.respond({status: 404, body: ''});
        }
    });
    await page.goto('http://fixture.test/fixture.html' + hash);
    return page;
}
const hidden = (page, sel) => page.$eval(sel, (e) => e.hidden);

try {
    let page = await fresh();
    check('sell on: options section shown', !(await hidden(page, '#sec-options')));
    check('sell on: not-selling line hidden', await hidden(page, '#not-selling'));

    await page.click('#product-has-options');
    check('options on: first default block hidden', await hidden(page, '#rows-a'));
    check('options on: second default block hidden', await hidden(page, '#weight'));
    check('options on: with-options note shown', !(await hidden(page, '#note')));
    await page.click('#product-sell');
    await page.click('#product-sell');
    check('sell off and on with options: default block stays hidden', await hidden(page, '#rows-a'));
    check('sell off and on with options: note stays shown', !(await hidden(page, '#note')));
    await page.click('#product-has-options');
    check('options off: both default blocks back', !(await hidden(page, '#rows-a')) && !(await hidden(page, '#weight')));
    check('options off: note hidden', await hidden(page, '#note'));

    await page.click('#product-sell');
    check('sell off: options section hidden', await hidden(page, '#sec-options'));
    check('sell off: shipping section hidden', await hidden(page, '#sec-shipping'));
    check('sell off: not-selling line shown', !(await hidden(page, '#not-selling')));
    check('sell off: SKU and stock wrapper hidden', await hidden(page, '#sell-rows'));
    await page.click('#product-sell');
    check('sell on again: shipping section shown', !(await hidden(page, '#sec-shipping')));

    await page.evaluate(() => {
        const hook = document.getElementById('plugin-hook');
        hook.innerHTML = '<div class="dg-upload" data-osc-no-shipping></div>';
        hook.dispatchEvent(new CustomEvent('osc:item-fields-loaded', {bubbles: true}));
    });
    check('digital marker: weight hidden', await hidden(page, '#weight'));
    check('digital marker: digital line shown', !(await hidden(page, '#digital-line')));
    check('digital marker: details kept', !(await hidden(page, '#sec-details')));

    await page.evaluate(() => {
        const hook = document.getElementById('plugin-hook');
        hook.innerHTML = '<div></div>';
        hook.dispatchEvent(new CustomEvent('osc:item-fields-loaded', {bubbles: true}));
    });
    check('physical: weight shown again', !(await hidden(page, '#weight')));

    await page.evaluate(() => {
        const hook = document.getElementById('plugin-hook');
        hook.innerHTML = '';
        hook.dispatchEvent(new CustomEvent('osc:item-fields-loaded', {bubbles: true}));
    });
    check('empty hook: details hidden', await hidden(page, '#sec-details'));

    check('seller starts closed', !(await page.$eval('#sec-seller', (e) => e.open)));
    // The browser opens a closed ancestor on its own when it jumps; stop the jump so only the script can.
    await page.$eval('#err-link', (a) => a.addEventListener('click', (e) => e.preventDefault()));
    await page.click('#err-link');
    check('error link opens seller', await page.$eval('#sec-seller', (e) => e.open));
    await page.close();

    page = await fresh();
    await page.click('#toc-link');
    check('toc link opens seller', await page.$eval('#sec-seller', (e) => e.open));
    await page.close();

    page = await fresh();
    // A jump to the details element itself does not open it, so only the script can.
    await page.evaluate(() => { location.hash = '#sec-seller'; });
    await new Promise((r) => setTimeout(r, 100));
    check('hash change opens seller', await page.$eval('#sec-seller', (e) => e.open));
    await page.close();

    page = await fresh('#sec-seller');
    check('a hash at load opens seller', await page.$eval('#sec-seller', (e) => e.open));
    await page.close();
} finally {
    await browser.close();
}
process.exit(failed ? 1 : 0);
