"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import {
  chatApi,
  Conversation,
  ConversationWithMessages,
  Message,
} from "@/lib/api";
import { ConversationList } from "@/components/chat/conversation-list";
import { ChatBubble } from "@/components/chat/chat-bubble";
import { ChatInput } from "@/components/chat/chat-input";
import { ModeToggle } from "@/components/chat/mode-toggle";
import { RatingStars } from "@/components/chat/rating-stars";

type Mode = "test" | "train";

export default function ChatPage() {
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [activeConv, setActiveConv] = useState<ConversationWithMessages | null>(
    null,
  );
  const [loadingConvs, setLoadingConvs] = useState(false);
  const [loadingMessages, setLoadingMessages] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pendingMode, setPendingMode] = useState<Mode>("test");

  const messagesEndRef = useRef<HTMLDivElement | null>(null);

  /* -----------------------------------------------------------------------
   * Load conversations list on mount
   * ----------------------------------------------------------------------- */
  useEffect(() => {
    refreshConversations();
  }, []);

  /* -----------------------------------------------------------------------
   * Load selected conversation messages
   * ----------------------------------------------------------------------- */
  useEffect(() => {
    if (activeId != null) {
      loadConversation(activeId);
    } else {
      setActiveConv(null);
    }
  }, [activeId]);

  /* -----------------------------------------------------------------------
   * Auto scroll to bottom when messages update
   * ----------------------------------------------------------------------- */
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [activeConv?.messages?.length]);

  /* -----------------------------------------------------------------------
   * Determine current chat mode
   * ----------------------------------------------------------------------- */
  const currentMode: Mode = useMemo(() => {
    if (activeConv) return activeConv.mode;
    return pendingMode;
  }, [activeConv, pendingMode]);

  /* -----------------------------------------------------------------------
   * Load conversations list
   * ----------------------------------------------------------------------- */
  async function refreshConversations() {
    setLoadingConvs(true);
    setError(null);
    try {
      const data = await chatApi.getConversations();
      setConversations(data);

      if (data.length > 0 && activeId === null) {
        setActiveId(data[0].id);
      }
    } catch (e: any) {
      setError(e.message ?? "Failed to load conversation list.");
    } finally {
      setLoadingConvs(false);
    }
  }

  /* -----------------------------------------------------------------------
   * Load conversation detail
   * ----------------------------------------------------------------------- */
  async function loadConversation(id: number) {
    setLoadingMessages(true);
    setError(null);
    try {
      const data = await chatApi.getConversation(id);
      setActiveConv(data);
    } catch (e: any) {
      setError(e.message ?? "Failed to load messages.");
    } finally {
      setLoadingMessages(false);
    }
  }

  /* -----------------------------------------------------------------------
   * Create new chat
   * ----------------------------------------------------------------------- */
  function handleNewChat() {
    setActiveId(null);
    setActiveConv(null);
    setPendingMode("test"); // reset mode for new chat
  }

  /* -----------------------------------------------------------------------
   * Delete conversation
   * ----------------------------------------------------------------------- */
  async function handleDeleteConversation(id: number) {
    if (!confirm("Are you sure you want to delete this conversation?")) return;

    try {
      await chatApi.deleteConversation(id);
      const next = conversations.filter((c) => c.id !== id);

      setConversations(next);

      if (activeId === id) {
        setActiveId(null);
        setActiveConv(null);
      }
    } catch (e: any) {
      alert(e.message ?? "Failed to delete conversation.");
    }
  }

  /* -----------------------------------------------------------------------
   * Change test/train mode
   * ----------------------------------------------------------------------- */
  async function handleModeChange(newMode: Mode) {
    if (activeConv) {
      try {
        const updated = await chatApi.updateConversation(activeConv.id, {
          mode: newMode,
        });

        setActiveConv({ ...activeConv, mode: updated.mode });

        setConversations((prev) =>
          prev.map((c) =>
            c.id === updated.id ? { ...c, mode: updated.mode } : c,
          ),
        );
      } catch (e: any) {
        alert(e.message ?? "Failed to switch mode.");
      }
    } else {
      setPendingMode(newMode);
    }
  }

  /* -----------------------------------------------------------------------
   * Send message
   * ----------------------------------------------------------------------- */
  async function handleSend(text: string) {
    setSending(true);
    setError(null);

    try {
      const payload: {
        conversation_id?: number;
        message: string;
        mode?: Mode;
      } = { message: text };

      // If conversation exists → continue conversation
      if (activeConv?.id) {
        payload.conversation_id = activeConv.id;
      }

      // If new chat → must send mode to backend
      if (!activeConv) {
        payload.mode = currentMode;
      }

      const res = await chatApi.sendMessage(payload);

      /* ---------------------------------------------------------
       * New conversation created
       * --------------------------------------------------------- */
      if (!activeConv) {
        setActiveId(res.conversation_id);
        await refreshConversations();
        await loadConversation(res.conversation_id);
        return;
      }

      /* ---------------------------------------------------------
       * Existing conversation → append new messages
       * --------------------------------------------------------- */
      const newUser: Message = {
        id: res.user_message_id,
        conversation_id: activeConv.id,
        role: "user",
        content: text,
        score: null,
        is_training: currentMode === "train",
        meta: null,
        rated_at: null,
      };

      const newBot: Message = {
        id: res.assistant_message_id,
        conversation_id: activeConv.id,
        role: "assistant",
        content: res.answer,
        score: res.score,
        is_training: currentMode === "train",
        meta: null,
        rated_at: null,
      };

      setActiveConv({
        ...activeConv,
        messages: [...activeConv.messages, newUser, newBot],
      });

      setConversations((prev) =>
        prev.map((c) =>
          c.id === activeConv.id
            ? { ...c, last_message_at: new Date().toISOString() }
            : c,
        ),
      );
    } catch (e: any) {
      setError(e.message ?? "Failed to send message.");
    } finally {
      setSending(false);
    }
  }

  /* -----------------------------------------------------------------------
   * Rate answer (train mode)
   * ----------------------------------------------------------------------- */
  async function handleRate(message: Message, score: number) {
    try {
      const res = await chatApi.rateMessage(message.id, { score });
      const updated = res.message;

      if (!activeConv) return;

      setActiveConv({
        ...activeConv,
        messages: activeConv.messages.map((m) =>
          m.id === updated.id ? { ...m, ...updated } : m,
        ),
      });
    } catch (e: any) {
      alert(e.message ?? "Failed to rate answer.");
    }
  }

  /* -----------------------------------------------------------------------
   * Should show empty UI state?
   * ----------------------------------------------------------------------- */
  const showEmptyState = !activeConv && !loadingMessages;

  return (
    <div
      className="grid grid-cols-1 md:grid-cols-[260px,minmax(0,1fr)]
      h-[calc(100vh-4rem)] border rounded-xl overflow-hidden bg-card"
    >
      {/* LEFT SIDE: Conversations */}
      <div className="hidden md:block">
        <ConversationList
          conversations={conversations}
          activeId={activeId}
          onSelect={setActiveId}
          onDelete={handleDeleteConversation}
          onNewChat={handleNewChat}
        />
      </div>

      {/* RIGHT SIDE: Chat Area */}
      <div className="flex flex-col h-full">
        {/* Mobile header */}
        <div className="md:hidden flex items-center justify-between px-3 py-2 border-b">
          <button
            onClick={handleNewChat}
            className="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs hover:bg-accent"
          >
            New Chat
          </button>
          <ModeToggle mode={currentMode} onChange={handleModeChange} />
        </div>

        {/* Desktop header */}
        <div className="hidden md:flex items-center justify-between px-4 py-3 border-b">
          <div className="flex flex-col">
            <h1 className="text-sm font-semibold">
              {activeConv?.title || "New Chat"}
            </h1>
            <p className="text-xs text-muted-foreground">
              {currentMode === "train"
                ? "Train Mode: Improve AI knowledge by rating good answers."
                : "Test Mode: Try asking questions without updating the knowledge base."}
            </p>
          </div>
          <ModeToggle mode={currentMode} onChange={handleModeChange} />
        </div>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto px-4 py-3">
          {error && <div className="mb-2 text-xs text-red-500">{error}</div>}

          {loadingMessages && (
            <p className="text-xs text-muted-foreground">Loading messages…</p>
          )}

          {showEmptyState && (
            <div className="h-full flex flex-col items-center justify-center text-center text-sm text-muted-foreground px-6">
              <p className="font-medium mb-1">Start chatting with DtD-AI ✨</p>
              <p className="text-xs">
                Ask anything or switch to Train Mode to improve AI knowledge.
              </p>
            </div>
          )}

          {activeConv?.messages?.map((m) => (
            <ChatBubble key={m.id} message={m}>
              {m.role === "assistant" && currentMode === "train" && (
                <RatingStars
                  initialScore={m.score}
                  isTrained={m.is_training}
                  onRate={(score) => handleRate(m, score)}
                />
              )}
            </ChatBubble>
          ))}

          <div ref={messagesEndRef} />
        </div>

        {/* Input */}
        <div className="px-4 py-3 border-t">
          <ChatInput disabled={sending} onSend={handleSend} />
          {sending && (
            <p className="mt-1 text-[11px] text-muted-foreground">
              Waiting for AI response…
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
