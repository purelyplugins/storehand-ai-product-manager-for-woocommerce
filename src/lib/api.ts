import type { ChatResponse } from './types';

const { apiUrl, nonce } = window.wppilotData;

export async function sendMessage(
  message: string,
  sessionId: string | null,
  confirmed = false
): Promise<ChatResponse> {
  const fallback = (msg: string): ChatResponse => ({
    type: 'error',
    message: msg,
    session_id: sessionId ?? '',
  });

  let res: Response;
  try {
    res = await fetch(`${apiUrl}/chat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify({ message, session_id: sessionId, confirmed }),
    });
  } catch (err) {
    return fallback(`Could not reach the server: ${err instanceof Error ? err.message : String(err)}`);
  }

  // Read body as text first — if PHP throws a fatal error the body will be
  // HTML, not JSON, and res.json() would throw and hide the real error.
  const text = await res.text();
  let data: ChatResponse;
  try {
    data = JSON.parse(text) as ChatResponse;
  } catch {
    // Server returned non-JSON — surface the raw response (truncated) so the
    // user (and developer) can see the actual PHP error.
    const preview = text.replace(/<[^>]+>/g, ' ').trim().slice(0, 300);
    return fallback(`Server returned an unexpected response: ${preview}`);
  }

  if (!res.ok) {
    return fallback(data.message || `Server error (${res.status})`);
  }
  return data;
}

export async function uploadMedia( file: File ): Promise<number> {
  const { wpApiUrl, nonce } = window.wppilotData;
  const formData = new FormData();
  formData.append( 'file', file );
  const res = await fetch( `${wpApiUrl}/media`, {
    method: 'POST',
    headers: {
      'X-WP-Nonce': nonce,
      'Content-Disposition': `attachment; filename="${file.name}"`,
    },
    body: formData,
  } );
  if ( !res.ok ) {
    const text = await res.text().catch( () => '' );
    const preview = text.replace( /<[^>]+>/g, ' ' ).trim().slice( 0, 200 );
    throw new Error( preview || `Upload failed (${res.status})` );
  }
  const data = await res.json();
  return data.id as number;
}

export async function clearSession( sessionId: string ): Promise<void> {
  await fetch(`${apiUrl}/session/clear`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify({ session_id: sessionId }),
  });
}

export async function clearPending( sessionId: string ): Promise<void> {
  await fetch(`${apiUrl}/pending/clear`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify({ session_id: sessionId }),
  });
}
