// lib/api.ts
export type Conversation = {
  id: number;
  user_id: string;
  title: string;
  mode: "test" | "train";
  is_title_generated: boolean;
  last_message_at: string | null;
  created_at?: string;
  updated_at?: string;
};

export type Message = {
  id: number;
  conversation_id: number;
  role: "user" | "assistant";
  content: string;
  score: number | null;
  is_training: boolean;
  meta: any;
  rated_at: string | null;
  created_at?: string;
  updated_at?: string;
};

export type ConversationWithMessages = Conversation & {
  messages: Message[];
};

const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || "";
const API_KEY = process.env.NEXT_PUBLIC_API_KEY || "";

async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const res = await fetch(`${BASE_URL}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      "X-API-KEY": API_KEY,
      "X-API-TEST": "Test",
      ...(options.headers || {}),
    },
    cache: "no-store",
  });
  console.log(API_KEY);
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`API error ${res.status}: ${text}`);
  }

  return res.json();
}

export const chatApi = {
  getConversations(): Promise<Conversation[]> {
    return apiFetch<Conversation[]>("/api/chat/conversations");
  },

  getConversation(id: number): Promise<ConversationWithMessages> {
    return apiFetch<ConversationWithMessages>(`/api/chat/conversations/${id}`);
  },

  updateConversation(
    id: number,
    data: Partial<Pick<Conversation, "title" | "mode">>,
  ): Promise<Conversation> {
    return apiFetch<Conversation>(`/api/chat/conversations/${id}`, {
      method: "PATCH",
      body: JSON.stringify(data),
    });
  },

  deleteConversation(id: number): Promise<{ status: string }> {
    return apiFetch<{ status: string }>(`/api/chat/conversations/${id}`, {
      method: "DELETE",
    });
  },

  sendMessage(payload: {
    conversation_id?: number | null;
    message: string;
    mode?: "test" | "train";
  }): Promise<{
    conversation_id: number;
    conversation_mode: "test" | "train";
    user_message_id: number;
    assistant_message_id: number;
    answer: string;
    kb_hits: any[];
  }> {
    return apiFetch("/api/chat/message", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  rateMessage(
    messageId: number,
    data: { score: number; comment?: string },
  ): Promise<{
    status: string;
    message: Message;
  }> {
    return apiFetch(`/api/chat/messages/${messageId}/rate`, {
      method: "POST",
      body: JSON.stringify(data),
    });
  },
};
