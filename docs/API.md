# WPilot AI REST API (`wpilot/v1`)

All endpoints require an authenticated WordPress session with a valid `X-WP-Nonce` (`wp_rest`) header. Internal server errors are logged server-side and never expose stack traces or SQL diagnostics in REST responses.

| Method | Route | Capability & Ownership | Purpose |
| :--- | :--- | :--- | :--- |
| `GET` | `/wp-json/wpilot/v1/site-overview` | Allowed role + per-tool capability filter | Fetch site info, pages, plugins, media, and products filtered to the caller's WordPress capabilities |
| `GET` | `/wp-json/wpilot/v1/conversations` | Allowed role (owner-scoped) | List the authenticated user's conversations |
| `POST` | `/wp-json/wpilot/v1/conversations` | Allowed role + allowlisted model | Create a new conversation owned by the authenticated user |
| `GET` | `/wp-json/wpilot/v1/conversations/{id}/messages` | Allowed role + strict conversation owner | Read messages for a conversation owned by the caller |
| `POST` | `/wp-json/wpilot/v1/chat` | Allowed role + conversation owner + allowlisted model | Run a validated multi-step AI turn (max 20 messages, `user`/`assistant` roles only, 8,000 bytes/msg) |
| `GET` | `/wp-json/wpilot/v1/tasks` | Allowed role (strict task owner) | List only tasks owned by the authenticated user |
| `POST` | `/wp-json/wpilot/v1/tasks/{id}/approve` | Strict task owner + tool capability + atomic lock | Re-validate arguments, atomically claim, execute, and verify a queued write task |
| `POST` | `/wp-json/wpilot/v1/tasks/{id}/cancel` | Strict task owner + atomic lock | Cancel a queued task owned by the authenticated user |
| `GET` | `/wp-json/wpilot/v1/backups` | `manage_options` | List captured pre-write snapshots |
| `POST` | `/wp-json/wpilot/v1/backups/{id}/rollback` | `manage_options` | Restore and verify a captured post, Elementor, SEO, or attachment metadata snapshot |
| `GET` | `/wp-json/wpilot/v1/audit-logs` | Allowed role (admin: all, non-admin: own logs) | Fetch immutable audit trail entries |
| `GET` | `/wp-json/wpilot/v1/settings` | `manage_options` | Retrieve public-safe settings and allowed model IDs (never exposes raw API keys) |
| `POST` | `/wp-json/wpilot/v1/settings` | `manage_options` | Update allowlisted model, rate limits, policies, or write-only encrypted OpenAI API key |
