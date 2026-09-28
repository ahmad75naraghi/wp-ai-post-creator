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

### Known hardening roadmap (not yet implemented)

- `base_url` and `source_sites` currently accept any `http(s)` host. In
  multisite/hosted environments this can be used to make the server issue
  requests to internal addresses (SSRF). Admin-only today (`manage_options`),
  but private-IP/localhost blocking and allowlists are planned for 1.6.
- REST endpoints do not yet rate-limit; combined with `edit_posts` they are
  only as safe as your editors.
