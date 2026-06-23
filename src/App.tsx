import { useState, useRef, useEffect, useCallback } from 'react';
import { sendMessage, clearSession, clearPending, uploadMedia } from './lib/api';
import type { Message, ChatResponse } from './lib/types';

const data = window.wppilotData;
const STARTERS: { label: string; prompt: string }[] = [
  { label: 'Create a product',   prompt: 'Create a product' },
  { label: 'Update a price',     prompt: 'Update a price' },
  { label: 'Edit a product',     prompt: 'Edit product ' },
  { label: 'Update stock',       prompt: 'Update stock for product ' },
  { label: 'Unpublish product',  prompt: 'Unpublish product ' },
];
const MSG_PAGE = 20; // messages rendered per page

function genId() { return Math.random().toString(36).slice(2); }
function fmtTime(ts: number) {
  return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// ── Icons ─────────────────────────────────────────────────────────────────

const IconPlane = () => (
  <svg viewBox="0 0 24 24" fill="currentColor">
    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
  </svg>
);
const IconPen = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
  </svg>
);
const IconX = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
    <path d="M18 6L6 18M6 6l12 12"/>
  </svg>
);
const IconSend = () => (
  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
);
const IconCopy = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
  </svg>
);
const IconRegen = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M1 4v6h6M23 20v-6h-6"/><path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15"/>
  </svg>
);
const IconDown = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
    <polyline points="6 9 12 15 18 9"/>
  </svg>
);
const IconImage = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
    <polyline points="21 15 16 10 5 21"/>
  </svg>
);
const IconLink = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/>
  </svg>
);

// ── Sub-components ────────────────────────────────────────────────────────

function TypingIndicator() {
  return (
    <div className="wppilot-typing-row">
      <div className="wppilot-typing">
        <div className="wppilot-dot"/><div className="wppilot-dot"/><div className="wppilot-dot"/>
      </div>
    </div>
  );
}

function ToolCard({ name, result }: { name: string; result: Record<string, unknown> }) {
  const ok = result.success !== false;
  const label = ok
    ? (result.message as string || name.replace(/_/g, ' '))
    : (result.error as string || 'Action failed');

  return (
    <div className={`wppilot-tool-card ${ok ? 'ok' : 'fail'}`}>
      <div className="wppilot-tool-card-header">
        <div className="wppilot-tool-status-icon">{ok ? '✓' : '✕'}</div>
        <span>{label}</span>
      </div>
      {ok && result.edit_url && (
        <a className="wppilot-tool-card-link" href={result.edit_url as string} target="_blank" rel="noreferrer">
          <IconLink /> Open in WooCommerce
        </a>
      )}
    </div>
  );
}

function renderInline(text: string): React.ReactNode[] {
  const parts: React.ReactNode[] = [];
  let remaining = text;
  let key = 0;
  while (remaining.length > 0) {
    const linkIdx = remaining.indexOf('[');
    const boldIdx = remaining.indexOf('**');
    const first = Math.min(
      linkIdx === -1 ? Infinity : linkIdx,
      boldIdx === -1 ? Infinity : boldIdx,
    );
    if (first === Infinity) { parts.push(remaining); break; }
    if (first === boldIdx) {
      const m = remaining.match(/^(.*?)\*\*([^*]+)\*\*(.*)/s);
      if (m) { if (m[1]) parts.push(m[1]); parts.push(<strong key={key++}>{m[2]}</strong>); remaining = m[3]; continue; }
    }
    if (first === linkIdx) {
      const m = remaining.match(/^(.*?)\[([^\]]+)\]\(([^)]+)\)(.*)/s);
      if (m) { if (m[1]) parts.push(m[1]); parts.push(<a key={key++} href={m[3]} target="_blank" rel="noreferrer">{m[2]}</a>); remaining = m[4]; continue; }
    }
    parts.push(remaining); break;
  }
  return parts;
}

function renderContent(text: string): React.ReactNode {
  return (
    <>
      {text.split('\n\n').map((block, i, arr) => {
        const lines = block.split('\n');
        const isList = lines.some(l => l.startsWith('- '));
        const mb = i < arr.length - 1 ? { marginBottom: '0.65em' } : {};
        if (isList) {
          return (
            <div key={i} style={mb}>
              {lines.map((line, j) =>
                line.startsWith('- ')
                  ? <div key={j} style={{ paddingLeft: '1em' }}>• {renderInline(line.slice(2))}</div>
                  : line ? <div key={j}>{renderInline(line)}</div> : null
              )}
            </div>
          );
        }
        return <p key={i} style={{ margin: 0, ...mb }}>{renderInline(block)}</p>;
      })}
    </>
  );
}

function MessageRow({
  msg, onCopy, onRegenerate, isLast,
}: { msg: Message; onCopy: (t: string) => void; onRegenerate?: () => void; isLast: boolean }) {
  const isUser = msg.role === 'user';
  return (
    <div className={`wppilot-row ${isUser ? 'user' : 'ai'}${msg.isError ? ' error' : ''}`}>
      <div className="wppilot-bubble">
        {isUser ? msg.content : renderContent(msg.content)}
        {msg.toolName && msg.toolResult && (
          <ToolCard name={msg.toolName} result={msg.toolResult} />
        )}
      </div>

      {!isUser && (
        <div className="wppilot-msg-actions">
          <button className="wppilot-action-btn" onClick={() => onCopy(msg.content)} title="Copy">
            <IconCopy /> Copy
          </button>
          {isLast && onRegenerate && (
            <button className="wppilot-action-btn" onClick={onRegenerate} title="Regenerate">
              <IconRegen /> Retry
            </button>
          )}
        </div>
      )}

      <div className="wppilot-timestamp">{fmtTime(msg.timestamp)}</div>
    </div>
  );
}


function EmptyState({ onStarter, inputHasText }: { onStarter: (s: string) => void; inputHasText: boolean }) {
  const noKey = !data.hasBYOKey;

  if (noKey) {
    const connectorsUrl = `${data.siteUrl}/wp-admin/options-general.php?page=connectors`;
    return (
      <div className="wppilot-empty">
        <div className="wppilot-empty-icon"><IconPlane /></div>
        <p className="wppilot-empty-title">Set up an AI provider to get started</p>
        <p className="wppilot-empty-sub">
          StoreHand AI Product Manager works with Anthropic, OpenAI, or Google Gemini. Install a provider plugin and add your API key in Settings → Connectors.
        </p>
        <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', justifyContent: 'center', marginTop: '8px' }}>
          <a href={connectorsUrl} className="wppilot-starter" style={{ textDecoration: 'none' }}>Go to Connectors →</a>
        </div>
      </div>
    );
  }

  return (
    <div className="wppilot-empty">
      <div className="wppilot-empty-icon"><IconPlane /></div>
      <p className="wppilot-empty-title">How can I help with your products today?</p>
      <p className="wppilot-empty-sub">
        Describe what you need in plain English — I'll handle the WooCommerce setup.
      </p>
    </div>
  );
}

function ConfirmDialog({
  message, actionName, onConfirm, onCancel, loading,
}: { message: string; actionName: string; onConfirm: () => void; onCancel: () => void; loading: boolean }) {
  return (
    <div className="wppilot-confirm">
      <p className="wppilot-confirm-msg">{message}</p>
      <p className="wppilot-confirm-warning">⚠️ This will change your live product data.</p>
      <p className="wppilot-confirm-proceed">Proceed?</p>
      <div className="wppilot-confirm-actions">
        <button className="wppilot-btn-ok" onClick={onConfirm} disabled={loading}>
          {loading ? '…' : 'Yes'}
        </button>
        <button className="wppilot-btn-no" onClick={onCancel} disabled={loading}>No</button>
      </div>
    </div>
  );
}

// ── Main App ──────────────────────────────────────────────────────────────

export default function App() {
  const [open, setOpen]               = useState(false);
  const [messages, setMessages]       = useState<Message[]>([]);
  const [input, setInput]             = useState('');
  const [loading, setLoading]         = useState(false);
  const [sessionId, setSessionId]     = useState<string | null>(null);
  const [pendingConfirm, setPendingConfirm] = useState<{ message: string; actionName: string } | null>(null);
  const [atBottom, setAtBottom]       = useState(true);
  const [unread, setUnread]           = useState(0);
  const [lastUserMsg, setLastUserMsg] = useState<string | null>(null);
  const [copied, setCopied]           = useState(false);
  const [visibleFrom, setVisibleFrom] = useState(0);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [imageId, setImageId]           = useState<number | null>(null);
  const [imageUploading, setImageUploading] = useState(false);
  const [imageError, setImageError]     = useState<string | null>(null);

  const messagesRef      = useRef<HTMLDivElement>(null);
  const textareaRef      = useRef<HTMLTextAreaElement>(null);
  const fileInputRef     = useRef<HTMLInputElement>(null);
  const touchStartY      = useRef(0);
  const welcomeShownRef  = useRef(false);
  const touchStartX  = useRef(0);

  // Track scroll position to show/hide scroll-to-bottom button
  function handleScroll() {
    const el = messagesRef.current;
    if (!el) return;
    setAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 60);
  }

  // Auto-scroll when new content arrives (if already at bottom)
  useEffect(() => {
    if (atBottom) {
      messagesRef.current?.scrollTo({ top: messagesRef.current.scrollHeight, behavior: 'smooth' });
    }
  }, [messages, loading, pendingConfirm]);

  // Unread badge when panel closed and AI responds
  useEffect(() => {
    const last = messages[messages.length - 1];
    if (!open && last?.role === 'assistant') setUnread(c => c + 1);
  }, [messages]);

  // Lazy rendering — keep only MSG_PAGE most recent messages visible
  useEffect(() => {
    setVisibleFrom(Math.max(0, messages.length - MSG_PAGE));
  }, [messages.length]);

  // Welcome message — shown once per conversation (first open, and after New Chat)
  useEffect(() => {
    if (open && messages.length === 0 && !welcomeShownRef.current) {
      welcomeShownRef.current = true;
      push({
        role: 'assistant',
        content: "Hi! I'm StoreHand AI Product Manager, your AI assistant for WooCommerce products.\n\nI work with your configured AI provider (Anthropic, OpenAI, or Google Gemini) to help you manage products using plain English.\n\nI create products as drafts so you can review them before publishing.\n\nWhat would you like to do?",
      });
    }
  }, [open]);

  // Focus textarea and clear unread on open
  useEffect(() => {
    if (open) {
      setUnread(0);
      setTimeout(() => textareaRef.current?.focus(), 50);
    }
  }, [open]);

  const push = useCallback((partial: Omit<Message, 'id' | 'timestamp'>) => {
    setMessages(prev => [...prev, { ...partial, id: genId(), timestamp: Date.now() }]);
  }, []);

  const settingsUrl = '/wp-admin/options-general.php?page=storehand-ai-product-manager-for-woocommerce';

  const handleResponse = useCallback((res: ChatResponse) => {
    if (res.session_id) setSessionId(res.session_id);
    if (res.type === 'confirmation_required') {
      setPendingConfirm({ message: res.message, actionName: res.action_name ?? 'proceed' });
      return;
    }
    if (res.type === 'error' || res.error) {
      let msg = res.message || 'Something went wrong. Please try again.';
      if (res.error_code === 'invalid_api_key') {
        msg = `${msg} [Go to Settings →](${settingsUrl})`;
      } else if (res.error_code === 'network_error' || res.error_code === 'timeout') {
        msg = `${msg} Use the Retry button to try again.`;
      }
      push({ role: 'assistant', content: msg, isError: true });
      return;
    }
    push({ role: 'assistant', content: res.message, toolName: res.tool_executed, toolResult: res.tool_result });
  }, [push, settingsUrl]);

  function autoResize() {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }

  async function handleImageSelect(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    e.target.value = '';
    setImageError(null);

    const reader = new FileReader();
    reader.onload = ev => setImagePreview(ev.target?.result as string);
    reader.readAsDataURL(file);

    setImageUploading(true);
    setImageId(null);
    try {
      const id = await uploadMedia(file);
      setImageId(id);
    } catch (err) {
      setImageError(err instanceof Error ? err.message : 'Upload failed');
      setImagePreview(null);
    } finally {
      setImageUploading(false);
    }
  }

  function clearImage() {
    setImagePreview(null);
    setImageId(null);
    setImageError(null);
    setImageUploading(false);
  }

  async function submit(msg: string) {
    const hasImage = imageId !== null;
    const trimmed = msg.trim();
    if (!trimmed && !hasImage || loading) return;

    const fullMsg = hasImage
      ? (trimmed
          ? `${trimmed}\n\n[Attached product image — WordPress media ID: ${imageId}. Use image_id: ${imageId} in your tool call.]`
          : `[Attached product image — WordPress media ID: ${imageId}. Use image_id: ${imageId} for the product thumbnail.]`)
      : trimmed;

    setLastUserMsg(fullMsg);
    setInput('');
    if (textareaRef.current) textareaRef.current.style.height = 'auto';
    clearImage();
    push({ role: 'user', content: trimmed || '📎 Product image attached' });
    setLoading(true);
    setAtBottom(true);
    try {
      const res = await sendMessage(fullMsg, sessionId);
      handleResponse(res);
    } catch {
      push({ role: 'assistant', content: 'Could not reach the server. Please try again.', isError: true });
    } finally {
      setLoading(false);
    }
  }

  async function handleConfirm() {
    if (!pendingConfirm) return;
    setPendingConfirm(null);
    setLoading(true);
    try {
      const res = await sendMessage('confirmed', sessionId);
      handleResponse(res);
    } catch {
      push({ role: 'assistant', content: 'Could not reach the server. Please try again.', isError: true });
    } finally {
      setLoading(false);
    }
  }

  function handleCancel() {
    setPendingConfirm(null);
    if (sessionId) clearPending(sessionId);
    push({ role: 'assistant', content: "No problem — cancelled. What else can I help with?" });
  }

  async function handleRegenerate() {
    if (!lastUserMsg || loading) return;
    setMessages(prev => {
      const copy = [...prev];
      while (copy.length > 0 && copy[copy.length - 1].role !== 'user') copy.pop();
      copy.pop(); // remove last user message too
      return copy;
    });
    await submit(lastUserMsg);
  }

  async function handleCopy(text: string) {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch { /* ignore */ }
  }

  async function handleNewChat() {
    if (sessionId) await clearSession(sessionId);
    setSessionId(null);
    setMessages([]);
    setPendingConfirm(null);
    setLastUserMsg(null);
    clearImage();
    welcomeShownRef.current = false;
  }

  function scrollToBottom() {
    messagesRef.current?.scrollTo({ top: messagesRef.current.scrollHeight, behavior: 'smooth' });
    setAtBottom(true);
  }

  function handleKey(e: React.KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(input); }
  }

  // Swipe-down to close on mobile
  function onTouchStart(e: React.TouchEvent) {
    touchStartY.current = e.touches[0].clientY;
    touchStartX.current = e.touches[0].clientX;
  }
  function onTouchEnd(e: React.TouchEvent) {
    const dy = e.changedTouches[0].clientY - touchStartY.current;
    const dx = Math.abs(e.changedTouches[0].clientX - touchStartX.current);
    if (dy > 80 && dx < 40) setOpen(false); // mostly vertical swipe down
  }

  function handleStarterClick(starter: string) {
    setInput(starter);
    textareaRef.current?.focus();
    autoResize();
  }

  const canSend      = (input.trim().length > 0 || imageId !== null) && !loading && !pendingConfirm && data.hasBYOKey && !imageUploading;
  const isEmpty      = messages.length === 0;
  const showStarters = data.hasBYOKey && !input.length && (isEmpty || (messages.length === 1 && messages[0].role === 'assistant'));
  const visibleMsgs   = messages.slice(visibleFrom);
  const hasOlder      = visibleFrom > 0;
  const lastAiIdx     = visibleMsgs.map((m, i) => m.role === 'assistant' ? i : -1).filter(i => i >= 0).pop();

  return (
    <>
      {/* FAB with unread badge */}
      <div className={`wppilot-fab-wrap${open ? ' panel-open' : ''}`}>
        <button className="wppilot-fab" onClick={() => setOpen(o => !o)} aria-label="Open StoreHand AI Product Manager">
          <IconPlane />
        </button>
        {unread > 0 && !open && (
          <div className="wppilot-badge">{unread > 9 ? '9+' : unread}</div>
        )}
      </div>

      {/* Backdrop — tap-outside to close */}
      {open && <div className="wppilot-backdrop" onClick={() => setOpen(false)} aria-hidden />}

      {/* Panel */}
      <div
        className={`wppilot-panel${open ? ' wppilot-open' : ''}`}
        role="dialog"
        aria-label="StoreHand AI Product Manager for WooCommerce"
        onTouchStart={onTouchStart}
        onTouchEnd={onTouchEnd}
      >

        {/* Header */}
        <div className="wppilot-header">
          <div className="wppilot-header-left">
            <div className="wppilot-header-icon"><IconPlane /></div>
            <div>
              <p className="wppilot-header-title">StoreHand AI Product Manager for WooCommerce</p>
              <p className="wppilot-header-sub">AI assistant</p>
            </div>
          </div>
          <div className="wppilot-header-actions">
            <button className="wppilot-icon-btn" onClick={handleNewChat} title="New chat"><IconPen /></button>
            <button className="wppilot-icon-btn" onClick={() => setOpen(false)} title="Close"><IconX /></button>
          </div>
        </div>

        {/* Messages or empty state */}
        <div className="wppilot-messages-wrap">

          {isEmpty ? (
            <EmptyState
              onStarter={handleStarterClick}
              inputHasText={input.length > 0}
            />
          ) : (
            <div className="wppilot-messages" ref={messagesRef} onScroll={handleScroll}>
              {hasOlder && (
                <button
                  className="wppilot-load-older"
                  onClick={() => setVisibleFrom(v => Math.max(0, v - MSG_PAGE))}
                >
                  Load older messages
                </button>
              )}
              {visibleMsgs.map((msg, i) => (
                <MessageRow
                  key={msg.id}
                  msg={msg}
                  onCopy={handleCopy}
                  onRegenerate={i === lastAiIdx && !loading ? handleRegenerate : undefined}
                  isLast={i === lastAiIdx}
                />
              ))}
              {loading && <TypingIndicator />}
              {pendingConfirm && (
                <ConfirmDialog
                  message={pendingConfirm.message}
                  actionName={pendingConfirm.actionName}
                  onConfirm={handleConfirm}
                  onCancel={handleCancel}
                  loading={loading}
                />
              )}
            </div>
          )}

          {showStarters && (
            <div className="wppilot-starters">
              {STARTERS.map(s => (
                <button key={s.label} className="wppilot-starter" onClick={() => handleStarterClick(s.prompt)}>{s.label}</button>
              ))}
            </div>
          )}

          {!atBottom && !isEmpty && (
            <button className="wppilot-scroll-btn" onClick={scrollToBottom}>
              <IconDown /> Scroll to bottom
            </button>
          )}
        </div>

        {/* Copied toast — simple */}
        {copied && (
          <div style={{
            position: 'absolute', bottom: 80, left: '50%', transform: 'translateX(-50%)',
            background: '#1A1A2E', color: 'white', fontSize: 12, padding: '6px 14px',
            borderRadius: 999, zIndex: 10, pointerEvents: 'none',
          }}>
            Copied!
          </div>
        )}

        {/* Input */}
        <div className="wppilot-input-area">
          {/* Image preview strip */}
          {(imagePreview || imageError) && (
            <div className="wppilot-img-preview-row">
              {imagePreview && (
                <div className="wppilot-img-thumb-wrap">
                  <img src={imagePreview} className="wppilot-img-thumb" alt="Product image preview" />
                  {imageUploading && <div className="wppilot-img-uploading" />}
                  {!imageUploading && imageId && <div className="wppilot-img-ok">✓</div>}
                  <button className="wppilot-img-remove" onClick={clearImage} aria-label="Remove image"><IconX /></button>
                </div>
              )}
              {imageError && (
                <div className="wppilot-img-error">{imageError} <button onClick={clearImage}><IconX /></button></div>
              )}
            </div>
          )}

          <div className="wppilot-input-wrap">
            {/* Hidden file input */}
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*"
              style={{ display: 'none' }}
              onChange={handleImageSelect}
            />
            <button
              className="wppilot-img-btn"
              onClick={() => fileInputRef.current?.click()}
              disabled={!!pendingConfirm || !data.hasBYOKey || imageUploading}
              aria-label="Attach product image"
              title="Attach product image"
            >
              <IconImage />
            </button>
            <textarea
              ref={textareaRef}
              className="wppilot-textarea"
              value={input}
              rows={1}
              placeholder="Ask StoreHand AI Product Manager anything…"
              onChange={e => { setInput(e.target.value); autoResize(); }}
              onKeyDown={handleKey}
              disabled={!!pendingConfirm || !data.hasBYOKey}
            />
            <button className="wppilot-send-btn" onClick={() => submit(input)} disabled={!canSend} aria-label="Send">
              <IconSend />
            </button>
          </div>
          <p className="wppilot-input-hint">Enter to send · Shift+Enter for new line</p>
        </div>
      </div>
    </>
  );
}
