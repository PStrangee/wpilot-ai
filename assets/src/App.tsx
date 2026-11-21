import React, { useEffect, useMemo, useState } from 'react';
import { createRestClient } from './api';
import type {
  AuditLogRow,
  BackupRow,
  ChatMessage,
  NavTab,
  PublicSettings,
  SiteOverviewData,
  TaskRow,
  WPilotBootConfig,
} from './types';
import {
  buildChatHistoryPayload,
  formatRiskLabel,
  resolveSafeModel,
  validateChatPrompt,
} from './utils';

interface AppProps {
  boot: WPilotBootConfig;
}

export const App: React.FC<AppProps> = ({ boot }) => {
  const client = useMemo(
    () => createRestClient(boot.restUrl, boot.nonce),
    [boot.restUrl, boot.nonce]
  );

  const [view, setView] = useState<NavTab>('chat');
  const [darkMode, setDarkMode] = useState<boolean>(false);
  const [isRtl, setIsRtl] = useState<boolean>(Boolean(boot.isRtl));
  const [settings, setSettings] = useState<PublicSettings>(boot.settings);
  const [conversationId, setConversationId] = useState<number>(0);
  const [messages, setMessages] = useState<ChatMessage[]>([
    {
      role: 'assistant',
      content: `WPilot AI is active on ${boot.siteName} (WordPress ${boot.wpVersion}). Configure your server-side OpenAI API key in Settings or ask me to inspect pages, Elementor widgets, WooCommerce products, and SEO metadata.`,
      tools: [],
      tasks: [],
    },
  ]);
  const [promptInput, setPromptInput] = useState<string>('');
  const [sending, setSending] = useState<boolean>(false);
  const [busyTaskId, setBusyTaskId] = useState<number | null>(null);
  const [notice, setNotice] = useState<string>('');
  const [siteData, setSiteData] = useState<SiteOverviewData | null>(null);
  const [tasks, setTasks] = useState<TaskRow[]>([]);
  const [backups, setBackups] = useState<BackupRow[]>([]);
  const [auditLogs, setAuditLogs] = useState<AuditLogRow[]>([]);

  // Settings form state (plaintext key is write-only, never returned from server)
  const [selectedModel, setSelectedModel] = useState<string>(boot.settings.model);
  const [rateLimit, setRateLimit] = useState<number>(boot.settings.max_requests_per_hour);
  const [requireMedApproval, setRequireMedApproval] = useState<boolean>(
    boot.settings.require_approval_med
  );
  const [apiKeyInput, setApiKeyInput] = useState<string>('');

  const allowedModels = settings.allowed_models?.length
    ? settings.allowed_models
    : ['gpt-4.1', 'gpt-4.1-mini', 'o4-mini'];

  const refreshSiteOverview = async () => {
    try {
      const data = await client.getSiteOverview();
      setSiteData(data);
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Unable to load site overview.');
    }
  };

  const refreshTasksAndLogs = async () => {
    try {
      const [tasksRes, backupsRes, logsRes] = await Promise.all([
        client.listTasks().catch(() => ({ tasks: [] })),
        boot.isAdmin ? client.listBackups().catch(() => ({ backups: [] })) : Promise.resolve({ backups: [] }),
        client.listAuditLogs().catch(() => ({ logs: [] })),
      ]);
      setTasks(tasksRes.tasks ?? []);
      setBackups(backupsRes.backups ?? []);
      setAuditLogs(logsRes.logs ?? []);
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Unable to load activity logs.');
    }
  };

  useEffect(() => {
    if (view === 'site' && !siteData) {
      void refreshSiteOverview();
    } else if (view === 'tasks') {
      void refreshTasksAndLogs();
    }
  }, [view]);

  const handleSendChat = async (e: React.FormEvent) => {
    e.preventDefault();
    if (sending) return;

    const check = validateChatPrompt(promptInput);
    if (!check.valid) {
      setNotice(check.error ?? 'Invalid message.');
      return;
    }

    setSending(true);
    setNotice('');
    setPromptInput('');

    const nextMessages: ChatMessage[] = [...messages, { role: 'user', content: check.trimmed }];
    setMessages(nextMessages);

    try {
      const safeModel = resolveSafeModel(settings.model, allowedModels);
      let activeConvId = conversationId;
      if (activeConvId <= 0) {
        const created = await client.createConversation(check.trimmed.slice(0, 60), safeModel);
        activeConvId = created.id;
        setConversationId(activeConvId);
      }

      const res = await client.sendChat({
        conversation_id: activeConvId,
        model: safeModel,
        messages: buildChatHistoryPayload(nextMessages),
      });

      setMessages((prev) => [
        ...prev,
        {
          role: 'assistant',
          content: res.text || 'Completed.',
          tools: res.executed_tools ?? [],
          tasks: res.pending_tasks ?? [],
        },
      ]);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Request failed.';
      setNotice(msg);
      setMessages((prev) => [...prev, { role: 'assistant', content: `Error: ${msg}` }]);
    } finally {
      setSending(false);
    }
  };

  const handleApproveTask = async (taskId: number) => {
    if (busyTaskId !== null) return;
    setBusyTaskId(taskId);
    setNotice('');
    try {
      const res = await client.approveTask(taskId);
      setNotice(
        res.verified
          ? `Task #${taskId} executed and verified (${res.status}).`
          : `Task #${taskId} completed with verification failure (${res.status}).`
      );
      await refreshTasksAndLogs();
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Approval failed.');
    } finally {
      setBusyTaskId(null);
    }
  };

  const handleCancelTask = async (taskId: number) => {
    if (busyTaskId !== null) return;
    setBusyTaskId(taskId);
    setNotice('');
    try {
      await client.cancelTask(taskId);
      setNotice(`Task #${taskId} cancelled.`);
      await refreshTasksAndLogs();
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Cancellation failed.');
    } finally {
      setBusyTaskId(null);
    }
  };

  const handleRollback = async (backupId: number) => {
    if (busyTaskId !== null) return;
    setBusyTaskId(backupId);
    setNotice('');
    try {
      await client.rollbackBackup(backupId);
      setNotice(`Backup #${backupId} restored and verified.`);
      await refreshTasksAndLogs();
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Rollback failed.');
    } finally {
      setBusyTaskId(null);
    }
  };

  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    setNotice('');
    try {
      const patch: Record<string, unknown> = {
        model: resolveSafeModel(selectedModel, allowedModels),
        max_requests_per_hour: rateLimit,
        require_approval_med: requireMedApproval,
      };
      if (apiKeyInput.trim()) {
        patch.openai_api_key = apiKeyInput.trim();
      }
      const updated = await client.updateSettings(patch);
      setSettings(updated);
      setSelectedModel(updated.model);
      setApiKeyInput('');
      setNotice('WPilot AI settings saved securely on the server.');
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to save settings.');
    }
  };

  return (
    <div className={`wpilot-app${darkMode ? ' wpilot-dark' : ''}`} dir={isRtl ? 'rtl' : 'ltr'}>
      <header className="wpilot-header">
        <div>
          <h1 className="wpilot-title">WPilot AI</h1>
          <p className="wpilot-subtitle">
            {boot.siteName} · {settings.model} ·{' '}
            {settings.has_api_key ? `API Key (${settings.key_source})` : 'API Key Not Configured'}
          </p>
        </div>
        <div className="wpilot-actions">
          <button
            type="button"
            className="wpilot-btn"
            onClick={() => setDarkMode((prev) => !prev)}
          >
            {darkMode ? 'Light' : 'Dark'}
          </button>
          <button
            type="button"
            className="wpilot-btn"
            onClick={() => setIsRtl((prev) => !prev)}
          >
            {isRtl ? 'LTR' : 'RTL'}
          </button>
        </div>
      </header>

      <nav className="wpilot-nav" aria-label="WPilot AI Sections">
        <button
          type="button"
          className={`wpilot-tab${view === 'chat' ? ' is-active' : ''}`}
          onClick={() => setView('chat')}
        >
          Agent Chat
        </button>
        <button
          type="button"
          className={`wpilot-tab${view === 'site' ? ' is-active' : ''}`}
          onClick={() => setView('site')}
        >
          Site Inspector
        </button>
        <button
          type="button"
          className={`wpilot-tab${view === 'tasks' ? ' is-active' : ''}`}
          onClick={() => setView('tasks')}
        >
          Tasks &amp; Audit
        </button>
        {boot.isAdmin && (
          <button
            type="button"
            className={`wpilot-tab${view === 'settings' ? ' is-active' : ''}`}
            onClick={() => setView('settings')}
          >
            Settings
          </button>
        )}
      </nav>

      {notice && (
        <div className="wpilot-notice" role="status">
          {notice}
        </div>
      )}

      {view === 'chat' && (
        <section className="wpilot-panel">
          <div className="wpilot-messages">
            {messages.map((msg, idx) => (
              <div
                key={idx}
                className={`wpilot-msg ${msg.role === 'user' ? 'wpilot-msg-user' : 'wpilot-msg-assistant'}`}
              >
                <div className="wpilot-msg-meta">
                  {msg.role === 'user' ? 'WordPress Admin' : 'WPilot AI'}
                </div>
                <div className="wpilot-msg-body">{msg.content}</div>

                {msg.tools && msg.tools.length > 0 && (
                  <div className="wpilot-tool-list" dir="ltr">
                    {msg.tools.map((tool, tIdx) => (
                      <div key={tIdx} className="wpilot-tool-item">
                        <code>{tool.tool_name}</code> — {formatRiskLabel(tool.risk_level)} ({tool.status ?? 'completed'})
                      </div>
                    ))}
                  </div>
                )}

                {msg.tasks && msg.tasks.length > 0 && (
                  <div className="wpilot-task-list">
                    {msg.tasks.map((task) => (
                      <div key={task.task_id} className="wpilot-task-card">
                        <div>
                          <strong>{task.tool_name}</strong> ({formatRiskLabel(task.risk_level)}) —{' '}
                          <span>{task.status}</span>
                        </div>
                        <pre dir="ltr" className="wpilot-code">
                          {JSON.stringify(task.arguments, null, 2)}
                        </pre>
                        <div className="wpilot-actions">
                          <button
                            type="button"
                            className="wpilot-btn wpilot-btn-primary"
                            disabled={busyTaskId === task.task_id}
                            onClick={() => void handleApproveTask(task.task_id)}
                          >
                            Approve &amp; Execute
                          </button>
                          <button
                            type="button"
                            className="wpilot-btn"
                            disabled={busyTaskId === task.task_id}
                            onClick={() => void handleCancelTask(task.task_id)}
                          >
                            Reject
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>

          <form className="wpilot-composer" onSubmit={(e) => void handleSendChat(e)}>
            <textarea
              className="wpilot-textarea"
              rows={2}
              value={promptInput}
              onChange={(e) => setPromptInput(e.target.value)}
              placeholder="Ask WPilot AI to inspect pages, audit SEO, refine Elementor widgets, or update WooCommerce copy..."
              disabled={sending}
            />
            <button
              type="submit"
              className="wpilot-btn wpilot-btn-primary"
              disabled={sending || !promptInput.trim()}
            >
              {sending ? 'Running...' : 'Send'}
            </button>
          </form>
        </section>
      )}

      {view === 'site' && (
        <section className="wpilot-panel">
          <div className="wpilot-panel-head">
            <h2>WordPress Site Intelligence</h2>
            <button type="button" className="wpilot-btn" onClick={() => void refreshSiteOverview()}>
              Refresh
            </button>
          </div>
          {!siteData ? (
            <p className="wpilot-muted">Loading site overview...</p>
          ) : (
            <div className="wpilot-grid">
              <div className="wpilot-card">
                <h3>Pages ({siteData.pages.length})</h3>
                <ul>
                  {siteData.pages.map((p) => (
                    <li key={p.id}>
                      #{p.id} — {p.title} ({p.status})
                    </li>
                  ))}
                </ul>
              </div>
              <div className="wpilot-card">
                <h3>Plugins ({siteData.plugins.length})</h3>
                <ul>
                  {siteData.plugins.map((pl, idx) => (
                    <li key={idx}>
                      {pl.name} v{pl.version} {pl.active ? '· Active' : '· Inactive'}
                    </li>
                  ))}
                </ul>
              </div>
              <div className="wpilot-card">
                <h3>WooCommerce Products ({siteData.products.length})</h3>
                <ul>
                  {siteData.products.map((pr) => (
                    <li key={pr.id}>
                      #{pr.id} — {pr.name} ({pr.sku || 'no SKU'})
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}
        </section>
      )}

      {view === 'tasks' && (
        <section className="wpilot-panel">
          <div className="wpilot-panel-head">
            <h2>Your Tasks, Backups &amp; Audit Logs</h2>
            <button type="button" className="wpilot-btn" onClick={() => void refreshTasksAndLogs()}>
              Refresh
            </button>
          </div>

          <h3>Your Queued &amp; Recent Tasks ({tasks.length})</h3>
          {tasks.length === 0 ? (
            <p className="wpilot-muted">No tasks recorded yet.</p>
          ) : (
            <div className="wpilot-stack">
              {tasks.map((tk) => (
                <div key={tk.id} className="wpilot-task-card">
                  <div>
                    <strong>#{tk.id} {tk.tool_name}</strong> · {formatRiskLabel(tk.risk_level)} · {tk.status}
                  </div>
                  {tk.status === 'awaiting_approval' && (
                    <div className="wpilot-actions">
                      <button
                        type="button"
                        className="wpilot-btn wpilot-btn-primary"
                        disabled={busyTaskId === tk.id}
                        onClick={() => void handleApproveTask(tk.id)}
                      >
                        Approve
                      </button>
                      <button
                        type="button"
                        className="wpilot-btn"
                        disabled={busyTaskId === tk.id}
                        onClick={() => void handleCancelTask(tk.id)}
                      >
                        Cancel
                      </button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}

          {boot.isAdmin && (
            <>
              <h3>Backups ({backups.length})</h3>
              {backups.length === 0 ? (
                <p className="wpilot-muted">No snapshots captured yet.</p>
              ) : (
                <div className="wpilot-stack">
                  {backups.map((bk) => (
                    <div key={bk.id} className="wpilot-task-card">
                      <div>
                        <strong>Backup #{bk.id}</strong> · {bk.resource_type} #{bk.resource_id} ({bk.tool_name})
                        {bk.restored_at ? ` · Restored ${bk.restored_at}` : ''}
                      </div>
                      <button
                        type="button"
                        className="wpilot-btn"
                        disabled={busyTaskId === bk.id}
                        onClick={() => void handleRollback(bk.id)}
                      >
                        Rollback
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}

          <h3>Audit Trail ({auditLogs.length})</h3>
          {auditLogs.length === 0 ? (
            <p className="wpilot-muted">No audit entries yet.</p>
          ) : (
            <div className="wpilot-stack">
              {auditLogs.map((log) => (
                <div key={log.id} className="wpilot-audit-row">
                  <code>{log.action}</code> · <span>{log.status}</span> — {log.summary}
                </div>
              ))}
            </div>
          )}
        </section>
      )}

      {view === 'settings' && boot.isAdmin && (
        <section className="wpilot-panel">
          <h2>WPilot AI Settings</h2>
          <p className="wpilot-muted">
            API keys are encrypted server-side using libsodium/AES-256-GCM or loaded via WPILOT_OPENAI_API_KEY in wp-config.php. OpenAI API usage requires API billing credits (separate from ChatGPT subscriptions).
          </p>
          <form className="wpilot-form" onSubmit={(e) => void handleSaveSettings(e)}>
            <label className="wpilot-field">
              <span>Allowed OpenAI Model</span>
              <select
                value={selectedModel}
                onChange={(e) => setSelectedModel(e.target.value)}
              >
                {allowedModels.map((m) => (
                  <option key={m} value={m}>
                    {m}
                  </option>
                ))}
              </select>
            </label>

            <label className="wpilot-field">
              <span>Hourly Request Limit per User</span>
              <input
                type="number"
                min={5}
                max={500}
                value={rateLimit}
                onChange={(e) => setRateLimit(Number(e.target.value))}
              />
            </label>

            <label className="wpilot-checkbox">
              <input
                type="checkbox"
                checked={requireMedApproval}
                onChange={(e) => setRequireMedApproval(e.target.checked)}
              />
              <span>Require explicit approval for medium-risk writes (high-risk always requires approval)</span>
            </label>

            <label className="wpilot-field">
              <span>Update Server-Side OpenAI API Key (write-only)</span>
              <input
                type="password"
                autoComplete="off"
                placeholder="sk-..."
                value={apiKeyInput}
                onChange={(e) => setApiKeyInput(e.target.value)}
              />
            </label>

            <button type="submit" className="wpilot-btn wpilot-btn-primary">
              Save Settings
            </button>
          </form>
        </section>
      )}
    </div>
  );
};
