# Changelog

## [1.0.0] - 2026-11-21

### Added
- WordPress plugin bootstrap (`wpilot-ai.php`), PSR-4 autoloader, `dbDelta` database schema, and clean uninstall script.
- Encrypted server-side Settings manager supporting `WPILOT_OPENAI_API_KEY` constant and libsodium/AES-256-GCM encryption.
- OpenAI Responses API provider (`POST /v1/responses`) with structured function tool calling, retry backoff, and token usage accounting.
- 9-state `TaskStateMachine` and `Orchestrator` with automatic low-risk inspection and human-approved write execution.
- 14 production tools covering WordPress Core, Elementor, WooCommerce, SEO (Yoast, Rank Math, Native), and System Rollback.
- Backup Manager with verified rollback and immutable Audit Logger.
- React + TypeScript Admin Workspace with Dark/Light modes and English/Persian (RTL) localization.
