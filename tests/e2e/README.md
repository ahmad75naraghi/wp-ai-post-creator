# End-to-end tests (WordPress + SQLite via php-wasm)

This suite runs the **real plugin** against a **real WordPress 6.7 install**
(SQLite drop-in, PHP 8.3 WebAssembly via `@php-wasm/node`) and **two mock
OpenAI-compatible providers** implemented as a `pre_http_request` filter.

It exercises the full stack: plugin bootstrap, activation, migration, REST
routes, permission checks, the whole agent pipeline (plan → outline → intro →
sections → conclusion → copywrite → FAQ → SEO → image → finalize), post
creation, terms, SEO meta, FAQ schema, media library upload, cancel/retry
flows, connection & settings sanitization, the fa_IR translation bundle and
JSON extraction — 45+ assertion groups in total.

The two mock hosts verify **per-step connection routing at the HTTP level**:

| Host | Purpose | Assigned to |
|---|---|---|
| `https://mock.invalid/v1` | Chat completions (Bearer `sk-chat-key`) | default connection — all text steps |
| `https://images.invalid/v1` | Image generations (Bearer `sk-image-key`) | the `image` step via Prompts & Steps |

A custom FAQ prompt (marker `FAQ-QUESTIONS-CUSTOM`) is configured for the
`faq` step to prove per-step prompt templates are actually sent.

## Layout

| File | Purpose |
|---|---|
| `mock-api.php` | Mu-plugin that answers both mock hosts (chat, models, images) and logs every call to `wp-content/mock-api-log.jsonl` |
| `install.php`  | Phase 1: fresh WP install + plugin activation + two connections + per-step config |
| `drive.php`    | Phase 2: unit checks + full agent run + admin-page render checks + assertions (prints JSON) |
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
"draft"`, `calls.chat_conns === ["Chat Mock"]`, `calls.image_conn ===
["Image Mock"]`, `terms`, `meta`, `thumbnail`, `i18n_fa` and the cancel/retry
flows should all be populated as documented in `drive.php`.
