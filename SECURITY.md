# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.5.x   | ✅ |
| < 1.5   | ❌ — update first |

## Reporting a vulnerability

Please report vulnerabilities privately via
[GitHub security advisories](https://github.com/ahmad75naraghi/wp-ai-post-creator/security/advisories/new)
so the fix can be coordinated and released before public disclosure. Do **not**
open a public issue for security problems.

Include: affected version, steps to reproduce, impact assessment, and — if you
have it — a proof of concept. You will get an acknowledgement within a few days.

## Security design notes

For reviewers and auditors — how the plugin handles sensitive data today:

- **API keys are write-only.** Keys are stored in the `aipc_connections` option
  in the site's own database and are never returned to the browser, never
  included in REST responses, the connections list, or logs. The key field is
  write-only in the UI (leave empty to keep the stored key).
- **Keys are sent only to the provider configured for that connection** via the
  `Authorization: Bearer` header over HTTPS.
- **Capability checks everywhere:** the agent REST endpoints (`start`, `step`,
  `cancel`, `retry`) and the console pages require `edit_posts`; connections,
  prompts, logs, schedule, settings and their REST endpoints require
  `manage_options`. Auto-publish options additionally require `publish_posts`
  and rewrite runs require `edit_post` on the target post.
- **Nonces** protect every admin-post form and REST relies on cookie
  authentication + capability checks (standard WP REST).
- **Output escaping:** all view output is escaped; AI-generated HTML is inserted
  via `wp_kses_post`-compatible paths and meta values are sanitized on save.
- **Uninstall** removes plugin data (options, post meta, cron events) only when
  the site owner explicitly enabled "Delete all plugin data on uninstall".

### Outbound network guard (SSRF) — since v1.6.0

All plugin-controlled outbound URLs go through `AIPC_Network::is_safe_url()`:

- applied in `AIPC_Connections::sanitize()` (new values only — already-stored
  URLs are grandfathered until changed), `AIPC_Settings::sanitize()`
  (`source_sites`), the REST connection test, and `AIPC_API_Client::download()`
  (provider-returned image URLs);
- only `http`/`https`, no credentials in the URL;
- blocked: private/reserved/CGNAT/multicast IPv4 ranges, IPv6 ULA/link-local/
  multicast/IPv4-mapped ranges, and empty hosts;
- loopback (`localhost`, `*.localhost`, `127.0.0.0/8`, `::1`) is **allowed by
  default** so local LLM servers (Ollama, LM Studio) keep working.

Filters:

- `aipc_outbound_allowlist` — array of extra allowed hosts, exact or `*.suffix`
  wildcard (`array( '10.1.2.3', '*.corp.example' )`);
- `aipc_allow_private_hosts` — return `true` to allow all private ranges;
- `aipc_allow_loopback` — return `false` to block loopback too.

Hostnames are **not** DNS-resolved (a hostname resolving to a private IP is not
detected). Strict environments should additionally define `WP_HTTP_BLOCK_EXTERNAL`
with `WP_ACCESSIBLE_HOSTS` — WordPress then blocks non-allowlisted outbound
requests for the whole site, independently of this guard.

### REST rate limiting — since v1.6.0

The agent endpoints (`/start`, `/step`, `/state`) enforce a per-user,
per-minute limit (30/240/300; 0 disables) via rolling transients. Exceeding it
returns HTTP 429 with code `aipc_rate`. Adjust with:

```php
add_filter( 'aipc_rest_rate_limit', function ( $limit, $route ) {
    return 'start' === $route ? 10 : $limit;
}, 10, 2 );
```
