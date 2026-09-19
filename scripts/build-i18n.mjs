/**
 * Builds the gettext translation artifacts:
 *
 *   1. Extracts every translatable literal from the core PHP source into `.pot`
 *      templates — one per text domain (core.pot, messages.pot). Translators copy
 *      a `.pot` to `<locale>/<domain>.po` and fill in the msgstr fields.
 *   2. Compiles every committed `<locale>/*.po` to a matching `.mo`, so the binary
 *      catalogues the app loads at runtime are never stale relative to their `.po`.
 *
 * Pure JS (gettext-parser) so it runs in the normal `npm run build` on any machine
 * without the system `gettext` tools installed.
 *
 * Extraction is literal-only by design: `__('a string')`, `_e("...")`, `_n(...)`,
 * `_m(...)`, `_mn(...)`. Calls whose argument is a variable or a concatenation are
 * skipped — they aren't a fixed string a translator can key off. Domain routing
 * mirrors hTranslations.php: __/_e/_n default to `core`, _m/_mn are `messages`,
 * and an explicit second-arg domain on __/_e/_n wins.
 */
import gettextParser from 'gettext-parser';
import { readFile, writeFile, readdir, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, extname } from 'node:path';

const SRC_DIRS = ['oc-includes', 'oc-admin'];
const LANG_DIR = 'oc-content/languages';
const DEFAULT_DOMAIN = 'core';
// The text domains core actually loads at runtime (hTranslations.php). A string
// tagged with any other domain is effectively untranslatable — almost always a
// stray/typo second argument — so it is reported, not written to a .pot.
const OUTPUT_DOMAINS = ['core', 'messages'];

// A PHP quoted string: single- or double-quoted, honouring backslash escapes.
const STR = String.raw`'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)"`;
const WS = String.raw`\s*`;

// Singular: fn( STRING [, DOMAIN] ). Plural: fn( STRING , STRING , ... ).
const SINGULAR_RE = new RegExp(String.raw`\b(__|_e|_m)${WS}\(${WS}(?:${STR})(?:${WS},${WS}(?:${STR}))?`, 'g');
const PLURAL_RE = new RegExp(String.raw`\b(_n|_mn)${WS}\(${WS}(?:${STR})${WS},${WS}(?:${STR})`, 'g');
// Context: fn( STRING , CONTEXT [, DOMAIN] ). The context disambiguates two identical
// English strings for translators and is never shown to anyone.
const CONTEXT_RE = new RegExp(
    String.raw`\b(_x|_ex|_mx)${WS}\(${WS}(?:${STR})${WS},${WS}(?:${STR})(?:${WS},${WS}(?:${STR}))?`,
    'g'
);

/** Decode a matched literal (group pair: single, double) to its runtime value, or null to skip. */
function decode(single, double) {
    if (single !== undefined) {
        return single.replace(/\\(['\\])/g, '$1');
    }
    if (double !== undefined) {
        // Skip interpolated strings ("...$var...") — not a fixed translatable literal.
        if (/(?<!\\)\$[A-Za-z_{]/.test(double)) {
            return null;
        }
        return double.replace(/\\([nrt"\\$])/g, (_, c) => ({ n: '\n', r: '\r', t: '\t' }[c] ?? c));
    }
    return null;
}


async function walkPhp(dir, out = []) {
    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) {
            await walkPhp(full, out);
        } else if (entry.isFile() && extname(entry.name) === '.php') {
            out.push(full);
        }
    }
    return out;
}

// domain -> Map(key -> { msgid, msgctxt, msgid_plural?, refs:Set })
// Keyed by context and msgid together: the whole point of a context is that the same
// msgid appears more than once, so keying on msgid alone would collapse them.
const domains = new Map();
function record(domain, msgid, msgidPlural, ref, msgctxt = '') {
    if (!domains.has(domain)) {
        domains.set(domain, new Map());
    }
    const bucket = domains.get(domain);
    const key = `${msgctxt}\u0004${msgid}`;
    const existing = bucket.get(key);
    if (existing) {
        existing.refs.add(ref);
        if (msgidPlural && !existing.msgid_plural) {
            existing.msgid_plural = msgidPlural;
        }
    } else {
        bucket.set(key, {
            msgid,
            msgctxt,
            ...(msgidPlural ? { msgid_plural: msgidPlural } : {}),
            refs: new Set([ref]),
        });
    }
}

async function extract() {
    const files = [];
    for (const dir of SRC_DIRS) {
        await walkPhp(dir, files);
    }

    for (const file of files) {
        const content = await readFile(file, 'utf8');

        for (const m of content.matchAll(SINGULAR_RE)) {
            const [, fn, s1, d1, sDom, dDom] = m;
            const msgid = decode(s1, d1);
            if (msgid === null || msgid === '') {
                continue;
            }
            const explicitDomain = decode(sDom, dDom);
            const domain = fn === '_m' ? 'messages' : (explicitDomain || DEFAULT_DOMAIN);
            record(domain, msgid, null, file.replace(/\\/g, '/'));
        }

        for (const m of content.matchAll(CONTEXT_RE)) {
            const [, fn, s1, d1, sCtx, dCtx, sDom, dDom] = m;
            const msgid = decode(s1, d1);
            const msgctxt = decode(sCtx, dCtx);
            if (msgid === null || msgid === '' || msgctxt === null || msgctxt === '') {
                continue;
            }
            const explicitDomain = decode(sDom, dDom);
            const domain = fn === '_mx' ? 'messages' : (explicitDomain || DEFAULT_DOMAIN);
            record(domain, msgid, null, file.replace(/\\/g, '/'), msgctxt);
        }

        for (const m of content.matchAll(PLURAL_RE)) {
            const [, fn, s1, d1, s2, d2] = m;
            const single = decode(s1, d1);
            const plural = decode(s2, d2);
            if (single === null || single === '' || plural === null) {
                continue;
            }
            const domain = fn === '_mn' ? 'messages' : DEFAULT_DOMAIN;
            record(domain, single, plural, file.replace(/\\/g, '/'));
        }
    }
}

/** Whether a parsed catalogue carries any actual translation, ignoring its header entry. */
function hasTranslations(parsed) {
    for (const [ctx, entries] of Object.entries(parsed.translations || {})) {
        for (const [msgid, entry] of Object.entries(entries)) {
            if (ctx === '' && msgid === '') {
                continue;
            }
            if ((entry.msgstr || []).some((v) => v !== '')) {
                return true;
            }
        }
    }
    return false;
}

function potHeaders() {
    return {
        'project-id-version': 'Shopclass',
        'report-msgid-bugs-to': 'https://github.com/mindstellar/shopclass/issues',
        'mime-version': '1.0',
        'content-type': 'text/plain; charset=UTF-8',
        'content-transfer-encoding': '8bit',
        'language': '',
        'plural-forms': 'nplurals=2; plural=(n != 1);',
        'x-generator': 'scripts/build-i18n.mjs',
    };
}

async function writePot(domain, entries) {
    const translations = { '': {} };
    for (const key of [...entries.keys()].sort()) {
        const e = entries.get(key);
        const ctx = e.msgctxt || '';
        if (!translations[ctx]) {
            translations[ctx] = {};
        }
        translations[ctx][e.msgid] = {
            msgid: e.msgid,
            ...(ctx ? { msgctxt: ctx } : {}),
            ...(e.msgid_plural ? { msgid_plural: e.msgid_plural } : {}),
            msgstr: e.msgid_plural ? ['', ''] : [''],
            comments: { reference: [...e.refs].sort().join('\n') },
        };
    }
    const data = { charset: 'utf-8', headers: potHeaders(), translations };
    const out = join(LANG_DIR, `${domain}.pot`);
    await writeFile(out, gettextParser.po.compile(data, { sort: true }));
    console.log(`  ${out}  (${entries.size} strings)`);
}

async function compilePoToMo() {
    let count = 0;
    let removed = 0;
    for (const locale of await readdir(LANG_DIR, { withFileTypes: true })) {
        if (!locale.isDirectory()) {
            continue;
        }
        const dir = join(LANG_DIR, locale.name);
        for (const entry of await readdir(dir)) {
            if (extname(entry) !== '.po') {
                continue;
            }
            const poPath = join(dir, entry);
            const parsed = gettextParser.po.parse(await readFile(poPath));
            const moPath = join(dir, `${entry.slice(0, -3)}.mo`);

            // A catalogue that translates nothing -- the source language, or a locale
            // nobody has started -- compiles to a header-only .mo that every lookup
            // misses before falling back to the msgid it would have used anyway.
            // Shipping no file at all is the same behaviour without the lookup: the
            // loader already treats a missing catalogue as nothing to load.
            if (!hasTranslations(parsed)) {
                if (existsSync(moPath)) {
                    await rm(moPath);
                    removed++;
                }
                continue;
            }

            await writeFile(moPath, gettextParser.mo.compile(parsed));
            count++;
        }
    }
    console.log(`  compiled ${count} .po -> .mo`
        + (removed ? `, removed ${removed} that translated nothing` : ''));
}

const started = Date.now();
console.log('i18n: extracting translation templates...');
await extract();
for (const domain of OUTPUT_DOMAINS) {
    if (domains.has(domain)) {
        await writePot(domain, domains.get(domain));
    }
}
for (const domain of [...domains.keys()].sort()) {
    if (!OUTPUT_DOMAINS.includes(domain)) {
        const entries = domains.get(domain);
        const where = [...entries.values()][0]?.refs.values().next().value ?? '';
        console.warn(`  warning: ${entries.size} string(s) tagged with unloaded domain '${domain}' (e.g. ${where}) — likely a stray argument; not written`);
    }
}
console.log('i18n: compiling catalogues...');
await compilePoToMo();
console.log(`i18n: done in ${Date.now() - started}ms`);
