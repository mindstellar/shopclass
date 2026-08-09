/**
 * Generates `deprecated-api.json`, the inventory the registry CI annotates
 * pull requests against (docs/MARKET.md §6.3). It scans `oc-includes/` for two
 * independent signals of a deprecation and merges them by symbol name:
 *
 *   1. Call sites of `mindstellar\utility\Deprecate::deprecated*()` — the
 *      runtime mechanism. The subject argument is often `__FUNCTION__` /
 *      `__METHOD__` / `__FILE__`, so call sites are resolved against the
 *      enclosing function/class tracked while walking the file.
 *   2. `@deprecated` docblock tags immediately above a function, method, or
 *      class declaration — including the free-text `@deprecated since X.Y.Z`
 *      style used throughout utils.php, which predates the Deprecate class
 *      and has no runtime call site at all.
 *
 * A symbol carrying both (e.g. utils.php's version_compare2) is merged, not
 * duplicated — the call site wins for fields it provides, since it is the
 * exact string the `d_function_run` hook fires with; the docblock fills gaps.
 *
 * No dependencies: this mirrors the header-block parsing in
 * tools/package-lint.php in spirit — reproduce what actually runs rather than
 * a real parser, because the registry CI must see exactly what core does.
 */
import { readFile, writeFile, readdir } from 'node:fs/promises';
import { join, relative, extname } from 'node:path';
import { execSync } from 'node:child_process';

const ROOT = process.cwd();
const SCAN_DIR = 'oc-includes';
const EXCLUDE_DIRS = new Set(['vendor', 'assets']);

const args = process.argv.slice(2);
let outArg = ROOT;
for (const a of args) {
  if (a.startsWith('--out=')) outArg = a.slice('--out='.length);
}
const outPath = outArg.endsWith('.json') ? outArg : join(outArg, 'deprecated-api.json');

/* ------------------------------------------------------------------ *
 * Small PHP-aware text utilities. None of this is a real PHP parser —
 * it is exactly enough to (a) blank out comments/strings so brace
 * counting and declaration matching aren't fooled by their contents,
 * and (b) walk a file top to bottom tracking "what class/function am
 * I inside right now".
 * ------------------------------------------------------------------ */

/** Replaces comments and string contents with spaces, preserving line breaks and length. */
function stripCommentsAndStrings(text) {
  let out = '';
  let i = 0;
  const n = text.length;
  const STATE = { NORMAL: 0, SQ: 1, DQ: 2, LINE_COMMENT: 3, BLOCK_COMMENT: 4 };
  let state = STATE.NORMAL;
  while (i < n) {
    const c = text[i];
    const c2 = text[i + 1];
    if (state === STATE.NORMAL) {
      if (c === "'") {
        state = STATE.SQ;
        out += ' ';
        i++;
        continue;
      }
      if (c === '"') {
        state = STATE.DQ;
        out += ' ';
        i++;
        continue;
      }
      if (c === '/' && c2 === '/') {
        state = STATE.LINE_COMMENT;
        out += '  ';
        i += 2;
        continue;
      }
      if (c === '#' && c2 !== '[') {
        state = STATE.LINE_COMMENT;
        out += ' ';
        i++;
        continue;
      }
      if (c === '/' && c2 === '*') {
        state = STATE.BLOCK_COMMENT;
        out += '  ';
        i += 2;
        continue;
      }
      out += c;
      i++;
      continue;
    }
    if (state === STATE.SQ || state === STATE.DQ) {
      const quote = state === STATE.SQ ? "'" : '"';
      if (c === '\\' && i + 1 < n) {
        out += '  ';
        i += 2;
        continue;
      }
      if (c === quote) {
        state = STATE.NORMAL;
        out += ' ';
        i++;
        continue;
      }
      out += c === '\n' ? '\n' : ' ';
      i++;
      continue;
    }
    if (state === STATE.LINE_COMMENT) {
      if (c === '\n') {
        state = STATE.NORMAL;
        out += '\n';
        i++;
        continue;
      }
      out += ' ';
      i++;
      continue;
    }
    if (state === STATE.BLOCK_COMMENT) {
      if (c === '*' && c2 === '/') {
        state = STATE.NORMAL;
        out += '  ';
        i += 2;
        continue;
      }
      out += c === '\n' ? '\n' : ' ';
      i++;
      continue;
    }
  }
  return out;
}

/**
 * Per-line facts about a stripped (comment/string-free) source: brace depth at
 * the start of the line, the enclosing class/interface/trait (if any), the
 * enclosing named function (if any — '{closure}' when the innermost function
 * is anonymous, matching real __FUNCTION__ behaviour), and a declaration this
 * line introduces, if any.
 */
function buildLineIndex(stripped) {
  const lines = stripped.split('\n');
  const classDeclRe = /^\s*(?:abstract\s+|final\s+|readonly\s+)*(class|interface|trait)\s+(\w+)/;
  // \b after "function" matters: without it this also matches inside
  // `function_exists(`, capturing "_exists" as a bogus declared name.
  const funcDeclRe = /\bfunction\b\s*&?\s*(\w+)?\s*\(/;

  const classStack = [];
  const funcStack = [];
  let depth = 0;
  const info = new Array(lines.length);

  for (let idx = 0; idx < lines.length; idx++) {
    const line = lines[idx];
    const enclosingClass = classStack.length ? classStack[classStack.length - 1].name : null;
    const enclosingFuncEntry = funcStack.length ? funcStack[funcStack.length - 1] : null;
    const enclosingFunction = enclosingFuncEntry ? enclosingFuncEntry.name : null;

    let declaration = null;
    const classMatch = line.match(classDeclRe);
    const funcMatch = line.match(funcDeclRe);
    // A class line is never also a function-declaration line in practice, but
    // guard the order anyway: class first, since `funcDeclRe` cannot match it.
    if (classMatch) {
      declaration = { kind: 'class', name: classMatch[2] };
      classStack.push({ name: classMatch[2], entryDepth: depth, entered: false });
    } else if (funcMatch) {
      const bareName = funcMatch[1] || '{closure}';
      declaration = {
        kind: 'function',
        name: bareName,
        qualified: enclosingClass ? `${enclosingClass}::${bareName}` : bareName,
      };
      funcStack.push({ name: bareName, qualified: declaration.qualified, entryDepth: depth, entered: false });
    }

    info[idx] = { depth, enclosingClass, enclosingFunction, enclosingFuncEntry, declaration };

    for (const ch of line) {
      if (ch === '{') depth++;
      else if (ch === '}') depth--;
    }

    // Allman-style bracing puts the opening `{` on the line *after* the
    // declaration, so a stack entry cannot be popped just because depth has
    // returned to entryDepth — it must first have gone above entryDepth at
    // least once. An entry that never opens a body at all (an abstract/
    // interface method declaration ending in `;`) is still cleared once the
    // enclosing scope it was declared in closes.
    for (const entry of classStack) {
      if (!entry.entered && depth > entry.entryDepth) entry.entered = true;
    }
    for (const entry of funcStack) {
      if (!entry.entered && depth > entry.entryDepth) entry.entered = true;
    }
    while (
      classStack.length &&
      ((classStack[classStack.length - 1].entered && depth <= classStack[classStack.length - 1].entryDepth) ||
        depth < classStack[classStack.length - 1].entryDepth)
    ) {
      classStack.pop();
    }
    while (
      funcStack.length &&
      ((funcStack[funcStack.length - 1].entered && depth <= funcStack[funcStack.length - 1].entryDepth) ||
        depth < funcStack[funcStack.length - 1].entryDepth)
    ) {
      funcStack.pop();
    }
  }

  return info;
}

/** Offsets of every line start, so a character index can be turned into a 1-based line number. */
function lineStarts(text) {
  const starts = [0];
  for (let i = 0; i < text.length; i++) {
    if (text[i] === '\n') starts.push(i + 1);
  }
  return starts;
}

function lineAt(starts, charIndex) {
  let lo = 0;
  let hi = starts.length - 1;
  while (lo < hi) {
    const mid = (lo + hi + 1) >> 1;
    if (starts[mid] <= charIndex) lo = mid;
    else hi = mid - 1;
  }
  return lo + 1;
}

/* ------------------------------------------------------------------ *
 * @deprecated docblock scan
 * ------------------------------------------------------------------ */

/**
 * Splits a free-text `@deprecated` tag body into a version and a replacement,
 * matching the several house styles actually in use (see utils.php,
 * Sanitize.php, DBCommandClass.php): "since X.Y.Z", "X.Y.Z <prose>", a bare
 * version with no "since", or prose with no version at all. Never invents a
 * value — an absent piece stays null.
 */
function parseDeprecatedTag(text) {
  const clean = text.replace(/\s+/g, ' ').trim();

  let since = null;
  const sinceMatch = clean.match(/since\s+(?:version\s+)?v?(\d+(?:\.\d+){1,3})/i);
  if (sinceMatch) {
    since = sinceMatch[1];
  } else {
    const bareMatch = clean.match(/^v?(\d+(?:\.\d+){1,3})\b/);
    if (bareMatch) since = bareMatch[1];
  }

  let replacement = null;
  const useMatch = clean.match(/\buse\s+([^\s,;]+(?:\([^)]*\))?)/i);
  if (useMatch) {
    replacement = useMatch[1].replace(/[.,;]+$/, '');
  }

  return { since, replacement };
}

/** Finds every `/** ... *\/` block in the raw source and returns its @deprecated tag text, if any. */
function findDeprecatedDocblocks(rawText, starts) {
  const results = [];
  const re = /\/\*\*([\s\S]*?)\*\//g;
  let m;
  while ((m = re.exec(rawText)) !== null) {
    const body = m[1];
    if (!/@deprecated\b/.test(body)) continue;

    // Tag text runs from "@deprecated" to the next "@tag" or the block's end,
    // with each continuation line's leading " * " stripped.
    const tagMatch = body.match(/@deprecated\b([\s\S]*?)(?=\n\s*\*\s*@\w|\s*$)/);
    const rawTagText = tagMatch ? tagMatch[1] : '';
    const tagText = rawTagText
      .split('\n')
      .map((l) => l.replace(/^\s*\*\s?/, ''))
      .join(' ')
      .trim();

    const blockEndIndex = m.index + m[0].length;
    results.push({
      endLine: lineAt(starts, blockEndIndex - 1),
      tagText,
    });
  }
  return results;
}

function scanDocblocks(rawText, lineInfo, starts) {
  const found = { functions: [], classes: [] };
  const blocks = findDeprecatedDocblocks(rawText, starts);

  for (const block of blocks) {
    const { since, replacement } = parseDeprecatedTag(block.tagText);

    // The declaration a docblock documents is the next non-blank source line
    // after it — look a few lines ahead to skip blank lines and attributes
    // (`#[...]`), but give up rather than guess past that.
    let target = null;
    let targetLine = null;
    for (let l = block.endLine; l < Math.min(block.endLine + 6, lineInfo.length); l++) {
      const info = lineInfo[l];
      if (!info) continue;
      const rawLine = (info.rawLineTrimmed ?? '').trim();
      if (rawLine === '' || rawLine.startsWith('#[')) continue;
      if (info.declaration) {
        target = info.declaration;
        targetLine = l + 1;
      }
      break;
    }

    if (!target) continue; // property, constant, or something else — not in scope

    if (target.kind === 'class') {
      found.classes.push({ name: target.name, since, replacement, removal: null, line: targetLine });
    } else if (target.kind === 'function' && target.name !== '{closure}') {
      found.functions.push({ name: target.qualified, since, replacement, removal: null, line: targetLine });
    }
  }

  return found;
}

/* ------------------------------------------------------------------ *
 * Deprecate::deprecated*() call-site scan
 * ------------------------------------------------------------------ */

/** Splits a call's argument list on top-level commas, respecting nesting and quotes. */
function splitArgs(argsStr) {
  const parts = [];
  let depth = 0;
  let cur = '';
  let quote = null;
  for (let i = 0; i < argsStr.length; i++) {
    const c = argsStr[i];
    if (quote) {
      cur += c;
      if (c === '\\') {
        cur += argsStr[++i] ?? '';
      } else if (c === quote) {
        quote = null;
      }
      continue;
    }
    if (c === "'" || c === '"') {
      quote = c;
      cur += c;
      continue;
    }
    if (c === '(' || c === '[' || c === '{') depth++;
    if (c === ')' || c === ']' || c === '}') depth--;
    if (c === ',' && depth === 0) {
      parts.push(cur);
      cur = '';
      continue;
    }
    cur += c;
  }
  if (cur.trim() !== '') parts.push(cur);
  return parts.map((p) => p.trim());
}

/**
 * Best-effort resolution of a call argument to a concrete name.
 * Returns a string, or null when nothing usable can be derived — callers
 * count that as a skip rather than guessing.
 */
function resolveArg(expr, ctx) {
  const e = expr.trim();

  const simpleString = e.match(/^'((?:\\.|[^'\\])*)'$/) || e.match(/^"((?:\\.|[^"\\])*)"$/);
  if (simpleString) return simpleString[1];

  if (e === '__FUNCTION__') return ctx.enclosingFunction ?? null;
  if (e === '__METHOD__') return ctx.enclosingFuncEntry?.qualified ?? ctx.enclosingFunction ?? null;
  if (e === '__CLASS__') return ctx.enclosingClass ?? null;
  if (e === '__FILE__') return ctx.relPath;

  // Concatenation of a mix of literals and expressions, e.g. `$prefix .
  // 'header_scripts_loaded'`: keep the literal parts verbatim and mark the
  // dynamic parts with the source text that produces them, rather than
  // inventing or dropping the whole name.
  if (e.includes('.')) {
    const pieces = splitArgs(e.replace(/\./g, ',')); // reuse the quote-aware splitter
    if (pieces.length > 1) {
      const resolved = pieces.map((p) => {
        const lit = p.trim().match(/^'((?:\\.|[^'\\])*)'$/) || p.trim().match(/^"((?:\\.|[^"\\])*)"$/);
        return lit ? lit[1] : `{${p.trim()}}`;
      });
      return resolved.join('');
    }
  }

  return null;
}

const CALL_SPECS = {
  deprecatedFunction: { category: 'functions', subject: 0, version: 1, replacement: 2 },
  deprecatedRunHook: { category: 'hooks', subject: 0, version: 1, replacement: 2 },
  deprecatedApplyFilter: { category: 'filters', subject: 0, version: 2, replacement: 3 },
  deprecatedFile: { category: 'files', subject: 0, version: 1, replacement: 2 },
};

function scanCallSites(rawText, lineInfo, starts, relPath) {
  const found = { functions: [], hooks: [], filters: [], files: [] };
  const callRe = /Deprecate::(deprecatedFunction|deprecatedRunHook|deprecatedApplyFilter|deprecatedFile)\s*\(/g;
  let m;
  while ((m = callRe.exec(rawText)) !== null) {
    const method = m[1];
    const spec = CALL_SPECS[method];
    const openParen = m.index + m[0].length - 1;

    // Balanced-paren scan for the argument list, string-aware so a literal
    // containing ')' cannot end it early.
    let depth = 1;
    let i = openParen + 1;
    let quote = null;
    while (i < rawText.length && depth > 0) {
      const c = rawText[i];
      if (quote) {
        if (c === '\\') i++;
        else if (c === quote) quote = null;
      } else if (c === "'" || c === '"') {
        quote = c;
      } else if (c === '(') {
        depth++;
      } else if (c === ')') {
        depth--;
      }
      i++;
    }
    const argsStr = rawText.slice(openParen + 1, i - 1);
    const args = splitArgs(argsStr);

    const lineNum = lineAt(starts, m.index);
    const ctx = { ...(lineInfo[lineNum - 1] ?? {}), relPath };

    const subjectExpr = args[spec.subject];
    const versionExpr = args[spec.version];
    const replacementExpr = args[spec.replacement];

    if (subjectExpr === undefined) continue;
    const name = resolveArg(subjectExpr, ctx);
    if (name === null) continue;

    const versionLit = versionExpr?.match(/^'((?:\\.|[^'\\])*)'$/) || versionExpr?.match(/^"((?:\\.|[^"\\])*)"$/);
    const since = versionLit ? versionLit[1] : null;

    let replacement = null;
    if (replacementExpr !== undefined && replacementExpr !== 'null') {
      const repLit = resolveArg(replacementExpr, ctx);
      replacement = repLit;
    }

    found[spec.category].push({ name, since, replacement, removal: null, file: relPath, line: lineNum });
  }
  return found;
}

/* ------------------------------------------------------------------ *
 * Walk oc-includes/, merge, sort, write
 * ------------------------------------------------------------------ */

async function walk(dir) {
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (EXCLUDE_DIRS.has(entry.name)) continue;
      out.push(...(await walk(join(dir, entry.name))));
    } else if (extname(entry.name) === '.php') {
      out.push(join(dir, entry.name));
    }
  }
  return out;
}

function mergeEntry(map, entry, hasFileLine) {
  const key = entry.name;
  const existing = map.get(key);
  if (!existing) {
    map.set(key, entry);
    return;
  }
  // A symbol found by both scans (docblock + call site): the call site is the
  // exact string the runtime hook fires with, so it wins for any field it
  // actually provides; the docblock only fills in what the call site left null.
  existing.since = existing.since ?? entry.since;
  existing.replacement = existing.replacement ?? entry.replacement;
  existing.removal = existing.removal ?? entry.removal;
  if (hasFileLine && entry.file && existing.file === undefined) {
    existing.file = entry.file;
    existing.line = entry.line;
  }
}

async function main() {
  const files = await walk(join(ROOT, SCAN_DIR));

  const maps = {
    functions: new Map(),
    hooks: new Map(),
    filters: new Map(),
    files: new Map(),
    classes: new Map(),
  };

  for (const abs of files) {
    const relPath = relative(ROOT, abs).split('\\').join('/');
    const rawText = await readFile(abs, 'utf8');
    const stripped = stripCommentsAndStrings(rawText);
    const lineInfo = buildLineIndex(stripped);
    // Attach the raw trimmed line text for the docblock target lookahead,
    // since buildLineIndex works off the comment-stripped copy.
    const rawLines = rawText.split('\n');
    for (let i = 0; i < lineInfo.length; i++) {
      lineInfo[i].rawLineTrimmed = rawLines[i] ?? '';
    }
    const starts = lineStarts(rawText);

    const docblockHits = scanDocblocks(rawText, lineInfo, starts);
    for (const fn of docblockHits.functions) {
      mergeEntry(maps.functions, { ...fn, file: relPath }, true);
    }
    for (const cls of docblockHits.classes) {
      mergeEntry(maps.classes, { ...cls, file: relPath }, true);
    }

    const callHits = scanCallSites(rawText, lineInfo, starts, relPath);
    for (const category of ['functions', 'hooks', 'filters', 'files']) {
      for (const entry of callHits[category]) {
        mergeEntry(maps[category], entry, true);
      }
    }
  }

  const toSortedArray = (map) =>
    [...map.values()].sort((a, b) => a.name.localeCompare(b.name)).map((e) => ({
      name: e.name,
      since: e.since ?? null,
      replacement: e.replacement ?? null,
      removal: e.removal ?? null,
      file: e.file ?? null,
      line: e.line ?? null,
    }));

  const constantsPath = join(ROOT, 'oc-includes/osclass/default-constants.php');
  const constantsText = await readFile(constantsPath, 'utf8');
  const versionMatch = constantsText.match(/define\(\s*'OSCLASS_VERSION',\s*'([^']+)'/);
  const coreVersion = versionMatch ? versionMatch[1] : 'unknown';

  let generatedFrom = coreVersion;
  try {
    generatedFrom = execSync('git describe --tags --always', { cwd: ROOT, stdio: ['ignore', 'pipe', 'ignore'] })
      .toString()
      .trim();
  } catch {
    // No git available (e.g. a bare source tarball) — fall back to the version constant.
  }

  const output = {
    core_version: coreVersion,
    generated_from: generatedFrom,
    functions: toSortedArray(maps.functions),
    hooks: toSortedArray(maps.hooks),
    filters: toSortedArray(maps.filters),
    files: toSortedArray(maps.files),
    classes: toSortedArray(maps.classes),
  };

  await writeFile(outPath, JSON.stringify(output, null, 2) + '\n');

  console.error(
    `deprecated-api.json -> ${outPath} :: ` +
      `functions=${output.functions.length} hooks=${output.hooks.length} ` +
      `filters=${output.filters.length} files=${output.files.length} classes=${output.classes.length}`
  );
}

await main();
