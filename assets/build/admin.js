/**
 * WPilot AI — Self-Contained WordPress Admin Interface Bundle.
 *
 * Mounts into `#wpilot-ai-root` inside WordPress Admin and communicates securely
 * with `/wp-json/wpilot/v1/*` using the localized `wp_rest` nonce.
 * Never receives or stores plaintext API keys in browser storage.
 */
(function () {
  'use strict';

  var boot = window.wpilotAiBoot || {};
  var restUrl = boot.restUrl || '/wp-json/wpilot/v1/';
  var nonce = boot.nonce || '';
  var root = document.getElementById('wpilot-ai-root');
  if (!root) return;

  var state = {
    view: 'chat',
    dark: false,
    rtl: Boolean(boot.isRtl),
    settings: boot.settings || { model: 'gpt-4.1', has_api_key: false, max_requests_per_hour: 60, require_approval_med: true },
    messages: [
      {
        role: 'assistant',
        content: 'WPilot AI is active on ' + (boot.siteName || 'your WordPress site') + ' (WordPress ' + (boot.wpVersion || '') + '). Configure your server-side OpenAI API key in Settings or start inspecting pages, plugins, Elementor, WooCommerce, and SEO.',
        tools: [],
        tasks: [],
      },
    ],
    conversationId: 0,
    sending: false,
    siteData: null,
    tasks: [],
    backups: [],
    auditLogs: [],
    notice: '',
  };

  function api(path, options) {
    var opts = options || {};
    var headers = Object.assign(
      {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
      },
      opts.headers || {}
    );
    return fetch(restUrl.replace(/\/$/, '') + path, Object.assign({}, opts, { headers: headers })).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          throw new Error((data && (data.message || data.error)) || 'REST API error ' + res.status);
        }
        return data;
      });
    });
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function loadSiteOverview() {
    api('/site-overview').then(function (data) {
      state.siteData = data;
      render();
    }).catch(function (err) {
      state.notice = err.message;
      render();
    });
  }

  function loadTasksAndLogs() {
    Promise.all([
      api('/tasks').catch(function () { return { tasks: [] }; }),
      api('/backups').catch(function () { return { backups: [] }; }),
      api('/audit-logs').catch(function () { return { logs: [] }; }),
    ]).then(function (results) {
      state.tasks = results[0].tasks || [];
      state.backups = results[1].backups || [];
      state.auditLogs = results[2].logs || [];
      render();
    });
  }

  function sendChat(promptText) {
    if (!promptText.trim() || state.sending) return;
    state.sending = true;
    state.notice = '';
    state.messages.push({ role: 'user', content: promptText.trim() });
    render();

    var payloadMessages = state.messages
      .filter(function (m) { return m.role === 'user' || m.role === 'assistant'; })
      .slice(-10)
      .map(function (m) { return { role: m.role, content: m.content }; });

    api('/chat', {
      method: 'POST',
      body: JSON.stringify({
        conversation_id: state.conversationId,
        model: state.settings.model,
        messages: payloadMessages,
      }),
    })
      .then(function (res) {
        state.messages.push({
          role: 'assistant',
          content: res.text || 'Done.',
          tools: res.executed_tools || [],
          tasks: res.pending_tasks || [],
        });
      })
      .catch(function (err) {
        state.notice = err.message;
        state.messages.push({ role: 'assistant', content: 'Error: ' + err.message });
      })
      .finally(function () {
        state.sending = false;
        render();
      });
  }

  function approveTask(taskId) {
    api('/tasks/' + taskId + '/approve', { method: 'POST' })
      .then(function (res) {
        state.notice = 'Task #' + taskId + ' ' + res.status + ' (verified: ' + String(res.verified) + ').';
        loadTasksAndLogs();
      })
      .catch(function (err) {
        state.notice = err.message;
        render();
      });
  }

  function cancelTask(taskId) {
    api('/tasks/' + taskId + '/cancel', { method: 'POST' })
      .then(function () {
        state.notice = 'Task #' + taskId + ' cancelled.';
        loadTasksAndLogs();
      })
      .catch(function (err) {
        state.notice = err.message;
        render();
      });
  }

  function rollbackBackup(backupId) {
    api('/backups/' + backupId + '/rollback', { method: 'POST' })
      .then(function () {
        state.notice = 'Backup #' + backupId + ' restored successfully.';
        loadTasksAndLogs();
      })
      .catch(function (err) {
        state.notice = err.message;
        render();
      });
  }

  function renderMainContent() {
    if (state.view === 'chat') {
      var msgsHtml = state.messages
        .map(function (m) {
          var cls = m.role === 'user' ? 'wpilot-msg wpilot-msg-user' : 'wpilot-msg wpilot-msg-assistant';
          var toolsHtml = '';
          if (m.tools && m.tools.length) {
            toolsHtml = '<div style="margin-bottom:8px;font-size:12px;color:var(--wpilot-muted);">' +
              m.tools.map(function (t) { return 'Tool: <code>' + escapeHtml(t.tool_name) + '</code> (' + escapeHtml(t.risk_level) + ')'; }).join(' &middot; ') +
              '</div>';
          }
          var pendingHtml = '';
          if (m.tasks && m.tasks.length) {
            pendingHtml = m.tasks.map(function (tk) {
              return '<div class="wpilot-diff" style="margin-top:10px;">' +
                '<strong>Approval Required:</strong> ' + escapeHtml(tk.tool_name) + ' (' + escapeHtml(tk.risk_level) + ')<br/>' +
                '<pre style="margin:6px 0;">' + escapeHtml(JSON.stringify(tk.arguments, null, 2)) + '</pre>' +
                '<button type="button" class="wpilot-btn-primary" data-approve="' + tk.task_id + '">Approve &amp; Execute</button> ' +
                '<button type="button" class="wpilot-btn-secondary" style="flex:0;" data-cancel="' + tk.task_id + '">Reject</button>' +
                '</div>';
            }).join('');
          }
          return '<div class="' + cls + '">' + toolsHtml + escapeHtml(m.content) + pendingHtml + '</div>';
        })
        .join('');

      return '<div class="wpilot-messages">' + msgsHtml + '</div>' +
        '<form id="wpilot-chat-form" class="wpilot-composer">' +
        '<textarea id="wpilot-prompt" placeholder="Ask WPilot AI to inspect pages, analyze SEO, edit Elementor widgets, or audit plugins..."></textarea>' +
        '<button type="submit" class="wpilot-btn-primary"' + (state.sending ? ' disabled' : '') + '>' + (state.sending ? 'Running...' : 'Send') + '</button>' +
        '</form>';
    }

    if (state.view === 'site') {
      if (!state.siteData) {
        return '<div class="wpilot-card"><p>Loading live WordPress site intelligence...</p></div>';
      }
      var pagesRows = (state.siteData.pages || []).map(function (p) {
        return '<tr><td>#' + p.id + '</td><td>' + escapeHtml(p.title) + '</td><td>' + escapeHtml(p.status) + '</td><td>' + (p.is_elementor ? 'Elementor' : 'Block/Classic') + '</td></tr>';
      }).join('');
      var pluginRows = (state.siteData.plugins || []).map(function (pl) {
        return '<tr><td>' + escapeHtml(pl.name) + '</td><td>' + escapeHtml(pl.version) + '</td><td>' + (pl.active ? 'Active' : 'Inactive') + '</td></tr>';
      }).join('');

      return '<div class="wpilot-card"><h3>Pages (' + (state.siteData.pages || []).length + ')</h3>' +
        '<table class="wpilot-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Builder</th></tr></thead><tbody>' + pagesRows + '</tbody></table></div>' +
        '<div class="wpilot-card"><h3>Plugins (' + (state.siteData.plugins || []).length + ')</h3>' +
        '<table class="wpilot-table"><thead><tr><th>Name</th><th>Version</th><th>State</th></tr></thead><tbody>' + pluginRows + '</tbody></table></div>';
    }

    if (state.view === 'tasks') {
      var taskRows = state.tasks.map(function (tk) {
        var actions = tk.status === 'awaiting_approval'
          ? '<button type="button" class="wpilot-btn-primary" data-approve="' + tk.id + '">Approve</button> <button type="button" class="wpilot-btn-secondary" data-cancel="' + tk.id + '">Reject</button>'
          : escapeHtml(tk.status);
        return '<tr><td>#' + tk.id + '</td><td><code>' + escapeHtml(tk.tool_name) + '</code></td><td>' + escapeHtml(tk.risk_level) + '</td><td>' + actions + '</td></tr>';
      }).join('') || '<tr><td colspan="4">No queued tasks yet.</td></tr>';

      var backupRows = state.backups.map(function (b) {
        var btn = b.restored_at
          ? 'Restored'
          : '<button type="button" class="wpilot-btn-secondary" data-rollback="' + b.id + '">Rollback</button>';
        return '<tr><td>#' + b.id + '</td><td>' + escapeHtml(b.resource_type) + ' #' + escapeHtml(b.resource_id) + '</td><td><code>' + escapeHtml(b.tool_name) + '</code></td><td>' + btn + '</td></tr>';
      }).join('') || '<tr><td colspan="4">No backup snapshots yet.</td></tr>';

      var logRows = state.auditLogs.map(function (l) {
        return '<tr><td>' + escapeHtml(l.created_at) + '</td><td><code>' + escapeHtml(l.action) + '</code></td><td>' + escapeHtml(l.risk_level) + ' / ' + escapeHtml(l.status) + '</td><td>' + escapeHtml(l.summary) + '</td></tr>';
      }).join('') || '<tr><td colspan="4">No audit entries yet.</td></tr>';

      return '<div class="wpilot-card"><h3>Tasks &amp; Approvals</h3><table class="wpilot-table"><thead><tr><th>ID</th><th>Tool</th><th>Risk</th><th>Status / Action</th></tr></thead><tbody>' + taskRows + '</tbody></table></div>' +
        '<div class="wpilot-card"><h3>Backup Snapshots</h3><table class="wpilot-table"><thead><tr><th>ID</th><th>Resource</th><th>Tool</th><th>Action</th></tr></thead><tbody>' + backupRows + '</tbody></table></div>' +
        '<div class="wpilot-card"><h3>Audit Log</h3><table class="wpilot-table"><thead><tr><th>Time</th><th>Action</th><th>Risk / Status</th><th>Summary</th></tr></thead><tbody>' + logRows + '</tbody></table></div>';
    }

    // Settings View
    return '<form id="wpilot-settings-form" class="wpilot-card">' +
      '<h3>OpenAI Responses API &amp; Security Settings</h3>' +
      '<p style="color:var(--wpilot-muted);font-size:12.5px;">API key is encrypted server-side with libsodium/AES-256-GCM and never exposed to browsers. Status: <strong>' +
      (state.settings.has_api_key ? 'Configured (' + escapeHtml(state.settings.key_source || 'encrypted') + ')' : 'Not Configured') +
      '</strong>. Note: OpenAI API uses separate API billing credits.</p>' +
      '<div class="wpilot-grid-2">' +
      '<div><label>OpenAI API Key (leave blank to keep current)<br/><input type="password" id="wpilot-api-key" class="wpilot-input" autocomplete="new-password" placeholder="sk-..." /></label></div>' +
      '<div><label>Model<br/><select id="wpilot-model" class="wpilot-select">' +
      '<option value="gpt-4.1"' + (state.settings.model === 'gpt-4.1' ? ' selected' : '') + '>gpt-4.1 (Flagship Tool Calling)</option>' +
      '<option value="gpt-4.1-mini"' + (state.settings.model === 'gpt-4.1-mini' ? ' selected' : '') + '>gpt-4.1-mini (Fast &amp; Balanced)</option>' +
      '<option value="o4-mini"' + (state.settings.model === 'o4-mini' ? ' selected' : '') + '>o4-mini (Reasoning)</option>' +
      '</select></label></div>' +
      '<div><label>Max Requests / Hour<br/><input type="number" id="wpilot-rate" class="wpilot-input" value="' + Number(state.settings.max_requests_per_hour || 60) + '" min="5" max="500" /></label></div>' +
      '<div><label>Max Tokens / Request<br/><input type="number" id="wpilot-tokens" class="wpilot-input" value="' + Number(state.settings.max_tokens_per_request || 4096) + '" min="512" max="16384" /></label></div>' +
      '</div>' +
      '<p style="margin-top:14px;"><label><input type="checkbox" id="wpilot-approve-med"' + (state.settings.require_approval_med ? ' checked' : '') + ' /> Require explicit approval before Medium-Risk mutations (High-Risk always requires approval)</label></p>' +
      '<button type="submit" class="wpilot-btn-primary">Save Settings</button>' +
      '</form>';
  }

  function render() {
    var shellClass = 'wpilot-shell' + (state.dark ? ' wpilot-dark' : '');
    root.innerHTML =
      '<div class="' + shellClass + '" dir="' + (state.rtl ? 'rtl' : 'ltr') + '">' +
      '<aside class="wpilot-sidebar">' +
      '<div class="wpilot-brand"><h2>WPilot AI</h2><p>' + escapeHtml(boot.siteName || 'WordPress') + '</p></div>' +
      '<nav class="wpilot-nav">' +
      '<button type="button" data-nav="chat" class="' + (state.view === 'chat' ? 'active' : '') + '">Agent Workspace</button>' +
      '<button type="button" data-nav="site" class="' + (state.view === 'site' ? 'active' : '') + '">Site Inspector</button>' +
      '<button type="button" data-nav="tasks" class="' + (state.view === 'tasks' ? 'active' : '') + '">Tasks &amp; Audit</button>' +
      '<button type="button" data-nav="settings" class="' + (state.view === 'settings' ? 'active' : '') + '">Settings</button>' +
      '</nav>' +
      '<div class="wpilot-sidebar-footer">' +
      '<button type="button" class="wpilot-btn-secondary" id="wpilot-toggle-dark">' + (state.dark ? 'Light' : 'Dark') + '</button>' +
      '<button type="button" class="wpilot-btn-secondary" id="wpilot-toggle-rtl">' + (state.rtl ? 'LTR' : 'RTL') + '</button>' +
      '</div></aside>' +
      '<section class="wpilot-main">' +
      '<header class="wpilot-topbar"><strong>WPilot AI &middot; ' + escapeHtml(state.view.toUpperCase()) + '</strong><span style="font-size:12px;color:var(--wpilot-muted);">Model: ' + escapeHtml(state.settings.model) + '</span></header>' +
      (state.notice ? '<div style="padding:10px 22px;background:rgba(37,99,235,0.1);font-size:12.5px;">' + escapeHtml(state.notice) + '</div>' : '') +
      '<div class="wpilot-content">' + renderMainContent() + '</div>' +
      '</section></div>';

    root.querySelectorAll('[data-nav]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.view = btn.getAttribute('data-nav');
        if (state.view === 'site') loadSiteOverview();
        if (state.view === 'tasks') loadTasksAndLogs();
        render();
      });
    });

    var darkBtn = document.getElementById('wpilot-toggle-dark');
    if (darkBtn) darkBtn.addEventListener('click', function () { state.dark = !state.dark; render(); });

    var rtlBtn = document.getElementById('wpilot-toggle-rtl');
    if (rtlBtn) rtlBtn.addEventListener('click', function () { state.rtl = !state.rtl; render(); });

    var chatForm = document.getElementById('wpilot-chat-form');
    if (chatForm) {
      chatForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var el = document.getElementById('wpilot-prompt');
        if (el) sendChat(el.value);
      });
    }

    var settingsForm = document.getElementById('wpilot-settings-form');
    if (settingsForm) {
      settingsForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = {
          model: document.getElementById('wpilot-model').value,
          max_requests_per_hour: Number(document.getElementById('wpilot-rate').value),
          max_tokens_per_request: Number(document.getElementById('wpilot-tokens').value),
          require_approval_med: document.getElementById('wpilot-approve-med').checked,
        };
        var keyVal = document.getElementById('wpilot-api-key').value.trim();
        if (keyVal) body.openai_api_key = keyVal;

        api('/settings', { method: 'POST', body: JSON.stringify(body) })
          .then(function (updated) {
            state.settings = updated;
            state.notice = 'WPilot AI settings saved and encrypted on server.';
            render();
          })
          .catch(function (err) {
            state.notice = err.message;
            render();
          });
      });
    }

    root.querySelectorAll('[data-approve]').forEach(function (btn) {
      btn.addEventListener('click', function () { approveTask(Number(btn.getAttribute('data-approve'))); });
    });
    root.querySelectorAll('[data-cancel]').forEach(function (btn) {
      btn.addEventListener('click', function () { cancelTask(Number(btn.getAttribute('data-cancel'))); });
    });
    root.querySelectorAll('[data-rollback]').forEach(function (btn) {
      btn.addEventListener('click', function () { rollbackBackup(Number(btn.getAttribute('data-rollback'))); });
    });
  }

  render();
})();
