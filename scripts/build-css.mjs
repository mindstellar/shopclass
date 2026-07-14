/**
 * Compiles the admin theme's SCSS.
 *
 * Imports `sass-embedded` by name rather than shelling out to the `sass` binary: the
 * pure-JS `sass` package can also own `node_modules/.bin/sass`, and it compiles this
 * theme roughly 200x slower. Binding to the package directly removes that ambiguity.
 */
import { compile } from 'sass-embedded';
import { writeFile, mkdir } from 'node:fs/promises';
import { watch } from 'node:fs';
import { dirname, basename, relative, resolve } from 'node:path';

const IN = 'oc-admin/themes/modern/scss/main.scss';
const OUT = 'oc-admin/themes/modern/css/main.css';
const MAP = `${OUT}.map`;
const SCSS_DIR = dirname(IN);

async function build() {
  const started = Date.now();

  const result = compile(IN, {
    style: 'expanded',
    sourceMap: true,
    sourceMapIncludeSources: true,
    // `quietDeps` alone does nothing here: it only silences files loaded from a load
    // path, and Bootstrap's SCSS is copied *into* the theme, so Sass treats it as first
    // party. These four deprecations all come from Bootstrap 5.3 and from this theme's
    // own legacy `@import` usage; neither is fixable without migrating to `@use`, which
    // is a separate piece of work. Silencing them by name keeps real warnings visible.
    quietDeps: true,
    silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
  });

  // Sass reports sources as absolute file:// URLs. Rewrite them relative to the CSS so
  // the map still resolves for anyone who checks the repo out somewhere else.
  const cssDir = resolve(dirname(OUT));
  result.sourceMap.sources = result.sourceMap.sources.map((source) =>
    source.startsWith('file://') ? relative(cssDir, new URL(source).pathname) : source,
  );

  await mkdir(dirname(OUT), { recursive: true });
  await writeFile(OUT, `${result.css}\n\n/*# sourceMappingURL=${basename(MAP)} */\n`);
  await writeFile(MAP, JSON.stringify(result.sourceMap));

  const kb = (Buffer.byteLength(result.css) / 1024).toFixed(0);
  console.log(`css  ${OUT}  (${kb} KB in ${((Date.now() - started) / 1000).toFixed(1)}s)`);
}

async function safeBuild() {
  try {
    await build();
  } catch (error) {
    // In watch mode a syntax error must not kill the watcher.
    console.error(error.message);
    if (!process.argv.includes('--watch')) process.exitCode = 1;
  }
}

await safeBuild();

if (process.argv.includes('--watch')) {
  console.log(`watching ${SCSS_DIR} …`);
  let pending;
  watch(SCSS_DIR, { recursive: true }, (_event, file) => {
    if (!file?.endsWith('.scss')) return;
    clearTimeout(pending);
    pending = setTimeout(safeBuild, 50); // coalesce editor save bursts
  });
}
