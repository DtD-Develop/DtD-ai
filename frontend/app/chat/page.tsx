"use client";

import React, { useEffect, useMemo, useRef, useState } from "react";
import { useSimpleToast } from "@/components/ui/simple-toast";

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
  const { showToast } = useSimpleToast();
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
  const [streamingMsgId, setStreamingMsgId] = useState<number | null>(null);

  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const [autoScroll, setAutoScroll] = useState(true);

  /* Load conversation list */
  useEffect(() => {
    refreshConversations();
  }, []);

  /* Load messages when conversation changes */
  useEffect(() => {
    if (activeId != null) loadConversation(activeId);
    else setActiveConv(null);
  }, [activeId]);

  /* Auto-scroll when new messages come */
  useEffect(() => {
    if (!activeConv?.messages) return;
    if (!autoScroll) return;

    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [activeConv?.messages]);

  /* Determine chat mode */
  const currentMode: Mode = useMemo(() => {
    if (activeConv) return activeConv.mode;
    return pendingMode;
  }, [activeConv, pendingMode]);

  /* Load conversation list */
  async function refreshConversations() {
    setLoadingConvs(true);
    setError(null);
    try {
      const list = await chatApi.getConversations();
      setConversations(list);

      if (list.length > 0 && activeId === null) setActiveId(list[0].id);
    } catch (e: any) {
      setError(e.message ?? "Failed to load conversations.");
    } finally {
      setLoadingConvs(false);
    }
  }

  /* Load messages for selected conversation */
  async function loadConversation(id: number) {
    setError(null);
    setLoadingMessages(true);
    try {
      const data = await chatApi.getConversation(id);
      setActiveConv(data);
    } catch (e: any) {
      setError(e.message ?? "Failed to load messages.");
    } finally {
      setLoadingMessages(false);
    }
  }

  /* New conversation */
  function handleNewChat() {
    setActiveId(null);
    setActiveConv(null);
    setPendingMode("test");
  }

  /* Delete conversation */
  async function handleDeleteConversation(id: number) {
    if (!confirm("Delete this conversation?")) return;

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

  /* Change conversation mode */
  async function handleModeChange(newMode: Mode) {
    if (!activeConv) {
      setPendingMode(newMode);
      return;
    }

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
  }

  /* SEND MESSAGE (Streaming) */
  async function handleSend(text: string) {
    setSending(true);
    setError(null);
    setStreamingMsgId(null);

    try {
      let localAssistantId: number | null = null; // ⭐ FIX KEY BUG

      const payload: {
        conversation_id?: number;
        message: string;
        mode?: Mode;
      } = { message: text };

      if (activeConv?.id) payload.conversation_id = activeConv.id;
      else payload.mode = currentMode;

      /* Optimistic user bubble */
      if (activeConv) {
        const optimisticUser: Message = {
          id: Date.now(),
          conversation_id: activeConv.id,
          role: "user",
          content: text,
          score: null,
          is_training: currentMode === "train",
          meta: null,
          rated_at: null,
        };

        setActiveConv({
          ...activeConv,
          messages: [...activeConv.messages, optimisticUser],
        });
      }

      /* Stream from backend */
      await chatApi.sendMessageStream(payload, {
        onStart: (info) => {
          localAssistantId = info.assistant_message_id;
          setStreamingMsgId(info.assistant_message_id);

          if (!activeConv) {
            setActiveId(info.conversation_id);
            (async () => {
              await refreshConversations();
              await loadConversation(info.conversation_id);
            })();
            return;
          }

          const placeholder: Message = {
            id: info.assistant_message_id,
            conversation_id: activeConv.id!,
            role: "assistant",
            content: "",
            score: null,
            is_training: currentMode === "train",
            meta: null,
            rated_at: null,
          };

          setActiveConv((prev) =>
            prev
              ? {
                  ...prev,
                  messages: [...prev.messages, placeholder],
                }
              : prev,
          );
        },

        onChunk: (chunk) => {
          setActiveConv((prev) => {
            if (!prev) return prev;

            return {
              ...prev,
              messages: prev.messages.map((m) =>
                m.id === localAssistantId
                  ? { ...m, content: (m.content || "") + chunk }
                  : m,
              ),
            };
          });
        },

        onDone: (final) => {
          setStreamingMsgId(null);

          setActiveConv((prev) => {
            if (!prev) return prev;

            return {
              ...prev,
              messages: prev.messages.map((m) =>
                m.id === final.assistant_message_id
                  ? {
                      ...m,
                      content: final.answer,
                      score: final.score ?? null,
                    }
                  : m,
              ),
            };
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

  /* Rating stars */
  function handleRate(message: Message, score: number) {
    chatApi
      .rateMessage(message.id, { score })
      .then((res) => {
        const updated = res.message;
        if (!activeConv) return;
        // update message in active conversation
        const nextConv: ConversationWithMessages = {
          ...activeConv,
          messages: activeConv.messages.map((m) =>
            m.id === updated.id ? { ...m, ...updated } : m,
          ),
        };

        setActiveConv(nextConv);

        // If we are in TRAIN mode, also send feedback to /api/train/feedback
        if (currentMode === "train") {
          const msgs = nextConv.messages;
          const idx = msgs.findIndex((m) => m.id === message.id);

          let question: string | null = null;
          if (idx > 0) {
            const prev = msgs[idx - 1];
            if (prev.role === "user") {
              question = prev.content;
            }
          }

          const answer = message.content;
          chatApi
            .sendTrainFeedback({
              question,
              answer,
              score,

              conversation_id: nextConv.id,
              message_id: message.id,
            })
            .then(() => {
              showToast("คำตอบนี้ถูกใช้ปรับปรุง Knowledge Base แล้ว", {
                variant: "success",
              });
              // mark message as trained in UI via meta flag
              setActiveConv((conv) => {
                if (!conv) return conv;
                return {
                  ...conv,
                  messages: conv.messages.map((m) =>
                    m.id === message.id
                      ? {
                          ...m,
                          meta: {
                            ...(m.meta || {}),
                            trained: true,
                          },
                        }
                      : m,
                  ),
                };
              });
            })
            .catch((err: any) => {
              console.warn("Failed to send train feedback:", err?.message);
            });
        }
      })
      .catch((e) => alert(e.message ?? "Failed to rate."));
  }

  /* scroll tracking */
  function handleScroll(e: React.UIEvent<HTMLDivElement>) {
    const el = e.currentTarget;
    const nearBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 80;
    setAutoScroll(nearBottom);
  }

  const showEmptyState = !activeConv && !loadingMessages;

  return (
    <div className="grid grid-cols-1 md:grid-cols-[260px,minmax(0,1fr)] h-[calc(100vh-4rem)] border rounded-xl overflow-hidden bg-card">
      {/* LEFT */}
      <div className="hidden md:block">
        <ConversationList
          conversations={conversations}
          activeId={activeId}
          onSelect={setActiveId}
          onDelete={handleDeleteConversation}
          onNewChat={handleNewChat}
        />
      </div>

      {/* RIGHT */}
      <div className="flex flex-col h-full">
        {/* HEADER */}
        <div className="hidden md:flex items-center justify-between px-4 py-3 border-b">
          <div>
            <h1 className="text-sm font-semibold">
              {activeConv?.title || "New Chat"}
            </h1>
            <p className="text-xs text-muted-foreground">
              {currentMode === "train"
                ? "Train Mode: Rate answers to improve knowledge."
                : "Test Mode: Ask freely without saving to knowledge."}
            </p>
          </div>
          <ModeToggle mode={currentMode} onChange={handleModeChange} />
        </div>

        {/* MESSAGES */}
        <div
          className="flex-1 overflow-y-auto px-4 py-3"
          onScroll={handleScroll}
        >
          {error && <div className="text-xs text-red-500 mb-2">{error}</div>}

          {loadingMessages && (
            <p className="text-xs text-muted-foreground">Loading messages…</p>
          )}

          {showEmptyState && (
            <div className="h-full flex flex-col items-center justify-center text-center text-sm text-muted-foreground">
              <p className="font-medium mb-1">Start a conversation ✨</p>
              <p className="text-xs">Ask anything or switch to Train Mode.</p>
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

        {/* INPUT */}
        <div className="px-4 py-3 border-t">
          <ChatInput disabled={sending} onSend={handleSend} />
          {sending && (
            <p className="text-[11px] text-muted-foreground mt-1">
              Waiting for AI response…
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
