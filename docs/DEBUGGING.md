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

Jobs live in the `{$wpdb->prefix}aipc_jobs` table (the JSON `payload` column
is the source of truth; status/counters are queryable columns). Retention is
90 days by default (`aipc_job_retention_days`, 0 = keep forever); pruning runs
on the daily cleanup cron and after each job creation.

## 2. Reading raw data

```sql
-- light job rows (lists/counters)
SELECT id, created, status, source, post_id, steps_done, steps_total, topic
  FROM wp_aipc_jobs ORDER BY created DESC LIMIT 20;

-- one full job (payload JSON)
SELECT payload FROM wp_aipc_jobs WHERE id = 'job_xxxxxxxx';
```
(or WP-CLI: `wp db query "SELECT payload FROM wp_aipc_jobs WHERE id='…'" | jq`)

If the table could not be created (DB user without CREATE rights) the plugin
falls back to the legacy option:
`SELECT option_value FROM wp_options WHERE option_name = 'aipc_jobs';`

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
| Job stalls mid-run (console shows the stall warning) | The background runner event was lost or wp-cron is throttled/never fires | The 15-min tick re-arms lost runner events automatically; check Site Health → cron and the Schedule page; with `DISABLE_WP_CRON` make sure a system cron runs often enough |
| `429 aipc_rate` on /start /step /state | Per-user per-minute REST limit hit | Wait a minute or raise the limit via the `aipc_rest_rate_limit` filter |
| Connection refused as "blocked by the outbound network guard" | `base_url` points at a private/reserved address | Add the host to the `aipc_outbound_allowlist` filter, or enable `aipc_allow_private_hosts`; loopback (Ollama/LM Studio) is allowed by default |
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
runners can't execute the same step concurrently. Since v1.6 the job is driven
by its own background runner (`aipc_run_job` cron event, self-rescheduling
every 30 s while work remains): a browser closing mid-run changes nothing. If
a runner event is lost, the 15-min scheduler tick re-arms it for every
`running` job; a lock left behind by a crashed run expires by itself.

## 7. Resetting to a clean state (dev only)

Deactivate + delete the plugin **with** "Delete all plugin data on uninstall"
enabled (Settings) to wipe options, job data, scheduled events and post meta.
In e2e, `install.php` already does the equivalent on every run.

## 8. Asking good questions in an issue

Always include: plugin version, WP/PHP versions, the failing step id (from
the job detail page), the exact error message, whether the run was manual or
scheduled, and (for scheduler issues) your cron setup. **Never paste API
keys.**
