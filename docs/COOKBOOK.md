# Development Cookbook

Step-by-step recipes for the most common changes, with the **exact files and
functions to touch**. Everything here follows the conventions in
[`AGENTS.md`](../AGENTS.md) — read that first. Deep background:
[`ARCHITECTURE.md`](ARCHITECTURE.md).

> Golden rule after any recipe: run the full verification
> (`lint.js` → `node --check` → `make-translations.py` → e2e) and update the
> assertions in `tests/e2e/drive.php` in the same change.

---

## Recipe 1 — Add a new pipeline step

Example: a "meta Social-teaser" step. Touch points, in order:

1. **Registry** — `includes/class-aipc-steps.php`, `registry()`:
   ```php
   'social_teaser' => array(
       'label' => __( 'Social teaser', 'wp-ai-post-creator' ),
       'kind'  => 'chat_json', // or chat_html / image
       'desc'  => __( 'Writes the social-media teaser…', 'wp-ai-post-creator' ),
       'prompt' => 'SOCIAL TEASER for "{{title}}" … Return ONLY this JSON object: {…}',
       'placeholders' => array(
           '{{title}}' => __( 'The planned article title', 'wp-ai-post-creator' ),
       ),
   ),
   ```
   The Prompts & Steps admin page renders registry entries automatically — no
   view change needed.

2. **Manifest** — `includes/class-aipc-agent.php`, `create_job()`: append the
   step to the `new`-mode (and/or `rewrite`-mode) step list:
   ```php
   $job['steps'][] = array( 'id' => 'social_teaser', 'label' => $reg['social_teaser']['label'], 'status' => 'pending' );
   ```
   Order = execution order. Steps without a registry entry need a hardcoded
   label (see `rw_finalize`).

3. **Dispatch** — same file, `run_step()`: add the `case` and implement
   `step_social_teaser( array &$job, array $conn )` following the existing
   pattern:
   - build `$args` (the `{{placeholders}}` map),
   - call `$data = $this->ask_json( $job, 'social_teaser', $args, array(), $conn )`
     (or `ask_html(…, $min_words, $conn)`),
   - **validate hard** — missing key? `throw new Exception( __( '…', 'wp-ai-post-creator' ) )`
     so the retry loop can re-run the step,
   - store into `$job['data']`, `$this->log( $job, …, 'success' )`,
   - end with `$this->advance( $job )`.

4. **Consume it** — usually in `step_finalize()` / the Post Builder (e.g. save
   as post meta in `AIPC_Post_Builder::create()/update()`).

5. **Optional gating** — if the step should be toggleable per run: add the arg
   in `sanitize_args()`, only append to the manifest when enabled (FAQ does
   this with `$job['args']['faq']`).

6. **Mock + tests**:
   - `tests/e2e/mock-api.php`: add a routing branch keyed on a unique phrase
     from your prompt (`$has( 'SOCIAL TEASER' )`).
   - `tests/e2e/drive.php`: assert the step appears in
     `$state['steps']`, the call used the expected connection, and the post
     meta was saved.
   - `tests/e2e/install.php`: only if it needs seeded config.

7. **Docs** — add the step id to the registry list in
   `ARCHITECTURE.md §4`; translations via `make-translations.py`
   (Recipe 7).

---

## Recipe 2 — Add a REST endpoint

1. `includes/class-aipc-rest.php`, `register_routes()`: copy an existing
   `register_rest_route( self::NS, '/thing', array( 'methods' => 'POST',
   'callback' => array( __CLASS__, 'thing' ), 'permission_callback' =>
   array( __CLASS__, 'can_edit' ), 'args' => array( … ) ) )`.
2. Permission: `can_edit` (edit_posts) or `can_manage` (manage_options) —
   never `__return_true`.
3. Callback: validate/sanitize every param, call the agent/services layer
   (never SQL or HTTP in the callback), return `rest_ensure_response()` or a
   `WP_Error` with an explicit `status`.
4. Frontend use: add the URL to the JS via `includes/class-aipc-assets.php`
   (the console talks to `CFG.restUrl + 'thing'` with `CFG.nonce` via
   `fetch`), and any new UI string to the inline `CFG.i18n`.
5. Tests: `rest_do_request( new WP_REST_Request( 'POST', '/aipc/v1/thing' ) )`
   in `drive.php` — assert status + shape + the anonymous-401 case.

---

## Recipe 3 — Add an admin page

1. `includes/class-aipc-admin.php`, `register()`:
   `add_submenu_page( 'aipc', $title, $menu_title, $capability, 'aipc-slug',
   array( __CLASS__, 'render_slug' ) )`.
   Capabilities: `edit_posts` for working screens, `manage_options` for config.
2. Add `public static function render_slug()` — capability guard +
   `require AIPC_PLUGIN_DIR . 'admin/views/slug.php';`.
3. Create `admin/views/slug.php` — copy the wrapper markup
   (`<div class="wrap aipc-wrap">`, `.aipc-header`, `.aipc-card`) so styling
   matches; escape everything.
4. `includes/class-aipc-assets.php`: add the hookname → screen mapping in
   `$plugin_pages` (hookname format: `toplevel_page_aipc` for the parent,
   `ai-post-creator_page_aipc-slug` for children) and enqueue the matching JS.
   Pass config with `wp_add_inline_script( 'aipc-agent', 'var AIPC_CFG = ' .
   wp_json_encode( self::data_for_agent( $extra ) ) . ';' )`.
5. Forms: point at `admin-post.php` and register an
   `admin_post_aipc_save_slug` handler (nonce field + `current_user_can()` +
   redirect back with `&updated=1`).
6. Tests: render the page in `drive.php`
   (`ob_start(); AIPC_Admin::render_slug(); $html = ob_get_clean();`) and
   assert on stable markers (element ids, option text).

---

## Recipe 4 — Add a setting

Example: a numeric `max_links` setting.

1. `includes/class-aipc-settings.php`:
   - `defaults()`: `'max_links' => 8,`
   - `sanitize()`: read with `isset()`, cast/clamp, else fall back to
     `$old['max_links']` (mirrors `source_sites` handling).
2. `admin/views/settings.php`: a `<tr>` in the right `<section>`; name it
   `aipc_settings[max_links]`; value `esc_attr( $aipc['max_links'] )`.
3. Consume it wherever needed via `AIPC_Settings::get( 'max_links' )`
   (or `AIPC_Settings::all()`).
4. Tests: add a case to the `sanitize_settings` (or a new
   `sanitize_v1XX`) group in `drive.php` proving clamping + fallback.
5. Translations (Recipe 7) + user-guide update if user-visible.

---

## Recipe 5 — Add a field to schedule entries

Follow the whole chain (this is where v1.5's `publish` field went):

1. `AIPC_Scheduler::sanitize_entry()` — default + whitelist/clamp.
2. `includes/class-aipc-admin.php`, `handle_save_schedule()` — read from
   `$_POST`, sanitize, pass into `AIPC_Scheduler::save_entry()`.
3. `admin/views/schedule.php` — a form field (and the default array for new
   entries), plus the summary column/table cell if it should be visible.
4. `AIPC_Scheduler::start_job_for_entry()` — merge it into `$opts` before
   `create_job()`.
5. `AIPC_Agent::sanitize_args()` — accept + clamp the matching run arg so
   manual REST runs accept it too.
6. Tests: the `sanitize_v1XX` group in `drive.php` (see how
   `publish_delay` clamping is asserted) + a UI marker assert in the
   `admin_pages.schedule` group.

---

## Recipe 6 — Add a connection field

1. `includes/class-aipc-connections.php`: `sanitize()` (validate, keep old
   value when the input is empty — the API-key pattern) + whatever default
   the client needs.
2. `admin/views/connections.php`: the form field.
3. Use it: `AIPC_API_Client` reads connection data by key; per-step chains
   pass the whole connection array around (`client_for( $conn )`).
4. `AIPC_Connections::all_for_ui()` must keep masking secrets (never return
   `api_key`).
5. Tests: sanitize asserts + a routing/run assert proving the field reaches
   the HTTP request (the mock logs headers/body).

---

## Recipe 7 — The translation workflow

1. After adding/changing any `__( '…', 'wp-ai-post-creator' )` call, run:
   ```bash
   python3 tests/e2e/make-translations.py
   ```
2. It **aborts with a MISSING list** for untranslated new strings. Add each
   to its `NEW_TRANSLATIONS` dict (Persian); for `_n()` plurals use the
   `{'0': …, '1': …}` form. Re-run until it exits 0.
3. Commit the regenerated `.po`, `.pot` **and** `.mo` together.
4. The e2e `i18n_fa` group loads the real `.mo` and round-trips old + new +
   plural strings — keep it green (extend it when you add interesting strings).
5. Never hand-edit the `.mo`; regenerate only.

---

## Recipe 8 — Extend the e2e suite

1. **Setup config** → `tests/e2e/install.php` (connections, steps, settings,
   schedule, Bale). Keep it idempotent — it wipes `wp-content/database/*`
   and the mock log on every run.
2. **Scripted provider responses** → `tests/e2e/mock-api.php`: branch on a
   unique prompt phrase **before** the fallback `return $chat( 'OK' )`, log
   before returning, and remember the mock logs only the first 4000 chars of
   a prompt.
3. **Assertions** → `tests/e2e/drive.php`, as a new group in `$out`
   (`$out['my_feature'] = array( 'ok' => … )`). Booleans must be `true`;
   non-bools (counts, ids, arrays) are informational.
4. **Failure injection** (retry/fallback tests): a `pre_http_request` filter
   at priority **5** in `drive.php` that returns an error response when a
   global flag is set, else `$preempt` untouched.
5. Run per `tests/e2e/README.md`; a helper for the pass/fail check is in
   `AGENTS.md`.

---

## Recipe 9 — Add a context helper (feed data into prompts)

For anything the agent should *know* while writing (v1.5 examples: recent
posts, link candidates, RSS sources):

1. Private method on `AIPC_Agent` returning a formatted string
   (see `recent_posts_context()` for the pattern; empty-state must be a
   translated "(None …)" line).
2. Call it while building the `$args` map of the relevant `step_*()`; the
   key must match a `{{placeholder}}` in the registry prompt **and** be
   documented in that entry's `placeholders` array.
3. Add a mock + a prompt-grounding assertion (the `research_sources` group
   in `drive.php` is the template).
4. Guard performance: cap the helper (post count, chars) and never let it
   throw outside a step.

---

## Recipe 10 — Release a version

Follow [`RELEASE-CHECKLIST.md`](RELEASE-CHECKLIST.md) end to end (version
bumps in 3 files, changelog, translations, e2e green, commit `vX.Y.Z — …`,
push the arena branch, REST-PATCH PR #1, verify mergeable).

---

## Quick reference — where things live

| I want to change… | Go to |
|---|---|
| A prompt / add a step to the registry | `AIPC_Steps::registry()` |
| Execution order / run modes | `AIPC_Agent::create_job()` |
| Retry/fallback behavior | `AIPC_Agent::execute_step()` + `aipc_step_attempts` filter |
| What the model sees | the `step_*()` `$args` maps + registry prompts |
| How a post is assembled/saved | `AIPC_Post_Builder::create()/update()/build_content()` |
| Publish behavior | `step_finalize()` + `AIPC_Scheduler::publish_post()` |
| REST surface | `AIPC_Rest::register_routes()` + callbacks |
| Console UI/behavior | `admin/views/new-post.php`/`rewrite.php` + `assets/admin-agent.js` + `AIPC_Assets::data_for_agent()` |
| Scheduler semantics | `AIPC_Scheduler::tick()/entry_due()/start_job_for_entry()` |
| Bale messages | `AIPC_Bale::notify()/notify_published()/build_report()` |
| Git self-update behavior | `AIPC_Updater::run()/remote_version()` + the `aipc-update` page |
| Mocked test world | `tests/e2e/mock-api.php` |
| Assertions | `tests/e2e/drive.php` |
