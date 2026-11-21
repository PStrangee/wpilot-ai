import type { ChatMessage } from './types';

export const MAX_CLIENT_MESSAGE_CHARS = 8000;
export const MAX_HISTORY_MESSAGES = 12;

export function validateChatPrompt(raw: string): { valid: boolean; trimmed: string; error?: string } {
  const trimmed = raw.trim();
  if (!trimmed) {
    return { valid: false, trimmed: '', error: 'Please enter an instruction before sending.' };
  }
  if (trimmed.length > MAX_CLIENT_MESSAGE_CHARS) {
    return {
      valid: false,
      trimmed,
      error: `Message exceeds the ${MAX_CLIENT_MESSAGE_CHARS}-character limit.`,
    };
  }
  return { valid: true, trimmed };
}

/**
 * Strips non-allowlisted roles and trims history to the server window before POST /chat.
 */
export function buildChatHistoryPayload(
  messages: ChatMessage[],
  maxMessages: number = MAX_HISTORY_MESSAGES
): Array<{ role: 'user' | 'assistant'; content: string }> {
  return messages
    .filter((m): m is ChatMessage => (m.role === 'user' || m.role === 'assistant') && m.content.trim().length > 0)
    .slice(-Math.max(1, maxMessages))
    .map((m) => ({
      role: m.role,
      content: m.content.trim().slice(0, MAX_CLIENT_MESSAGE_CHARS),
    }));
}

export function resolveSafeModel(selected: string, allowedModels: string[], fallback = 'gpt-4.1'): string {
  const clean = selected.trim();
  if (allowedModels.includes(clean)) {
    return clean;
  }
  return allowedModels[0] ?? fallback;
}

export function extractRestErrorMessage(payload: unknown, fallbackStatus: number): string {
  if (payload && typeof payload === 'object') {
    const record = payload as Record<string, unknown>;
    if (typeof record.message === 'string' && record.message.trim().length > 0) {
      return record.message.trim();
    }
    if (typeof record.error === 'string' && record.error.trim().length > 0) {
      return record.error.trim();
    }
  }
  return `REST API request failed (HTTP ${fallbackStatus}).`;
}

export function formatRiskLabel(risk: string): string {
  if (risk === 'high') return 'High Risk';
  if (risk === 'medium') return 'Medium Risk';
  return 'Low Risk';
}
