# WPilot AI

**Your WordPress. Your AI. Your Control.**

WPilot AI is an open-source, modular WordPress AI management agent built in PHP 8.2+ and React/TypeScript. It inspects real WordPress site state, plans multi-step operations, classifies risk, validates every tool call against strict schemas, queues mutations for explicit owner approval, verifies every write, and captures complete pre-write snapshots for verified rollback.

## Architecture & Core Modules

- **Core (`includes/Core`)**: Plugin lifecycle, `dbDelta` schema installer, libsodium/AES-256-GCM encrypted API key storage, server-side OpenAI model allowlist, structured `WPilotException` error boundary, and WordPress Admin SPA loader (`wp-element`).
- **AI Layer (`includes/AI`)**: Extensible `ProviderInterface` with a full OpenAI Responses API (`POST /v1/responses`) provider supporting structured function tool calling, malformed JSON detection, timeouts, retries, and usage tracking.
- **Agent & Tools (`includes/Agent`, `includes/Tools`)**: Strict 9-state `TaskStateMachine`, multi-step `Orchestrator` with atomic SQL approval locks, `ArgumentValidator` (JSON Schema + per-tool `validate()`), capability-filtered `ToolRegistry`, and 22 tools across WordPress Core, Elementor, WooCommerce, SEO (Yoast, Rank Math, Native), Code Inspection, and System Backup/Rollback.
- **Security (`includes/Security`)**: WordPress nonce verification, capability & per-object `edit_post`/`delete_post` checks, strict task and conversation ownership isolation, hourly transient rate limiting, prompt-injection delimiter isolation, and `realpath()`-confined `FileGuard`.
- **Backup & Audit (`includes/Backup`, `includes/Logging`)**: Complete pre-write snapshots (including `post_status`, Elementor metadata, and exact SEO post meta keys with existence tracking), verified rollback (including restoring trashed pages), and user-scoped audit trails.
- **Frontend (`assets/src` -> `assets/build`)**: React + TypeScript WordPress Admin UI compiled with `esbuild` against WordPress Core's `wp-element` runtime (`npm run build`), with `tsc --noEmit` typechecking and `node:test` unit tests.

## Installation

1. Copy `wpilot-ai/` into `wp-content/plugins/` (or upload the packaged `wpilot-ai.zip` via **Plugins > Add New > Upload Plugin**).
2. Activate **WPilot AI** in WordPress Admin.
3. Configure your server-side OpenAI API key via **WPilot AI > Settings** or define `WPILOT_OPENAI_API_KEY` in `wp-config.php`.
4. Note: OpenAI API billing is separate from ChatGPT Plus subscriptions.

## Development & Quality Checks

### PHP (requires PHP 8.2+ and Composer)
```bash
composer install
composer lint   # PHP syntax check across includes/, tests/, wpilot-ai.php, uninstall.php
composer test   # PHPUnit security & unit test suite (standalone WordPress harness)
composer stan   # PHPStan level 6 with WordPress & WooCommerce stubs
composer cs     # WordPress Coding Standards (phpcs.xml.dist)
```

### Frontend (requires Node.js 22+)
```bash
npm install
npm run lint    # Strict TypeScript typecheck (tsc --noEmit)
npm test        # Frontend unit tests (assets/src/utils.test.ts)
npm run build   # Builds assets/build/admin.js and assets/build/admin.css
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
