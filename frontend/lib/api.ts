// lib/api.ts
export type Conversation = {
  id: number;
  title: string;
  mode: "test" | "train";
  last_message_at?: string | null;
  summary?: string | null;
};

export type Message = {
  id: number;
  conversation_id: number;
  role: "user" | "assistant";
  content: string;
  score?: number | null;
  is_training?: boolean;
  meta?: any | null;
  rated_at?: string | null;
};

export type ConversationWithMessages = Conversation & {
  messages: Message[];
};

export type SendMessageResponse = {
  conversation_id: number;
  conversation_mode?: "test" | "train";
  user_message_id: number;
  assistant_message_id: number;
  answer: string;
  kb_hits: any[];
  score?: number | null;
};

const BASE = "/api";

async function handleJsonResponse(res: Response) {
  if (!res.ok) {
    const body = await res.text();
    throw new Error(body || `HTTP ${res.status}`);
  }
  return res.json();
}

export const chatApi = {
  async getConversations(): Promise<Conversation[]> {
    const res = await fetch(`${BASE}/chat/conversations`, { method: "GET" });
    return handleJsonResponse(res);
  },

  async getConversation(id: number): Promise<ConversationWithMessages> {
    const res = await fetch(`${BASE}/chat/conversations/${id}`, {
      method: "GET",
    });
    return handleJsonResponse(res);
  },

  async deleteConversation(id: number) {
    const res = await fetch(`${BASE}/chat/conversations/${id}`, {
      method: "DELETE",
    });
    return handleJsonResponse(res);
  },

  async updateConversation(id: number, payload: any) {
    const res = await fetch(`${BASE}/chat/conversations/${id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    return handleJsonResponse(res);
  },

  async sendMessage(payload: {
    conversation_id?: number;
    message: string;
    mode?: "test" | "train";
  }): Promise<SendMessageResponse> {
    const res = await fetch(`${BASE}/chat/message`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    return handleJsonResponse(res);
  },

  /**
   * sendMessageStream:
   * - payload same as sendMessage
   * - callbacks: onStart(info), onChunk(chunk), onDone(final)
   *
   * Backend returns newline-delimited JSON lines (NDJSON): each line is a JSON object with type chunk/done
   */
  async sendMessageStream(
    payload: {
      conversation_id?: number;
      message: string;
      mode?: "test" | "train";
    },
    callbacks: {
      onStart?: (info: {
        conversation_id: number;
        assistant_message_id: number;
        user_message_id?: number;
      }) => void;
      onChunk?: (chunk: string) => void;
      onDone?: (final: {
        conversation_id: number;
        assistant_message_id: number;
        answer: string;
        score?: number | null;
      }) => void;
    },
  ): Promise<void> {
    const res = await fetch(`${BASE}/chat/message/stream`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });

    if (!res.ok || !res.body) {
      const text = await res.text();
      throw new Error(text || `HTTP ${res.status}`);
    }

    // Read the stream as text chunks, parse newline-delimited JSON
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";

    // We expect backend to send lines like: { "type":"chunk", ... }\n  or { "type": "done", ... }\n
    // The first chunk may include an initial "start" or "chunk".
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      // split lines
      const lines = buffer.split("\n");
      buffer = lines.pop() ?? "";

      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        try {
          const payload = JSON.parse(trimmed);
          if (payload.type === "chunk") {
            // first chunk might carry ids; call onStart if present and not yet called
            if (callbacks.onStart) {
              callbacks.onStart({
                conversation_id: payload.conversation_id,
                assistant_message_id: payload.assistant_message_id,
                user_message_id: payload.user_message_id,
              });
              // nullify so we don't call again (but we won't nullify here to keep simple)
              // we rely on frontend to ignore duplicates
              callbacks.onStart = undefined;
            }
            if (callbacks.onChunk) callbacks.onChunk(payload.chunk);
          } else if (payload.type === "done") {
            if (callbacks.onDone)
              callbacks.onDone({
                conversation_id: payload.conversation_id,
                assistant_message_id: payload.assistant_message_id,
                answer: payload.answer,
                score: payload.score ?? null,
              });
          }
        } catch (err) {
          // ignore parse errors for partial lines
          console.warn("Failed parse stream line", err, trimmed);
        }
      }
    }
  },

  async rateMessage(messageId: number, payload: { score: number }) {
    const res = await fetch(`${BASE}/chat/messages/${messageId}/rate`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    return handleJsonResponse(res);
  },

  async summarizeConversation(conversationId: number) {
    const res = await fetch(
      `${BASE}/chat/conversations/${conversationId}/summarize`,
      { method: "POST" },
    );
    return handleJsonResponse(res);
  },
};
