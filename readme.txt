=== WPilot AI ===
Contributors: wpilotai
Tags: ai, agent, elementor, woocommerce, seo
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your WordPress. Your AI. Your Control. Manage pages, Elementor layouts, WooCommerce products, and SEO through natural language with approval diffs and rollback.

== Description ==

WPilot AI is an open-source WordPress AI management agent designed for site owners, agencies, and developers. Instead of giving instructions you have to perform manually, WPilot AI inspects your site through official WordPress APIs, plans multi-step edits, classifies risk, displays before-and-after diffs, requests explicit approval for sensitive changes, verifies every write, and stores snapshots for instant rollback.

Key capabilities:
* OpenAI Responses API integration with server-side model allowlist, encrypted key storage, and structured tool calling
* Extensible Provider interface for additional AI providers
* Site Intelligence covering pages, posts, plugins, themes, media, Elementor, WooCommerce, and SEO
* Strict Permission Engine with WordPress nonces, per-object capability checks, task/conversation ownership isolation, strict JSON schema + tool validation, and atomic approval guards
* Verified Backup & Rollback Manager supporting complete post state (including trashed pages), Elementor data, and Yoast/Rank Math/Native SEO metadata
* User-scoped Audit Logs for editors and full audit visibility for administrators
* TypeScript + React Admin Workspace bundled for WordPress Core `wp-element` with Chat, Approval Queue, Backups/Rollback, Audit Logs, and Settings

== Installation ==

1. Upload the `wpilot-ai` directory to `/wp-content/plugins/` or install the ZIP via WordPress Admin > Plugins > Add New.
2. Activate the plugin through the Plugins screen.
3. Navigate to WPilot AI > Settings to configure your server-side OpenAI API key (or define `WPILOT_OPENAI_API_KEY` in `wp-config.php`).
4. Note: OpenAI API access uses separate API billing and is not included in a ChatGPT Plus subscription.

== Changelog ==

= 1.0.0 =
* Initial open-source release with Plugin Core, OpenAI Responses API provider, Tool Registry, ArgumentValidator, Task State Machine with atomic approval guards, WordPress/Elementor/WooCommerce/SEO tools, Verified Backup & Rollback, Audit Logs, and React + TypeScript Admin Workspace.
