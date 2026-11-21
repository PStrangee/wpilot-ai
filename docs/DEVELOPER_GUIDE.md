# WPilot AI Developer Guide

## Registering a Custom Tool

Third-party plugins and themes can extend WPilot AI using the `wpilot_register_tools` action:

```php
add_action( 'wpilot_register_tools', function ( \WPilotAI\Tools\ToolRegistry $registry, \WPilotAI\Backup\BackupManager $backups ) {
    $registry->register( new \MyPlugin\CustomAuditTool() );
}, 10, 2 );
```

Every tool must implement `\WPilotAI\Tools\ToolInterface`:
- `get_required_capability()` — WordPress capability required before the model can see or invoke the tool. Tools targeting specific posts or files must also perform per-object `current_user_can()` checks (e.g. `edit_post`, `delete_post`, `edit_themes`).
- `get_risk_level()` — `low` (auto-run read), `medium` (approval diff + snapshot), or `high` (mandatory approval + snapshot).
- `get_parameters_schema()` — Strict JSON Schema (`additionalProperties: false`, explicit types, bounds, and `required` fields). Enforced by `\WPilotAI\Tools\ArgumentValidator` on both execution/queueing and task approval.
- `validate()`, `execute()`, and `verify()` — `ArgumentValidator` invokes `validate()` before execution and approval; `Orchestrator` enforces `verify()` and marks the task failed if verification does not pass.

## Registering an Additional AI Provider

Implement `\WPilotAI\AI\ProviderInterface` and register via `wpilot_register_providers`.

## Extending Allowed Models

Server-side model enforcement defaults to `gpt-4.1`, `gpt-4.1-mini`, and `o4-mini`. Customize with the `wpilot_allowed_models` filter:

```php
add_filter( 'wpilot_allowed_models', function ( array $models ): array {
    return array_values( array_unique( array_merge( $models, array( 'gpt-4.1' ) ) ) );
} );
```

## Building & Testing Locally

### Frontend (TypeScript + React)

```bash
cd wpilot-ai
npm install
npm run lint     # tsc --noEmit
npm test         # tsx --test assets/src/utils.test.ts
npm run build    # esbuild -> assets/build/admin.js & admin.css
```

### Backend (PHP 8.2+ & Composer)

```bash
cd wpilot-ai
composer install
composer lint    # php -l across all plugin PHP files
composer test    # PHPUnit test suite
composer stan    # PHPStan static analysis (WordPress + WooCommerce stubs)
composer cs      # PHP_CodeSniffer (WordPress Coding Standards)
```
