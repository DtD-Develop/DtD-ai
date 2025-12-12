"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import {
  chatApi,
  Conversation,
  ConversationWithMessages,
  Message,
  SendMessageResponse,
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

  const [autoScroll, setAutoScroll] = useState(true);

  /* ------------------------------------------------------------------ */
  /* Load Conversations List */
  /* ------------------------------------------------------------------ */
  useEffect(() => {
    refreshConversations();
  }, []);

  /* Load messages when selecting conversation */
  useEffect(() => {
    if (activeId != null) {
      loadConversation(activeId);
    } else {
      setActiveConv(null);
    }
  }, [activeId]);

  /* Auto scroll to bottom */
  useEffect(() => {
    const lastMsg = activeConv?.messages?.[activeConv.messages.length - 1];

    if (!lastMsg) return;

    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({
        behavior: "smooth",
        block: "end",
      });
    }
  }, [activeConv?.messages]);

  /* Determine active mode (test or train) */
  const currentMode: Mode = useMemo(() => {
    if (activeConv) return activeConv.mode;
    return pendingMode;
  }, [activeConv, pendingMode]);

  /* ------------------------------------------------------------------ */
  /* Fetch Conversations */
  /* ------------------------------------------------------------------ */
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
      setError(e.message ?? "Failed to load conversations.");
    } finally {
      setLoadingConvs(false);
    }
  }

  /* ------------------------------------------------------------------ */
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

  /* ------------------------------------------------------------------ */
  function handleNewChat() {
    setActiveId(null);
    setActiveConv(null);
    setPendingMode("test");
  }

  /* ------------------------------------------------------------------ */
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

  /* ------------------------------------------------------------------ */
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
        alert(e.message ?? "Failed to change mode.");
      }
    } else {
      setPendingMode(newMode);
    }
  }

  /* ------------------------------------------------------------------ */
  /* SEND MESSAGE + STREAMING SUPPORT */
  /* ------------------------------------------------------------------ */
  async function handleSend(text: string) {
    setSending(true);
    setError(null);

    try {
      const payload: {
        conversation_id?: number;
        message: string;
        mode?: Mode;
      } = { message: text };

      if (activeConv?.id) payload.conversation_id = activeConv.id;
      else payload.mode = currentMode; // for new chat

      let tempAssistantId: number | null = null;

      /* ---------------------------------------------------------
         Show user message immediately (local optimistic update)
      --------------------------------------------------------- */
      if (activeConv) {
        const userLocalMsg: Message = {
          id: Date.now(),
          role: "user",
          conversation_id: activeConv.id,
          content: text,
          score: null,
          is_training: currentMode === "train",
          meta: null,
          rated_at: null,
        };

        setActiveConv({
          ...activeConv,
          messages: [...activeConv.messages, userLocalMsg],
        });
      }

      /* ---------------------------------------------------------
         STREAMING MODE
      --------------------------------------------------------- */
      await chatApi.sendMessageStream(payload, {
        onStart: (info) => {
          tempAssistantId = info.assistant_message_id;

          if (!activeConv) {
            // new conversation created
            setActiveId(info.conversation_id);

            (async () => {
              await refreshConversations();
              await loadConversation(info.conversation_id);
            })();
            return;
          }

          // Append placeholder assistant bubble for streaming
          const placeholder: Message = {
            id: info.assistant_message_id,
            role: "assistant",
            conversation_id: activeConv.id!,
            content: "", // empty => ChatBubble shows typing indicator
            is_training: currentMode === "train",
            score: null,
            meta: null,
            rated_at: null,
          };

          setActiveConv((prev) =>
            prev
              ? { ...prev, messages: [...prev.messages, placeholder] }
              : prev,
          );
        },

        onChunk: (chunk) => {
          // Update assistant placeholder message
          setActiveConv((prev) => {
            if (!prev) return prev;

            const msgs = prev.messages.map((m) =>
              m.id === tempAssistantId
                ? { ...m, content: (m.content || "") + chunk }
                : m,
            );

            return { ...prev, messages: msgs };
          });
        },

        onDone: (final) => {
          setActiveConv((prev) => {
            if (!prev) return prev;

            const msgs = prev.messages.map((m) =>
              m.id === final.assistant_message_id
                ? { ...m, content: final.answer, score: final.score ?? null }
                : m,
            );

            return { ...prev, messages: msgs };
          });

          setConversations((prev) =>
            prev.map((c) =>
              c.id === final.conversation_id
                ? { ...c, last_message_at: new Date().toISOString() }
                : c,
            ),
          );
        },
      });
    } catch (e: any) {
      setError(e.message ?? "Failed to send message.");
    } finally {
      setSending(false);
    }
  }

  /* ------------------------------------------------------------------ */
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
      alert(e.message ?? "Failed to rate.");
    }
  }

  function handleScroll(e: React.UIEvent<HTMLDivElement>) {
    const el = e.currentTarget;
    const isBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 80;
    setAutoScroll(isBottom);
  }

  useEffect(() => {
    if (!autoScroll) return;

    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [activeConv?.messages]);

  /* ------------------------------------------------------------------ */
  const showEmptyState = !activeConv && !loadingMessages;

  /* ------------------------------------------------------------------ */
  /* RENDER UI */
  /* ------------------------------------------------------------------ */
  return (
    <div
      className="grid grid-cols-1 md:grid-cols-[260px,minmax(0,1fr)]
                 h-[calc(100vh-4rem)] border rounded-xl overflow-hidden bg-card"
    >
      {/* LEFT SIDE — Conversation List */}
      <div className="hidden md:block">
        <ConversationList
          conversations={conversations}
          activeId={activeId}
          onSelect={setActiveId}
          onDelete={handleDeleteConversation}
          onNewChat={handleNewChat}
        />
      </div>

      {/* RIGHT SIDE — Chat Area */}
      <div className="flex flex-col h-full">
        {/* Mobile Header */}
        <div className="md:hidden flex items-center justify-between px-3 py-2 border-b">
          <button
            onClick={handleNewChat}
            className="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs hover:bg-accent"
          >
            New Chat
          </button>

          <ModeToggle mode={currentMode} onChange={handleModeChange} />
        </div>

        {/* Desktop Header */}
        <div className="hidden md:flex items-center justify-between px-4 py-3 border-b">
          <div className="flex flex-col">
            <h1 className="text-sm font-semibold">
              {activeConv?.title || "New Chat"}
            </h1>
            <p className="text-xs text-muted-foreground">
              {currentMode === "train"
                ? "Train Mode: Rate good answers to improve the knowledge base."
                : "Test Mode: Ask questions without updating the knowledge base."}
            </p>
          </div>

          <ModeToggle mode={currentMode} onChange={handleModeChange} />
        </div>

        {/* MESSAGES */}
        <div
          className="flex-1 overflow-y-auto px-4 py-3"
          onScroll={handleScroll}
        >
          {error && <div className="mb-2 text-xs text-red-500">{error}</div>}

          {loadingMessages && (
            <p className="text-xs text-muted-foreground">Loading messages…</p>
          )}

          {showEmptyState && (
            <div className="h-full flex flex-col items-center justify-center text-center text-sm text-muted-foreground px-6">
              <p className="font-medium mb-1">
                Start a conversation with DtD-AI ✨
              </p>
              <p className="text-xs">
                Ask anything or switch to Train Mode to improve knowledge.
              </p>
            </div>
          )}

          {activeConv?.messages?.map((m) => (
            <ChatBubble
              key={m.id}
              message={m}
              isStreaming={m.id === streamingMsgId}
            >
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

        {/* CHAT INPUT */}
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
