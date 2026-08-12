// Runs the dev stack, adding docker-compose.local.yml when there is one.
//
// The dev compose file is committed and shared, so it can only describe things every
// checkout has. Bind-mounting a theme from a sibling directory is not that: the path
// exists on one machine, and on every other Docker creates an empty directory at the
// mount point — which reads as a broken theme rather than an absent one, because an
// empty oc-content/themes/<name> still looks like a theme to the loader.
//
// So personal mounts live in docker-compose.local.yml, which is gitignored. This picks
// it up when it exists and ignores it when it does not, rather than making everyone
// remember a second -f.
//
// Usage: node scripts/dev.mjs <docker compose args...>

import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const files = ['docker-compose.dev.yml'];
const local = 'docker-compose.local.yml';
if (existsSync(join(root, local))) {
    files.push(local);
}

const args = files.flatMap((f) => ['-f', f]).concat(process.argv.slice(2));

if (files.length > 1) {
    console.log(`(using ${files.join(' + ')})`);
}

const child = spawn('docker', ['compose', ...args], { cwd: root, stdio: 'inherit' });
child.on('exit', (code, signal) => process.exit(signal ? 1 : code ?? 0));
child.on('error', (err) => {
    console.error(`could not run docker compose: ${err.message}`);
    process.exit(1);
});
