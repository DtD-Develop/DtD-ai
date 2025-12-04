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
  const [pendingMode, setPendingMode] = useState<Mode>("test"); // สำหรับ New Chat ยังไม่มีห้อง
  const messagesEndRef = useRef<HTMLDivElement | null>(null);

  // โหลด conversations ตอนเปิดหน้า
  useEffect(() => {
    refreshConversations();
  }, []);

  useEffect(() => {
    if (activeId != null) {
      loadConversation(activeId);
    } else {
      setActiveConv(null);
    }
  }, [activeId]);

  useEffect(() => {
    // auto scroll to bottom เมื่อ messages เปลี่ยน
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: "smooth" });
    }
  }, [activeConv?.messages?.length]);

  const currentMode: Mode = useMemo(() => {
    if (activeConv) return activeConv.mode;
    return pendingMode;
  }, [activeConv, pendingMode]);

  async function refreshConversations() {
    setLoadingConvs(true);
    setError(null);
    try {
      const data = await chatApi.getConversations();
      setConversations(data);
      // ถ้ายังไม่มี active เลย แต่มีห้องอยู่ ให้เลือกอันแรก
      if (data.length > 0 && activeId === null) {
        setActiveId(data[0].id);
      }
    } catch (e: any) {
      setError(e.message || "โหลดรายชื่อห้องไม่สำเร็จ");
    } finally {
      setLoadingConvs(false);
    }
  }

  async function loadConversation(id: number) {
    setLoadingMessages(true);
    setError(null);
    try {
      const data = await chatApi.getConversation(id);
      setActiveConv(data);
    } catch (e: any) {
      setError(e.message || "โหลดข้อความไม่สำเร็จ");
    } finally {
      setLoadingMessages(false);
    }
  }

  function handleNewChat() {
    setActiveId(null);
    setActiveConv(null);
    setPendingMode("test"); // ตั้ง default เป็น test หรือจะจำ mode ล่าสุดก็ได้
  }

  async function handleDeleteConversation(id: number) {
    if (!confirm("ต้องการลบห้องแชทนี้จริงไหม?")) return;
    try {
      await chatApi.deleteConversation(id);
      const next = conversations.filter((c) => c.id !== id);
      setConversations(next);
      if (activeId === id) {
        setActiveId(null);
        setActiveConv(null);
      }
    } catch (e: any) {
      alert(e.message || "ลบไม่ได้");
    }
  }

  async function handleModeChange(newMode: Mode) {
    if (activeConv) {
      try {
        const updated = await chatApi.updateConversation(activeConv.id, {
          mode: newMode,
        });
        // update state
        setActiveConv({ ...activeConv, mode: updated.mode });
        setConversations((prev) =>
          prev.map((c) =>
            c.id === updated.id ? { ...c, mode: updated.mode } : c,
          ),
        );
      } catch (e: any) {
        alert(e.message || "เปลี่ยนโหมดไม่สำเร็จ");
      }
    } else {
      // ยังไม่มีห้อง (New Chat) → เปลี่ยน pending mode
      setPendingMode(newMode);
    }
  }

  async function handleSend(text: string) {
    setSending(true);
    setError(null);
    try {
      const payload: {
        conversation_id?: number | null;
        message: string;
        mode?: Mode;
      } = {
        message: text,
      };

      if (activeConv?.id) {
        payload.conversation_id = activeConv.id;
      }
      // ถ้าเป็นห้องใหม่ → ต้องส่ง mode ไปให้ backend สร้างห้อง
      if (!activeConv) {
        payload.mode = currentMode;
      }

      const res = await chatApi.sendMessage(payload);

      // ถ้าเป็นห้องใหม่ → set active id + โหลดใหม่
      if (!activeConv) {
        setActiveId(res.conversation_id);
        await refreshConversations();
        await loadConversation(res.conversation_id);
      } else {
        // ถ้าห้องเดิม → append messages แบบเร็ว ๆ
        const newUser: Message = {
          id: res.user_message_id,
          conversation_id: activeConv.id,
          role: "user",
          content: text,
          score: null,
          is_training: false,
          meta: null,
          rated_at: null,
        };
        const newBot: Message = {
          id: res.assistant_message_id,
          conversation_id: activeConv.id,
          role: "assistant",
          content: res.answer,
          score: null,
          is_training: false,
          meta: null,
          rated_at: null,
        };

        setActiveConv({
          ...activeConv,
          messages: [...(activeConv.messages || []), newUser, newBot],
        });
        // update last_message_at สั้น ๆ
        setConversations((prev) =>
          prev.map((c) =>
            c.id === activeConv.id
              ? { ...c, last_message_at: new Date().toISOString() }
              : c,
          ),
        );
      }
    } catch (e: any) {
      setError(e.message || "ส่งข้อความไม่สำเร็จ");
    } finally {
      setSending(false);
    }
  }

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
      alert(e.message || "ให้คะแนนไม่สำเร็จ");
    }
  }

  const showEmptyState = !activeConv && !loadingMessages;

  return (
    <div className="grid grid-cols-1 md:grid-cols-[260px,minmax(0,1fr)] h-[calc(100vh-4rem)] border rounded-xl overflow-hidden bg-card">
      {/* Left: conversation list */}
      <div className="hidden md:block">
        <ConversationList
          conversations={conversations}
          activeId={activeId}
          onSelect={(id) => setActiveId(id)}
          onDelete={handleDeleteConversation}
          onNewChat={handleNewChat}
        />
      </div>

      {/* Right: main chat area */}
      <div className="flex flex-col h-full">
        {/* Mobile new chat + conv list toggle (simplified: only New Chat now) */}
        <div className="md:hidden flex items-center justify-between px-3 py-2 border-b">
          <button
            onClick={handleNewChat}
            className="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs hover:bg-accent"
          >
            New Chat
          </button>
          {/* โหมด */}
          <ModeToggle mode={currentMode} onChange={handleModeChange} />
        </div>

        {/* Header */}
        <div className="hidden md:flex items-center justify-between px-4 py-3 border-b">
          <div className="flex flex-col">
            <h1 className="text-sm font-semibold">
              {activeConv?.title || "New Chat"}
            </h1>
            <p className="text-xs text-muted-foreground">
              {currentMode === "train"
                ? "โหมด Train: ให้คะแนนคำตอบที่ดีเพื่อเพิ่มเข้า Knowledge Base"
                : "โหมด Test: ลองถาม-ตอบกับ AI โดยไม่บันทึกเข้า KB"}
            </p>
          </div>
          <ModeToggle mode={currentMode} onChange={handleModeChange} />
        </div>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto px-4 py-3">
          {error && <div className="mb-2 text-xs text-red-500">{error}</div>}

          {loadingMessages && (
            <p className="text-xs text-muted-foreground">กำลังโหลดข้อความ…</p>
          )}

          {showEmptyState && !loadingMessages && (
            <div className="h-full flex flex-col items-center justify-center text-center text-sm text-muted-foreground px-6">
              <p className="font-medium mb-1">
                เริ่มต้นคุยกับ DtD-AI ได้เลย ✨
              </p>
              <p className="text-xs">
                ลองถามอะไรก็ได้ หรือสลับเป็นโหมด Train เพื่อเพิ่มความรู้ให้ระบบ!
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
              กำลังรอคำตอบจาก AI…
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
