# Installing WPilot AI on WordPress

## Requirements

- WordPress 6.4 or newer
- PHP 8.2 or newer (with `sodium` or `openssl` and `json` extensions enabled)
- OpenAI API key with active API billing credits (ChatGPT Plus subscriptions do not include API usage)

## Method 1: Upload ZIP in WordPress Admin (Recommended)

1. Download `wpilot-ai.zip` from the WPilot AI workspace.
2. In WordPress Admin, go to **Plugins > Add New Plugin > Upload Plugin**.
3. Choose `wpilot-ai.zip` and click **Install Now**, then **Activate Plugin**.
4. Open **WPilot AI** in the left WordPress Admin menu, navigate to **Settings**, and save your OpenAI API key (stored encrypted at rest on the server).

## Method 2: Define Key in `wp-config.php`

For managed hosting or CI/CD environments, define the key as a PHP constant above `/* That's all, stop editing! */`:

```php
define( 'WPILOT_OPENAI_API_KEY', 'sk-proj-your-api-key' );
```

## Optional Integrations

- **Elementor**: Automatically detected when active; preserves unsupported widgets and verifies `_elementor_data` writes and rollbacks.
- **WooCommerce**: Automatically detected; validates product inputs strictly and requires `edit_post` on the target product before modifying descriptions or prices.
- **SEO**: Automatically selects Yoast SEO, Rank Math SEO, or native WPilot SEO metadata, capturing exact meta keys for verified rollback.

## Prebuilt Frontend Assets

The installable ZIP includes compiled `assets/build/admin.js` and `assets/build/admin.css` bundles targeting WordPress Core `wp-element`, so Node.js is not required on your WordPress server. Contributors modifying `assets/src/` can rebuild with `npm install && npm run build` inside `wpilot-ai/`.
