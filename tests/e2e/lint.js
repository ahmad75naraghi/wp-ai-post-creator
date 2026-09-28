/**
 * Syntax lint: parse every plugin PHP file with php-parser (PHP 7.4 target).
 *
 * There is no PHP CLI in this environment — php-parser provides the parser.
 *
 * Usage:
 *   node tests/e2e/lint.js
 *
 * Looks for php-parser in (first hit wins):
 *   $E2E_HOME  →  current working directory  →  ~/.cache/e2e
 * (run `npm i php-parser@3` in one of those first)
 *
 * Override the plugin root with $AIPC_ROOT (default: this script's repo).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const PLUGIN = process.env.AIPC_ROOT || path.join(__dirname, '..', '..');

const searchPaths = [
  process.env.E2E_HOME,
  process.cwd(),
  path.join(os.homedir(), '.cache', 'e2e'),
].filter(Boolean);

let engine = null;
let loadedFrom = null;
for (const base of searchPaths) {
  try {
    engine = require(require.resolve('php-parser', { paths: [base] }));
    loadedFrom = base;
    break;
  } catch (e) {
    /* try next */
  }
}
if (!engine) {
  console.error('php-parser not found. Run `npm i php-parser@3` in one of: ' + searchPaths.join('  |  '));
  process.exit(2);
}

const parser = new engine({
  parser: { extractDoc: false, suppressErrors: false, version: 704 },
  ast: { withPositions: true },
});

function walk(dir, out) {
  for (const name of fs.readdirSync(dir)) {
    const full = path.join(dir, name);
    const stat = fs.statSync(full);
    if (stat.isDirectory()) {
      if (name === '.git' || name === 'node_modules' || name === 'languages') continue;
      walk(full, out);
    } else if (name.endsWith('.php')) {
      out.push(full);
    }
  }
  return out;
}

const files = walk(PLUGIN, []).sort();
let bad = 0;
for (const file of files) {
  const rel = path.relative(PLUGIN, file);
  try {
    parser.parseCode(fs.readFileSync(file, 'utf8'), rel);
    console.log('OK   ' + rel);
  } catch (e) {
    bad++;
    console.log('FAIL ' + rel + ' :: ' + e.message);
  }
}
console.log('\n' + files.length + ' files, ' + bad + ' failed  (php-parser from ' + loadedFrom + ')');
process.exit(bad ? 1 : 0);
