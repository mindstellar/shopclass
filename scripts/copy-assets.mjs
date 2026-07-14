/**
 * Copies third-party assets out of node_modules into the paths Osclass serves them from.
 *
 * Replaces the old Grunt `clean` + `copy` tasks. Osclass loads vendor assets by literal
 * path (there is no bundler and no asset hashing), and the copied output is committed to
 * git — `.build.sh` packages releases with `git archive`, so nothing rebuilds them at
 * release time. Both destinations are wiped first so a removed dependency cannot leave
 * an orphaned file behind that would still ship.
 */
import { rm, mkdir, cp, chmod, glob } from 'node:fs/promises';
import { dirname, join, basename, relative } from 'node:path';

const ASSETS = 'oc-includes/assets';
const SCSS_VENDOR = 'oc-admin/themes/modern/scss/bootstrap';
const NM = 'node_modules';

/**
 * `flatten` mirrors Grunt's option of the same name: true drops every matched file
 * directly into `dest`, false preserves its path relative to `cwd`.
 */
const TARGETS = [
  { dest: `${ASSETS}/jquery`, flatten: true, src: ['jquery/dist/jquery.min.js', 'jquery/LICENSE.txt'] },
  { dest: `${ASSETS}/jquery-migrate`, flatten: true, src: ['jquery-migrate/dist/jquery-migrate.min.js', 'jquery-migrate/LICENSE.txt'] },
  { dest: `${ASSETS}/jquery-ui`, flatten: true, src: ['jquery-ui-dist/*.min.js', 'jquery-ui-dist/*.min.css', 'jquery-ui-dist/LICENSE.txt'] },
  { dest: `${ASSETS}/jquery-ui/images`, flatten: true, src: ['jquery-ui-dist/images/*'] },
  { dest: `${ASSETS}/jquery-treeview`, flatten: true, src: ['jquery-treeview/jquery.treeview.js'] },
  { dest: `${ASSETS}/jquery-validation`, flatten: true, src: ['jquery-validation/dist/jquery.validate.min.js', 'jquery-validation/dist/additional-methods.min.js', 'jquery-validation/LICENSE.md'] },
  { dest: `${ASSETS}/jquery-ui-nested`, flatten: true, src: ['jquery-ui-nested/jquery-ui-nested.js'] },
  { dest: `${ASSETS}/spectrum-colorpicker`, flatten: true, src: ['spectrum-colorpicker/spectrum.js', 'spectrum-colorpicker/spectrum.css', 'spectrum-colorpicker/LICENSE'] },
  { dest: `${ASSETS}/bootstrap`, flatten: true, src: ['bootstrap/dist/css/bootstrap.min.*', 'bootstrap/dist/js/bootstrap.min.*', 'bootstrap/LICENSE'] },
  { dest: `${ASSETS}/popper`, flatten: true, src: ['@popperjs/core/dist/umd/popper.min.js', '@popperjs/core/LICENSE.md'] },
  { dest: `${ASSETS}/chart-js`, flatten: true, src: ['chart.js/dist/chart.min.js', 'chart.js/LICENSE.md'] },
  { dest: `${ASSETS}/sortablejs`, flatten: true, src: ['sortablejs/Sortable.min.js', 'sortablejs/LICENSE'] },
  { dest: `${ASSETS}/fonts/open-sans`, flatten: true, src: ['npm-font-open-sans/fonts/Regular/OpenSans-Regular.ttf', 'npm-font-open-sans/LICENSE'] },

  { dest: `${ASSETS}/bootstrap-icons`, flatten: true, src: ['bootstrap-icons/LICENSE'] },
  { dest: `${ASSETS}/bootstrap-icons`, flatten: false, cwd: 'bootstrap-icons/font', src: ['**/*'] },

  { dest: `${ASSETS}/tinymce`, flatten: false, cwd: 'tinymce', src: ['license.txt', 'tinymce.min.js'] },
  { dest: `${ASSETS}/tinymce`, flatten: false, cwd: 'tinymce', src: ['icons/**/*.min.*', 'skins/ui/oxide/**/*.min.*', 'skins/content/default/**/*.min.*', 'themes/silver/**/*.min.*'] },
  { dest: `${ASSETS}/tinymce/models/dom`, flatten: false, cwd: 'tinymce/models/dom', src: ['**/*.min.js'] },
  { dest: `${ASSETS}/tinymce/skins`, flatten: false, cwd: 'tinymce/skins', src: ['content/default/content.min.css', 'ui/oxide/skin.min.css'] },
  { dest: `${ASSETS}/tinymce/plugins`, flatten: false, cwd: 'tinymce/plugins', src: ['{advlist,anchor,autolink,charmap,code,fullscreen,image,imagetools,insertdatetime,link,lists,media,paste,preview,searchreplace,table,visualblocks}/*.min.js'] },

  { dest: `${ASSETS}/osclass-legacy`, flatten: false, cwd: 'osclass-legacy-assets/src', src: ['**/*'] },

  // Bootstrap's SCSS sources, imported by main.scss. Not an asset — it lands in the theme
  // and is gitignored, because it is a verbatim vendor copy. Brand overrides live in
  // scss/_brand.scss, which is tracked and therefore survives this wipe.
  { dest: SCSS_VENDOR, flatten: false, cwd: 'bootstrap/scss', src: ['**/*'] },
];

async function expand(patterns, cwd) {
  const base = cwd ? join(NM, cwd) : NM;
  const found = [];
  for (const pattern of patterns) {
    for await (const hit of glob(pattern, { cwd: base, withFileTypes: true })) {
      if (hit.isDirectory()) continue;
      const abs = join(hit.parentPath ?? hit.path, hit.name);
      found.push({ abs, rel: relative(base, abs) });
    }
  }
  return found;
}

for (const dir of [ASSETS, SCSS_VENDOR]) {
  await rm(dir, { recursive: true, force: true });
  await mkdir(dir, { recursive: true });
}

let copied = 0;

for (const target of TARGETS) {
  const files = await expand(target.src, target.cwd);

  if (files.length === 0) {
    // A dependency that vanished silently is how you ship a half-broken admin.
    console.error(`  ✗ ${target.dest} — no files matched ${target.src.join(', ')}`);
    process.exitCode = 1;
    continue;
  }

  for (const file of files) {
    const out = target.flatten
      ? join(target.dest, basename(file.abs))
      : join(target.dest, file.rel);
    await mkdir(dirname(out), { recursive: true });
    await cp(file.abs, out);
    // cp inherits the source's mode, and some packages ship 0755 files. These are static
    // web assets; without normalising, every copy shows up as a spurious mode-change diff.
    await chmod(out, 0o644);
    copied++;
  }
}

console.log(`copied ${copied} files → ${ASSETS} + ${SCSS_VENDOR}`);
