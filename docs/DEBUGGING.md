# Debugging Guide

For developers and advanced users. Order of investigation for any problem:
**job log → mock/provider evidence → code path**.

## 1. First: the job detail page

**AI Post Creator → Logs → (job) → detail.** Everything below is recorded
there per job:

- `log[]` — human-readable timeline (levels: info / success / warn / error),
  including retry and fallback lines
  (`Attempt 1/3 on «Conn» failed (…) — retrying…`,
  `Connection «X» failed after 3 attempts — switching to «Y».`)
- `calls[]` — every API call: step, connection name, model, duration ms,
  ok/fail, error text, tokens
- `timings` — per-step wall clock
- `usage` — token totals
- the source (`manual` / `cron`), mode (`new` / `rewrite`) and the final result

Roughly 30 jobs are kept in `aipc_jobs` (autoload off); old ones are pruned by
the daily cleanup cron.

## 2. Reading raw data

```sql
-- current jobs (option, autoload off)
SELECT option_value FROM wp_options WHERE option_name = 'aipc_jobs';
```
(or WP-CLI: `wp option get aipc_jobs --format=json | jq '.[0]'`)

Post side: meta `_aipc_generated`, `_aipc_job` (job id),
`_aipc_faq_schema`, `_aipc_meta_title`, `_aipc_meta_description`.

## 3. Enable real errors

The plugin never surfaces PHP notices by design. To see them during debugging:

```php
// wp-config.php — never on production
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );     // writes wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false );
```

In the e2e suite, warnings/deprecations in the phase-2 output count as
failures — the log must stay clean.

## 4. Symptom → cause → fix

| Symptom | Likely cause | Fix |
|---|---|---|
| `401/Unauthorized` in calls | Wrong API key or base URL (must include `/v1`) | Connections → edit + Test connection |
| `خطای API 500: …` repeatedly on one step | Provider rejecting the request (model name, params) or genuinely down | Check the call's error text in the job detail; try another model; the fallback chain (Prompts & Steps) switches providers automatically |
| Step fails with "did not include / lost the structure" | Model ignored the format contract (JSON/H2 structure) | The agent retries 3× per connection; if it persists use a stronger model for that step |
| Featured image missing but post done | Whole image chain failed → step intentionally skipped (log: `Image generation failed on every connection`) | Check image model/size; the post continues by design |
| Schedule never fires | wp-cron not running on low-traffic sites | Site Health → cron; add a system cron hitting `wp-cron.php` every minute |
| Delayed publish never happened | Same wp-cron issue (the `aipc_publish_post` event waits for cron) | Same; the handler is idempotent — a late wake-up still publishes |
| Anonymous REST call returns 401 | Expected | Log in / use an app password with proper capabilities |
| Publish options ignored over REST | Calling user lacks `publish_posts` | By design (`sanitize_args` drops them) |
| Rewrite job created then failed on post check | Post id invalid or not editable by that user | Pass an existing post you can edit |
| Bale messages missing but job done | Bale disabled, token empty, no recipients, or delivery errors (per-chat results in the job log) | Schedule → Bale section → Send test message |
| Report sent twice / never | `last_report` date handling | Check `aipc_bale` option (`report`, `report_time`, `last_report`) and the tick log |
| Topics duplicate older posts | Prompt/model ignored the recent-posts block | Sharpen the site prompt; consider a stronger model for the plan step |
| Translations missing after adding strings | `.mo` not regenerated | `python3 tests/e2e/make-translations.py` (fix MISSING) and redeploy `.po/.mo` |

## 5. Inspecting the actual HTTP traffic

- **Live site:** the job's `calls[]` (see 1). For deeper needs, temporarily log
  `wp_remote_*` args via a mu-plugin `pre_http_request` filter — remove it
  after (never log Authorization headers in production).
- **E2E:** everything is already logged to
  `wp-content/mock-api-log.jsonl` — one JSON line per request with host,
  auth header, model and the first 4000 chars of the prompt. Grepping this
  file answers most "what did the agent actually send?" questions.

## 6. Locked / stuck jobs

Each running job holds a transient lock `aipc_lock_<job_id>` (600 s) so two
browsers can't execute the same step concurrently. If a step died mid-flight
(never happens with normal REST flow), the lock expires by itself; a job left
in `running` is picked up by the next scheduler tick (cron source) or can be
cancelled from the console.

## 7. Resetting to a clean state (dev only)

Deactivate + delete the plugin **with** "Delete all plugin data on uninstall"
enabled (Settings) to wipe options, job data, scheduled events and post meta.
In e2e, `install.php` already does the equivalent on every run.

## 8. Asking good questions in an issue

Always include: plugin version, WP/PHP versions, the failing step id (from
the job detail page), the exact error message, whether the run was manual or
scheduled, and (for scheduler issues) your cron setup. **Never paste API
keys.**
