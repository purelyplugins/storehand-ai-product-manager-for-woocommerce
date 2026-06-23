export type MessageRole = 'user' | 'assistant' | 'tool' | 'system';

export interface Message {
  id: string;
  role: MessageRole;
  content: string;
  toolName?: string;
  toolResult?: Record<string, unknown>;
  isError?: boolean;
  timestamp: number;
}

export interface ChatResponse {
  type: 'text' | 'confirmation_required' | 'error';
  message: string;
  action_name?: string;
  tool_executed?: string;
  tool_result?: Record<string, unknown>;
  session_id: string;
  error?: string;
  error_code?: string;
}

export interface WPPilotData {
  apiUrl: string;
  wpApiUrl: string;
  nonce: string;
  hasBYOKey: boolean;
  siteUrl: string;
  i18n: Record<string, string>;
}

declare global {
  interface Window {
    wppilotData: WPPilotData;
  }
}
