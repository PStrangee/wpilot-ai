# WPilot AI Architecture

## System Layers

1. **Presentation Layer (React + TypeScript Admin Workspace)**
   - Source lives in `assets/src/` (`App.tsx`, `api.ts`, `utils.ts`, `types.ts`, `styles.css`) and compiles via `esbuild` (`build.mjs`) into `assets/build/admin.js` and `assets/build/admin.css`.
   - Enqueued inside WordPress Admin (`toplevel_page_wpilot-ai`) using WordPress Core's `wp-element` React runtime and `wp_rest` nonce localization.
   - Zero access to server-side AI credentials.
2. **REST Gateway (`WPilotAI\REST\RestController`)**
   - Namespace: `wpilot/v1`.
   - Enforces `permission_callback`, per-tool capability checks, strict task & conversation ownership isolation, user-scoped audit logs, model allowlist checks, and sanitized error responses via `WPilotException`.
3. **Orchestration & State Machine (`WPilotAI\Agent`)**
   - Strict lifecycle: `pending -> planning -> awaiting_approval -> executing -> verifying -> completed | failed | cancelled | rolled_back`.
   - Enforces message role (`user`, `assistant`) and byte limits, runs `ArgumentValidator` (JSON Schema + `$tool->validate()`) on both execution and approval, and uses atomic conditional SQL updates to prevent duplicate approvals and race conditions.
4. **AI Provider Abstraction (`WPilotAI\AI`)**
   - `ProviderInterface` decouples orchestration from OpenAI (`POST /v1/responses`) so additional providers can be registered via `wpilot_register_providers`.
   - Flags malformed tool-call JSON payloads so incomplete calls are never executed.
5. **Tool Registry & Adapters (`WPilotAI\Tools`, `WPilotAI\SEO`)**
   - Every tool declares capability, risk level, strict JSON Schema (`additionalProperties: false`), `validate()`, `execute()`, and `verify()`, plus per-object capability checks (`edit_post`, `delete_post`, `edit_themes`).
   - SEO adapters (`YoastAdapter`, `RankMathAdapter`, `NativeSeoAdapter`) expose exact WordPress meta keys for lossless backup and rollback.
6. **Safety, Backup & Audit (`WPilotAI\Security`, `WPilotAI\Backup`, `WPilotAI\Logging`)**
   - Captures complete pre-mutation snapshots (including `post_status`, `post_name`, Elementor metadata, and existence-aware SEO/attachment post meta) into `wp_wpilot_backups`.
   - Verifies every restored field/meta after rollback (including untrashing trashed pages via `wp_untrash_post`) and records every decision in `wp_wpilot_audit_logs`.
