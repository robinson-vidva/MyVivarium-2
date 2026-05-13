<?php
/**
 * Chatbot floating widget injector.
 *
 * Include this from header.php and/or footer.php (or any authenticated page).
 * Renders the floating chat button + panel only when:
 *   1. The user has an authenticated PHP session (user_id set).
 *   2. ai_settings.chatbot_enabled === '1'.
 *   3. ai_settings.groq_api_key is non-empty.
 *
 * Idempotent: a $GLOBALS flag prevents double-render when included from both
 * header.php and footer.php on the same request.
 *
 * Debug: when ?chatbot_debug=1 is on the URL AND the session user is admin,
 * an HTML comment is emitted with the three gating booleans so an admin can
 * see at a glance why the widget is or isn't rendering on any page.
 *
 * Self-contained: emits one <style> block, one widget markup block, and one
 * <script> block. No bundler, no frameworks. Bootstrap 5 is already loaded
 * by header.php; we don't rely on it.
 */

// Idempotency guard — safe to include from both header.php and footer.php.
if (!empty($GLOBALS['__chatbot_widget_rendered'])) return;

$__chat_authed  = isset($_SESSION['user_id']) && isset($_SESSION['username']);
$__chat_enabled = false;
$__chat_key_set = false;
$__chat_provider = 'groq';
$__chat_provider_label = 'Groq';
$__chat_model    = '';
if ($__chat_authed) {
    require_once __DIR__ . '/ai_settings.php';
    require_once __DIR__ . '/llm_provider.php';
    try {
        $__chat_enabled = ai_settings_get('chatbot_enabled') === '1';
        $__cfg          = llm_get_active_config();
        $__chat_key_set = $__cfg['api_key'] !== '';
        $__chat_provider       = $__cfg['provider'];
        $__chat_provider_label = llm_provider_label($__cfg['provider']);
        $__chat_model          = $__cfg['model'];
    } catch (Throwable $e) {
        // Swallow — debug comment below still reports authed/enabled/key_set.
    }
}
$__chat_show = $__chat_authed && $__chat_enabled && $__chat_key_set;

// Admin-only debug comment.
if (isset($_GET['chatbot_debug']) && $_GET['chatbot_debug'] === '1'
    && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    echo "\n<!-- chatbot_widget: authed=" . ($__chat_authed ? '1' : '0')
       . ", enabled=" . ($__chat_enabled ? '1' : '0')
       . ", key_set=" . ($__chat_key_set ? '1' : '0')
       . ", will_render=" . ($__chat_show ? '1' : '0') . " -->\n";
}

if (!$__chat_show) return;

// Mark as rendered BEFORE emitting markup so a re-include in footer.php is a no-op.
$GLOBALS['__chatbot_widget_rendered'] = true;
?>
<style>
  /* Floating chat widget — bottom-right, above everything else. */
  #mv-chat-fab {
    position: fixed; right: 20px; bottom: 20px;
    width: 56px; height: 56px; border-radius: 50%;
    background: #0d6efd; color: #fff; border: none;
    box-shadow: 0 4px 14px rgba(0,0,0,0.2);
    cursor: pointer; z-index: 1080;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; line-height: 1;
  }
  #mv-chat-fab:hover { background: #0b5ed7; }
  #mv-chat-panel {
    position: fixed; right: 20px; bottom: 90px;
    width: 380px; height: 560px;
    background: #fff; border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
    display: none; flex-direction: column;
    z-index: 1080; overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }
  #mv-chat-panel.open { display: flex; }
  #mv-chat-header {
    background: #0d6efd; color: #fff; padding: 10px 14px;
    display: flex; align-items: center; gap: 8px;
  }
  #mv-chat-header .mv-chat-title { flex: 1; font-weight: 600; font-size: 14px; }
  #mv-chat-header .mv-chat-title .mv-chat-subtitle { display: block; font-weight: 400; font-size: 11px; color: rgba(255,255,255,0.8); line-height: 1.2; margin-top: 1px; }
  #mv-chat-header button {
    background: transparent; border: none; color: #fff; cursor: pointer;
    font-size: 18px; padding: 2px 6px; line-height: 1;
  }
  #mv-chat-header button:hover { background: rgba(255,255,255,0.15); border-radius: 4px; }
  #mv-chat-history-dropdown {
    position: absolute; top: 44px; right: 12px;
    background: #fff; color: #212529; border: 1px solid #ddd;
    border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    width: 280px; max-height: 320px; overflow-y: auto;
    display: none; z-index: 5;
  }
  #mv-chat-history-dropdown.open { display: block; }
  #mv-chat-history-dropdown .mv-hist-item {
    padding: 8px 12px; cursor: pointer; font-size: 13px;
    border-bottom: 1px solid #f0f0f0; color: #212529;
  }
  #mv-chat-history-dropdown .mv-hist-item:hover { background: #f8f9fa; }
  #mv-chat-history-dropdown .mv-hist-empty { padding: 16px; text-align: center; color: #6c757d; font-size: 13px; }
  #mv-chat-messages {
    flex: 1; overflow-y: auto; padding: 12px;
    background: #f8f9fa; display: flex; flex-direction: column; gap: 8px;
  }
  .mv-msg { max-width: 85%; padding: 8px 12px; border-radius: 10px; font-size: 14px; line-height: 1.4; word-wrap: break-word; white-space: pre-wrap; }
  .mv-msg-user { align-self: flex-end; background: #0d6efd; color: #fff; }
  .mv-msg-assistant { align-self: flex-start; background: #fff; color: #212529; border: 1px solid #e0e0e0; }
  .mv-msg-event { align-self: center; background: #fff3cd; color: #664d03; font-size: 12px; padding: 4px 10px; border-radius: 6px; max-width: 95%; }
  .mv-tool-card {
    align-self: flex-start; background: #e9ecef; border: 1px solid #ced4da;
    border-radius: 8px; padding: 6px 10px; font-size: 12px; max-width: 95%; cursor: pointer;
  }
  .mv-tool-card.expanded .mv-tool-body { display: block; }
  .mv-tool-card .mv-tool-body { display: none; margin-top: 6px; font-family: ui-monospace, monospace; white-space: pre-wrap; max-height: 200px; overflow-y: auto; background: #f1f3f5; padding: 6px; border-radius: 4px; }
  .mv-tool-card .mv-tool-head { font-weight: 600; }
  .mv-confirm-card {
    align-self: stretch; background: #fff3cd; border: 1px solid #ffe69c;
    border-radius: 8px; padding: 10px 12px; font-size: 13px; color: #664d03;
  }
  .mv-confirm-card .mv-confirm-summary { font-weight: 600; margin-bottom: 6px; }
  .mv-confirm-card .mv-confirm-diff { font-family: ui-monospace, monospace; font-size: 11px; background: #fff; border: 1px solid #ffe69c; border-radius: 4px; padding: 6px; max-height: 140px; overflow-y: auto; margin-bottom: 8px; white-space: pre-wrap; }
  .mv-confirm-card .mv-confirm-btns { display: flex; gap: 6px; }
  .mv-confirm-card button { padding: 4px 12px; border-radius: 4px; border: none; cursor: pointer; font-size: 13px; }
  .mv-confirm-card button.mv-btn-confirm { background: #198754; color: #fff; }
  .mv-confirm-card button.mv-btn-cancel  { background: #6c757d; color: #fff; }
  .mv-confirm-card.done { opacity: 0.6; pointer-events: none; }
  #mv-chat-typing { align-self: flex-start; color: #6c757d; font-size: 12px; padding: 4px 12px; font-style: italic; display: none; }
  #mv-chat-typing.show { display: block; }
  #mv-chat-input-row {
    border-top: 1px solid #dee2e6; padding: 8px; background: #fff;
    display: flex; gap: 6px; align-items: flex-end;
  }
  #mv-chat-input {
    flex: 1; border: 1px solid #ced4da; border-radius: 6px;
    padding: 8px 10px; resize: none; font-size: 14px; max-height: 100px;
    font-family: inherit;
  }
  #mv-chat-send {
    background: #0d6efd; color: #fff; border: none; border-radius: 6px;
    padding: 8px 14px; cursor: pointer; font-size: 14px;
  }
  #mv-chat-send:disabled { background: #6ea8fe; cursor: not-allowed; }
  @media (max-width: 480px) {
    #mv-chat-panel { right: 0; bottom: 0; left: 0; top: 0; width: 100%; height: 100%; border-radius: 0; }
    #mv-chat-fab { right: 12px; bottom: 12px; }
    #mv-chat-history-dropdown { right: 8px; left: 8px; width: auto; }
  }
</style>

<button id="mv-chat-fab" type="button" aria-label="Open MyVivarium AI chat" title="MyVivarium AI">💬</button>
<div id="mv-chat-panel" role="dialog" aria-label="MyVivarium AI">
  <div id="mv-chat-header">
    <span class="mv-chat-title">MyVivarium AI<span class="mv-chat-subtitle">powered by <?= htmlspecialchars($__chat_provider_label); ?> <?= htmlspecialchars($__chat_model); ?></span></span>
    <button type="button" id="mv-chat-new"     title="New conversation" aria-label="New conversation">＋</button>
    <button type="button" id="mv-chat-history" title="History"          aria-label="History">≡</button>
    <button type="button" id="mv-chat-close"   title="Close"            aria-label="Close">×</button>
  </div>
  <div id="mv-chat-history-dropdown"></div>
  <div id="mv-chat-messages"></div>
  <div id="mv-chat-typing">MyVivarium AI is thinking…</div>
  <div id="mv-chat-input-row">
    <textarea id="mv-chat-input" rows="1" placeholder="Ask about the colony…" maxlength="2000"></textarea>
    <button type="button" id="mv-chat-send">Send</button>
  </div>
</div>

<script>
(function () {
  const fab      = document.getElementById('mv-chat-fab');
  const panel    = document.getElementById('mv-chat-panel');
  const msgs     = document.getElementById('mv-chat-messages');
  const input    = document.getElementById('mv-chat-input');
  const sendBtn  = document.getElementById('mv-chat-send');
  const closeBtn = document.getElementById('mv-chat-close');
  const newBtn   = document.getElementById('mv-chat-new');
  const histBtn  = document.getElementById('mv-chat-history');
  const histDrop = document.getElementById('mv-chat-history-dropdown');
  const typing   = document.getElementById('mv-chat-typing');

  let conversationId = '';
  let pendingOpId    = null;
  let busy           = false;

  function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function scrollBottom() { msgs.scrollTop = msgs.scrollHeight; }

  function addMessage(role, content) {
    const div = document.createElement('div');
    div.className = 'mv-msg mv-msg-' + (role === 'user' ? 'user' : role === 'system_event' ? 'event' : 'assistant');
    div.textContent = content;
    msgs.appendChild(div);
    scrollBottom();
  }
  function addEvent(text) {
    const div = document.createElement('div');
    div.className = 'mv-msg mv-msg-event';
    div.textContent = text;
    msgs.appendChild(div);
    scrollBottom();
  }
  function addToolCard(call) {
    if (!call || !call.name) return;
    const wrap = document.createElement('div');
    wrap.className = 'mv-tool-card';
    const head = document.createElement('div');
    head.className = 'mv-tool-head';
    head.textContent = '🔧 ' + call.name + (call.status ? ' (HTTP ' + call.status + ')' : '');
    const body = document.createElement('div');
    body.className = 'mv-tool-body';
    body.textContent = JSON.stringify(call.request || call, null, 2);
    wrap.appendChild(head); wrap.appendChild(body);
    wrap.addEventListener('click', () => wrap.classList.toggle('expanded'));
    msgs.appendChild(wrap);
    scrollBottom();
  }
  function addConfirmCard(pc) {
    pendingOpId = pc.pending_operation_id;
    const wrap = document.createElement('div');
    wrap.className = 'mv-confirm-card';
    const sum = document.createElement('div');
    sum.className = 'mv-confirm-summary';
    sum.textContent = '⚠ Confirm: ' + (pc.summary || 'Destructive action');
    const diff = document.createElement('div');
    diff.className = 'mv-confirm-diff';
    diff.textContent = JSON.stringify(pc.diff || {}, null, 2);
    const btns = document.createElement('div');
    btns.className = 'mv-confirm-btns';
    const ok = document.createElement('button');
    ok.className = 'mv-btn-confirm'; ok.textContent = 'Confirm';
    const no = document.createElement('button');
    no.className = 'mv-btn-cancel';  no.textContent = 'Cancel';
    btns.appendChild(ok); btns.appendChild(no);
    wrap.appendChild(sum); wrap.appendChild(diff); wrap.appendChild(btns);
    msgs.appendChild(wrap);
    scrollBottom();

    ok.addEventListener('click', () => {
      wrap.classList.add('done');
      sendBackend({ confirm_pending_op: pc.pending_operation_id });
    });
    no.addEventListener('click', () => {
      wrap.classList.add('done');
      sendBackend({ cancel_pending_op: pc.pending_operation_id });
    });
  }

  function setBusy(state) {
    busy = state;
    sendBtn.disabled = state;
    typing.classList.toggle('show', state);
    if (state) scrollBottom();
  }

  function sendBackend(extra) {
    if (busy) return;
    pendingOpId = null;
    setBusy(true);
    const payload = Object.assign({ conversation_id: conversationId || undefined }, extra);
    fetch('ai_chat.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(r => r.json().then(j => ({ status: r.status, body: j })))
      .then(({ status, body }) => {
        setBusy(false);
        if (!body || !body.ok) {
          const err = (body && (body.detail || body.error)) || ('HTTP ' + status);
          addMessage('assistant', '⚠ ' + err);
          return;
        }
        conversationId = body.conversation_id || conversationId;
        (body.tool_calls || []).forEach(addToolCard);
        if (body.pending_confirmation) {
          addConfirmCard(body.pending_confirmation);
        } else if (body.reply) {
          addMessage('assistant', body.reply);
        }
      })
      .catch(err => {
        setBusy(false);
        addMessage('assistant', '⚠ Network error: ' + err.message);
      });
  }

  function send() {
    const text = input.value.trim();
    if (!text || busy) return;
    addMessage('user', text);
    input.value = ''; input.style.height = 'auto';
    sendBackend({ message: text });
  }

  fab.addEventListener('click', () => {
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
      input.focus();
      if (!conversationId) {
        // First open: try to load most-recent conversation, else stay empty.
        loadHistory(true);
      }
    }
  });
  closeBtn.addEventListener('click', () => panel.classList.remove('open'));
  sendBtn.addEventListener('click', send);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 100) + 'px';
  });

  newBtn.addEventListener('click', () => {
    conversationId = '';
    pendingOpId = null;
    msgs.innerHTML = '';
    addEvent('New conversation started.');
    input.focus();
  });

  function loadHistory(autoselectLatest) {
    fetch('ai_chat_history.php', { credentials: 'same-origin' })
      .then(r => r.json()).then(j => {
        if (!j.ok) return;
        const list = j.conversations || [];
        histDrop.innerHTML = '';
        if (!list.length) {
          const e = document.createElement('div');
          e.className = 'mv-hist-empty';
          e.textContent = 'No past conversations.';
          histDrop.appendChild(e);
        } else {
          list.forEach(c => {
            const row = document.createElement('div');
            row.className = 'mv-hist-item';
            row.textContent = c.title || ('Conversation ' + c.id.slice(0, 8));
            row.addEventListener('click', () => {
              histDrop.classList.remove('open');
              loadConversation(c.id);
            });
            histDrop.appendChild(row);
          });
        }
        if (autoselectLatest && list.length) {
          loadConversation(list[0].id);
        }
      }).catch(() => {});
  }
  function loadConversation(id) {
    fetch('ai_chat_history.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(r => r.json()).then(j => {
        if (!j.ok) return;
        conversationId = j.conversation.id;
        msgs.innerHTML = '';
        (j.messages || []).forEach(m => {
          if (m.role === 'user' || m.role === 'assistant') {
            if (m.content) addMessage(m.role, m.content);
            if (m.tool_call_json && m.tool_call_json.tool_calls) {
              m.tool_call_json.tool_calls.forEach(tc => addToolCard({ name: (tc.function && tc.function.name) || 'tool' }));
            }
          } else if (m.role === 'system_event') {
            addEvent(m.content || '');
          } else if (m.role === 'tool') {
            addToolCard({ name: (m.tool_call_json && m.tool_call_json.function && m.tool_call_json.function.name) || 'tool', request: m.tool_result_json });
          }
        });
      }).catch(() => {});
  }

  histBtn.addEventListener('click', e => {
    e.stopPropagation();
    if (histDrop.classList.contains('open')) {
      histDrop.classList.remove('open');
    } else {
      loadHistory(false);
      histDrop.classList.add('open');
    }
  });
  document.addEventListener('click', e => {
    if (!histDrop.contains(e.target) && e.target !== histBtn) histDrop.classList.remove('open');
  });
})();
</script>
