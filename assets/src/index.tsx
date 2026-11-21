import React from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import type { WPilotBootConfig } from './types';

function bootstrap(): void {
  const container = document.getElementById('wpilot-ai-root');
  if (!container) {
    return;
  }

  const raw = window.wpilotAiBoot ?? {};
  const boot: WPilotBootConfig = {
    restUrl: raw.restUrl ?? '/wp-json/wpilot/v1/',
    nonce: raw.nonce ?? '',
    locale: raw.locale ?? 'en_US',
    isRtl: Boolean(raw.isRtl),
    isAdmin: Boolean(raw.isAdmin),
    siteName: raw.siteName ?? 'WordPress Site',
    wpVersion: raw.wpVersion ?? '',
    settings: {
      provider: raw.settings?.provider ?? 'openai',
      model: raw.settings?.model ?? 'gpt-4.1',
      allowed_models: raw.settings?.allowed_models ?? ['gpt-4.1', 'gpt-4.1-mini', 'o4-mini'],
      max_requests_per_hour: raw.settings?.max_requests_per_hour ?? 60,
      max_tokens_per_request: raw.settings?.max_tokens_per_request ?? 4096,
      request_timeout: raw.settings?.request_timeout ?? 45,
      require_approval_med: raw.settings?.require_approval_med ?? true,
      require_approval_high: true,
      allowed_roles: raw.settings?.allowed_roles ?? ['administrator'],
      has_api_key: Boolean(raw.settings?.has_api_key),
      key_source: raw.settings?.key_source ?? 'unconfigured',
    },
  };

  createRoot(container).render(<App boot={boot} />);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap);
} else {
  bootstrap();
}
