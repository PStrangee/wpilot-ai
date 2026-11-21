# Contributing to WPilot AI

We welcome contributions to WPilot AI across PHP tools, AI providers, Elementor/WooCommerce adapters, localization (especially RTL languages), and automated tests.

## Development Workflow

1. Fork the repository and create a feature branch from `main`.
2. Install PHP dev dependencies (`composer install`) and frontend dependencies (`npm install`).
3. Ensure every new tool implements `WPilotAI\Tools\ToolInterface` with explicit `get_required_capability()`, `get_risk_level()`, `validate()`, and `verify()`.
4. Run quality gates before opening a PR:
   - `composer test` (PHPUnit)
   - `composer stan` (PHPStan level 6)
   - `composer cs` (WordPress Coding Standards)
   - `npm test` (TypeScript/React unit tests)

## Security Expectations

- Never expose API keys to browser JavaScript or REST responses.
- Never bypass `current_user_can()` or REST `permission_callback`.
- Never execute arbitrary AI-generated PHP code.
