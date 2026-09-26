/**
 * E2E runner: WordPress + SQLite (php-wasm) + AI Post Creator, driven through
 * the real REST stack against a mock OpenAI-compatible provider.
 *
 * Prereqs (see tests/e2e/README.md):
 *   npm init -y && npm i @php-wasm/universal @php-wasm/node
 *   E2E_WP_ROOT must contain WordPress with wp-content/db.php (wp-sqlite-db)
 *   and wp-content/mu-plugins/mock-api.php, and the plugin under
 *   wp-content/plugins/wp-ai-post-creator.
 */
const { PHP } = require('@php-wasm/universal');
const { loadNodeRuntime, useHostFilesystem } = require('@php-wasm/node');
const path = require('path');

const HERE = __dirname;
const WP_ROOT = process.env.E2E_WP_ROOT || path.join(HERE, 'wordpress');

(async () => {
  const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
  useHostFilesystem(php);

  const run = async (file) => php.run({ code: `<?php putenv('E2E_WP_ROOT=${WP_ROOT}'); require '${file}';` });

  console.log('=== phase 1: install WordPress + activate plugin ===');
  const r1 = await run(path.join(HERE, 'install.php'));
  if (r1.errors) process.stdout.write(r1.errors);
  console.log(r1.text);
  if (!r1.text || r1.text.indexOf('"installed":true') === -1) {
    console.error('PHASE 1 FAILED (exit ' + r1.exitCode + ')');
    process.exit(1);
  }

  console.log('\n=== phase 2: drive the agent ===');
  const r2 = await run(path.join(HERE, 'drive.php'));
  if (r2.errors) process.stdout.write(r2.errors);
  const marker = r2.text.indexOf('===E2E_JSON===');
  if (marker === -1) {
    console.error('PHASE 2 FAILED (exit ' + r2.exitCode + ')');
    console.log(r2.text);
    process.exit(1);
  }
  console.log(r2.text.slice(marker + '===E2E_JSON==='.length));
  process.exit(0);
})().catch((e) => {
  console.error('E2E ERROR:', e && e.message ? e.message : e);
  process.exit(1);
});
