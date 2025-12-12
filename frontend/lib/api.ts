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

// ใช้ BASE_URL + API_KEY จาก env แทน BASE เดิม
const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || "";
const API_KEY = process.env.NEXT_PUBLIC_API_KEY || "";

// helper สำหรับเรียก API ให้แน่ใจว่าแนบ X-API-KEY ทุกครั้ง
async function ChatFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const res = await fetch(`${BASE_URL}${path}`, {
    ...options,
    headers: {
      "X-API-KEY": API_KEY,
      ...(options.headers || {}),
    },
  });

  if (!res.ok) {
    const body = await res.text();
    throw new Error(body || `HTTP ${res.status}`);
  }

  return res.json();
}

export const chatApi = {
  async getConversations(): Promise<Conversation[]> {
    return ChatFetch<Conversation[]>("/api/chat/conversations", {
      method: "GET",
    });
  },

  async getConversation(id: number): Promise<ConversationWithMessages> {
    return ChatFetch<ConversationWithMessages>(
      `/api/chat/conversations/${id}`,
      {
        method: "GET",
      },
    );
  },

  async deleteConversation(id: number) {
    return ChatFetch(`/api/chat/conversations/${id}`, {
      method: "DELETE",
    });
  },

  async updateConversation(id: number, payload: any): Promise<Conversation> {
    const res = await ChatFetch<Conversation>(`/api/chat/conversations/${id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    return res;
  },
  async sendMessage(payload: {
    conversation_id?: number;
    message: string;
    mode?: "test" | "train";
  }): Promise<SendMessageResponse> {
    return ChatFetch<SendMessageResponse>("/api/chat/message", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
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
    // stream ต้องใช้ fetch ตรง ๆ เพื่อเข้าถึง res.body, แต่ยังคงแนบ X-API-KEY เหมือนเดิม
    const res = await fetch(`${BASE_URL}/api/chat/message/stream`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-API-KEY": API_KEY,
      },
      body: JSON.stringify(payload),
    });

    if (!res.ok || !res.body) {
      const text = await res.text();
      throw new Error(text || `HTTP ${res.status}`);
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split("\n");
      buffer = lines.pop() ?? "";

      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        try {
          const payload = JSON.parse(trimmed);
          if (payload.type === "chunk") {
            if (callbacks.onStart) {
              callbacks.onStart({
                conversation_id: payload.conversation_id,
                assistant_message_id: payload.assistant_message_id,
                user_message_id: payload.user_message_id,
              });
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
          console.warn("Failed parse stream line", err, trimmed);
        }
      }
    }
  },

  async rateMessage(
    messageId: number,
    payload: { score: number },
  ): Promise<{ message: Message }> {
    const res = await ChatFetch<{ message: Message }>(
      `/api/chat/messages/${messageId}/rate`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      },
    );
    return res;
  },

  async summarizeConversation(conversationId: number) {
    return ChatFetch(`/api/chat/conversations/${conversationId}/summarize`, {
      method: "POST",
    });
  },
};
