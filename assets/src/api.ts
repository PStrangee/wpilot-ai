import type {
  AuditLogRow,
  BackupRow,
  PublicSettings,
  SiteOverviewData,
  TaskRow,
} from './types';
import { extractRestErrorMessage } from './utils';

export interface RestClient {
  getSiteOverview: () => Promise<SiteOverviewData>;
  createConversation: (title: string, model: string) => Promise<{ id: number; title: string; model: string }>;
  sendChat: (payload: {
    conversation_id: number;
    model: string;
    messages: Array<{ role: 'user' | 'assistant'; content: string }>;
  }) => Promise<{
    response_id: string;
    text: string;
    executed_tools: Array<{
      tool_name: string;
      risk_level: 'low' | 'medium' | 'high';
      status?: string;
      result?: Record<string, unknown>;
      error?: string;
    }>;
    pending_tasks: Array<{
      task_id: number;
      tool_name: string;
      risk_level: 'low' | 'medium' | 'high';
      status: string;
      arguments: Record<string, unknown>;
    }>;
  }>;
  listTasks: () => Promise<{ tasks: TaskRow[] }>;
  approveTask: (taskId: number) => Promise<{ task_id: number; status: string; verified: boolean }>;
  cancelTask: (taskId: number) => Promise<{ task_id: number; status: string }>;
  listBackups: () => Promise<{ backups: BackupRow[] }>;
  rollbackBackup: (backupId: number) => Promise<{ restored: boolean; verified: boolean; backup_id: number }>;
  listAuditLogs: () => Promise<{ logs: AuditLogRow[] }>;
  updateSettings: (patch: Record<string, unknown>) => Promise<PublicSettings>;
}

export function createRestClient(restUrl: string, nonce: string): RestClient {
  const base = restUrl.replace(/\/$/, '');

  async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const res = await fetch(`${base}${path}`, {
      ...init,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
        ...(init.headers ?? {}),
      },
    });

    let data: unknown = null;
    try {
      data = await res.json();
    } catch {
      data = null;
    }

    if (!res.ok) {
      throw new Error(extractRestErrorMessage(data, res.status));
    }

    return data as T;
  }

  return {
    getSiteOverview: () => request<SiteOverviewData>('/site-overview'),
    createConversation: (title, model) =>
      request('/conversations', {
        method: 'POST',
        body: JSON.stringify({ title, model }),
      }),
    sendChat: (payload) =>
      request('/chat', {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    listTasks: () => request('/tasks'),
    approveTask: (taskId) => request(`/tasks/${taskId}/approve`, { method: 'POST' }),
    cancelTask: (taskId) => request(`/tasks/${taskId}/cancel`, { method: 'POST' }),
    listBackups: () => request('/backups'),
    rollbackBackup: (backupId) => request(`/backups/${backupId}/rollback`, { method: 'POST' }),
    listAuditLogs: () => request('/audit-logs'),
    updateSettings: (patch) =>
      request('/settings', {
        method: 'POST',
        body: JSON.stringify(patch),
      }),
  };
}
