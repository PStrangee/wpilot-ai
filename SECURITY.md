# Security Policy

## Threat Model & Built-in Safeguards

WPilot AI enforces defense-in-depth across every layer of the WordPress plugin:

1. **Authentication & Ownership Isolation**
   - Every REST endpoint requires WordPress authentication and a valid `X-WP-Nonce` (`wp_rest`).
   - Tasks are strictly isolated per user: users can only list, approve, or cancel their own tasks.
   - Conversations verify ownership (`wpilot_conversations.user_id === current_user_id`) before reading messages, writing turns, or queueing tasks.
   - Audit logs are scoped to the authenticated user for non-administrators.

2. **Capability & Argument Enforcement**
   - Tools declare required WordPress capabilities and enforce per-object checks (`edit_post`, `delete_post`, `publish_pages`, `edit_themes`, `manage_options`).
   - Every tool invocation is validated against its strict JSON Schema (`additionalProperties: false`, types, bounds, enums) and its custom `validate()` hook both before execution/queueing and again on approval.
   - Atomic conditional SQL updates prevent duplicate task execution and approval race conditions.

3. **AI & Input Hardening**
   - Model IDs are restricted to a server-side allowlist (`gpt-4.1`, `gpt-4.1-mini`, `o4-mini`, filterable via `wpilot_allowed_models`).
   - Chat endpoints strictly allow only `user` and `assistant` roles and enforce message count (20) and byte length limits (8,000 bytes per message, 32,000 total).
   - Untrusted WordPress page, post, product, media, and file content is wrapped in `<untrusted_site_content>` delimiters with closing-tag escaping.
   - OpenAI API keys are encrypted at rest with libsodium (`XChaCha20-Poly1305`) or AES-256-GCM (or loaded via `WPILOT_OPENAI_API_KEY`) and are never returned to browsers.

4. **Backup & Verified Rollback**
   - Mutations abort immediately if snapshot persistence fails.
   - Post snapshots preserve complete state including `post_status` and `post_name`; rolling back trashed pages uses `wp_untrash_post()` plus `wp_update_post()` and verifies every field afterward.
   - Elementor and SEO rollbacks preserve exact WordPress meta keys and existence flags (removing keys that did not exist before mutation) and verify persistence after restoration.

## Reporting a Vulnerability

Please report security issues privately to the maintainers rather than opening a public GitHub issue. Include reproduction steps, affected version, and WordPress/PHP versions.
