# REST API Reference

The plugin exposes a small REST namespace for driving the agent and testing
connections. Base URL: `{$site}/wp-json/aipc/v1/` — authentication is the
standard WordPress cookie + nonce (the bundled admin JS sends the `wp_rest`
nonce created for logged-in users).

**Capabilities:** `start/step/state/cancel/retry` require **`edit_posts`**;
`connection/*` and `bale/*` require **`manage_options`**. Anonymous requests
get HTTP 401. Auto-publish params are only honored for users with
`publish_posts`.

**Rate limits (v1.6+):** `/start`, `/step` and `/state` enforce a per-user,
per-minute cap (30/240/300; 0 disables) — exceeding it returns HTTP 429 with
`{"code":"aipc_rate"}`. Adjust via the `aipc_rest_rate_limit` filter.

**Background runner (v1.6+):** `/start` arms a server-side runner that drives
the job to completion on its own; the console only watches via `/state`.

---

## Agent endpoints

### POST `/aipc/v1/start`

Creates a job and returns its initial state.

**Parameters** (all optional unless noted):

| Param | Type | Values / notes |
|---|---|---|
| `topic` | string | ≤400 chars; empty = the agent invents one from the site prompt |
| `tone` | string | one of `AIPC_Settings::tones()` keys (professional, friendly, casual, expert, persuasive, storytelling, humorous, neutral) |
| `length` | string | `short` (3 sections ≈600 w) · `medium` (5 ≈1200) · `long` (8 ≈2200) |
| `language` | string | language key (`fa`, `en`, `ar`, …) |
| `language_custom` | string | English name of the language when `language=other` |
| `image` | bool | generate a featured image (also requires the global setting) |
| `faq` | bool | FAQ block + FAQPage schema |
| `toc` | bool | table of contents |
| `mode` | string | `new` (default) or `rewrite` |
| `post_id` | int | **required for `mode=rewrite`** — the post to rewrite (must exist and be editable) |
| `publish_mode` | string | `draft` (default) · `now` · `delay` — only forwarded with `publish_posts` |
| `publish_delay` | int | minutes, clamped to 15–10080; only used with `delay` |

**Response:** a `client_state` object (see below). Errors → 400 with the
plugin's message (e.g. invalid rewrite target).

### POST `/aipc/v1/state`

Read-only view of a job — returns the same `client_state` object as `/step`
**without executing anything**. This is what the console polls while the
background runner does the work.

| Param | Type | Notes |
|---|---|---|
| `job_id` | string | **required** |
| `since` | int | log cursor from the previous response |

Unknown job → 404.

### POST `/aipc/v1/step`

Executes exactly one pending step of the job (kept for compatibility and for
manual drivers; the console no longer loops this since v1.6).

| Param | Type | Notes |
|---|---|---|
| `job_id` | string | **required** |
| `since` | int | log cursor from the previous response |

Returns the updated `client_state` (`logs` contains only lines after
`since`, `since` the new cursor). When another caller holds the step lock you
get the current state plus `"busy": true`.

### POST `/aipc/v1/cancel`

| Param | Type |
|---|---|
| `job_id` | string (required) |

Marks a running job `cancelled`; further `step` calls are no-ops.

### POST `/aipc/v1/retry`

| Param | Type |
|---|---|
| `job_id` | string (required) |

Resets the failed step of an `error` job and sets it back to `running`.

### The `client_state` object

```json
{
  "id": "job_abc123",
  "status": "running",              // running | done | error | cancelled
  "topic": "سبزی‌کاری در بالکن",
  "steps": [ { "id": "plan", "label": "Category & topic selection", "status": "done" }, … ],
  "progress": 42,                   // 0–100, null until the outline exists
  "logs":   [ { "t": 1727400000, "msg": "…", "level": "info" } ],   // since the cursor
  "since":  14,                     // next cursor
  "usage":  { "prompt": 1320, "completion": 880, "calls": 12 },
  "result": {                       // present when status=done
    "post_id": 5,
    "title": "…",
    "edit": "https://…/wp-admin/post.php?post=5&action=edit",
    "view": "https://…/?p=5",
    "words": 323,
    "status": "draft"               // draft | publish | scheduled
  },
  "error":  null                    // or { "step": "plan", "message": "…" }
}
```

---

## Connection endpoints (`manage_options`)

### POST `/aipc/v1/connection/test`

Tests one connection — either a saved one (`id`) or raw values
(`base_url`, `api_key`, `chat_model`). Returns
`{ "ok": true, "model": "…" }` or `{ "ok": false, "error": "…" }`.

### POST `/aipc/v1/connection/models`

Lists models for raw or saved connection values. Returns
`{ "models": [ "mock-mini", "mock-pro", "dall-e-3" ] }`.

---

## Bale endpoints (`manage_options`)

### POST `/aipc/v1/bale/test`

| Param | Type | Notes |
|---|---|---|
| `token` | string | optional — falls back to the stored token |
| `chat_ids` | string | optional list (one per line) — falls back to stored recipients |
| `chat_id` | string | single-recipient shorthand |

Sends a test message to **every** recipient and reports per-chat results:
`{ "ok": true, "sent": 2, "errors": [] }`.

### POST `/aipc/v1/bale/chat-id`

Calls `getUpdates` on the stored bot and returns the newest detected chat id:
`{ "ok": true, "chat_id": "98765" }`. Send a message to your bot first.

---

## Example: a full run with curl

```bash
# 1) start (admin cookie + nonce required — or use an application password
#    for a user with the right capabilities)
curl -X POST "$SITE/wp-json/aipc/v1/start" \
  -u "admin:application-password" \
  -H "Content-Type: application/json" \
  -d '{"topic":"","length":"short","language":"fa","image":true,"faq":true}'

# 2) drive it to completion
curl -X POST "$SITE/wp-json/aipc/v1/step" -u "admin:…app-password" \
  -H "Content-Type: application/json" -d '{"job_id":"job_abc123","since":0}'
# …repeat with the returned "since" until "status" is done/error
```

## Notes & caveats

- The REST layer is intentionally thin: every heavy operation lives in
  `AIPC_Agent`/`AIPC_*` classes (also used by cron and admin-post), so REST,
  cron and UI behave identically.
- Job data is public-projection only (`client_state`); stored jobs, calls and
  API keys are never exposed via REST.
- A job that hits an error keeps its state; fix nothing server-side — call
  `/retry` (or press the button in the console) and continue with `/step`.
- Rewrite jobs update the existing post: the `result.post_id` equals the
  `post_id` you passed to `/start`, and `result.status` mirrors the post's
  own status (publish modes do not apply to rewrites — the post keeps what it
  had).
