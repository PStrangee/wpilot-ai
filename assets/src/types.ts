export type NavTab = 'chat' | 'site' | 'tasks' | 'settings';

export interface PublicSettings {
  provider: string;
  model: string;
  allowed_models: string[];
  max_requests_per_hour: number;
  max_tokens_per_request: number;
  request_timeout: number;
  require_approval_med: boolean;
  require_approval_high: boolean;
  allowed_roles: string[];
  has_api_key: boolean;
  key_source: 'wp-config.php' | 'encrypted_option' | 'unconfigured';
}

export interface WPilotBootConfig {
  restUrl: string;
  nonce: string;
  locale: string;
  isRtl: boolean;
  isAdmin: boolean;
  siteName: string;
  wpVersion: string;
  settings: PublicSettings;
}

export interface ExecutedTool {
  tool_name: string;
  risk_level: 'low' | 'medium' | 'high';
  status?: string;
  result?: Record<string, unknown>;
  error?: string;
}

export interface PendingTask {
  task_id: number;
  tool_name: string;
  risk_level: 'low' | 'medium' | 'high';
  status: string;
  arguments: Record<string, unknown>;
}

export interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
  tools?: ExecutedTool[];
  tasks?: PendingTask[];
}

export interface TaskRow {
  id: number;
  user_id: number;
  tool_name: string;
  risk_level: 'low' | 'medium' | 'high';
  status: string;
  arguments: string;
  created_at?: string;
}

export interface BackupRow {
  id: number;
  user_id: number;
  resource_type: string;
  resource_id: string;
  tool_name: string;
  restored_at: string | null;
  created_at?: string;
}

export interface AuditLogRow {
  id: number;
  user_id: number;
  action: string;
  risk_level: string;
  status: string;
  summary: string;
  created_at?: string;
}

export interface SiteOverviewData {
  site: Record<string, unknown>;
  pages: Array<{ id: number; title: string; slug: string; status: string }>;
  plugins: Array<{ name: string; version: string; active: boolean }>;
  media: Array<{ id: number; filename: string; alt: string }>;
  products: Array<{ id: number; name: string; sku: string; price: string; has_short_desc: boolean }>;
}

declare global {
  interface Window {
    wpilotAiBoot?: Partial<WPilotBootConfig>;
  }
}
