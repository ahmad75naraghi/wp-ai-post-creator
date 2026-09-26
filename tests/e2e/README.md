# End-to-end tests (WordPress + SQLite via php-wasm)

This suite runs the **real plugin** against a **real WordPress 6.7 install**
(SQLite drop-in, PHP 8.3 WebAssembly via `@php-wasm/node`) and a **mock
OpenAI-compatible provider** implemented as a `pre_http_request` filter.

It exercises the full stack: plugin bootstrap, activation, REST routes,
permission checks, the whole agent pipeline (plan → outline → intro →
sections → conclusion → FAQ → SEO → image → finalize), post creation,
terms, SEO meta, FAQ schema, media library upload, cancel/retry flows,
settings sanitization and JSON extraction — 30 assertions in total.

## Layout

| File | Purpose |
|---|---|
| `mock-api.php` | Mu-plugin that answers `https://mock.invalid/v1/*` requests (chat, models, images) and logs every call |
| `install.php`  | Phase 1: fresh WP install + plugin activation + settings |
| `drive.php`    | Phase 2: unit checks + full agent run + assertions (prints JSON) |
| `e2e.js`       | Node runner |

## Running

```bash
# 1) Prepare a WordPress checkout with the SQLite driver (one-time):
mkdir wordpress && cd wordpress
curl -sL https://codeload.github.com/WordPress/WordPress/tar.gz/refs/tags/6.7.1 | tar xz --strip-components=1
curl -sL https://codeload.github.com/aaemnnosttv/wp-sqlite-db/tar.gz/refs/heads/master | tar xz
cp wp-sqlite-db-master/src/db.php wp-content/db.php
mkdir -p wp-content/mu-plugins wp-content/plugins
cp ../mock-api.php wp-content/mu-plugins/
cp -R ../../.. wp-content/plugins/wp-ai-post-creator   # the plugin source

# 2) Install the runtime and run:
npm i @php-wasm/universal @php-wasm/node
E2E_WP_ROOT="$PWD/wordpress" node e2e.js
```

The run prints a JSON report; `agent.status === "done"`, `post.status ===
"draft"`, `terms`, `meta`, `thumbnail` and the cancel/retry flows should all
be populated as documented in `drive.php`.
