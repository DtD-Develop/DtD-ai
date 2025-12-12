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

// ENV
const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || "";
const API_KEY = process.env.NEXT_PUBLIC_API_KEY || "";

// helper
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
    return ChatFetch("/api/chat/conversations", { method: "GET" });
  },

  async getConversation(id: number): Promise<ConversationWithMessages> {
    return ChatFetch(`/api/chat/conversations/${id}`, { method: "GET" });
  },

  async deleteConversation(id: number) {
    return ChatFetch(`/api/chat/conversations/${id}`, { method: "DELETE" });
  },

  async updateConversation(id: number, payload: any): Promise<Conversation> {
    return ChatFetch(`/api/chat/conversations/${id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
  },

  async sendMessage(payload: {
    conversation_id?: number;
    message: string;
    mode?: "test" | "train";
  }): Promise<SendMessageResponse> {
    return ChatFetch("/api/chat/message", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
  },

  /* ---------------------------------------------------------
   * STREAMING VERSION
   * --------------------------------------------------------- */
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

        let json: any;
        try {
          json = JSON.parse(trimmed);
        } catch {
          console.warn("Invalid JSON from stream:", trimmed);
          continue;
        }

        const event = json.event;

        if (event === "start") {
          callbacks.onStart?.({
            conversation_id: json.conversation_id,
            assistant_message_id: json.assistant_message_id,
            user_message_id: json.user_message_id,
          });
          continue;
        }

        if (event === "chunk") {
          callbacks.onChunk?.(json.chunk);
          continue;
        }

        if (event === "done") {
          callbacks.onDone?.({
            conversation_id: json.conversation_id,
            assistant_message_id: json.assistant_message_id,
            answer: json.answer,
            score: json.score ?? null,
          });
          continue;
        }
      }
    }
  },

  /* ---------------------------------------------------------
   * RATE MESSAGE
   * --------------------------------------------------------- */
  async rateMessage(
    messageId: number,
    payload: { score: number },
  ): Promise<{ message: Message }> {
    return ChatFetch(`/api/chat/messages/${messageId}/rate`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
  },

  async summarizeConversation(conversationId: number) {
    return ChatFetch(`/api/chat/conversations/${conversationId}/summarize`, {
      method: "POST",
    });
  },
};
