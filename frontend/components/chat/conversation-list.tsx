"use client";

import { Conversation } from "@/lib/api";
import { Plus, Trash2 } from "lucide-react";

type Props = {
  conversations: Conversation[];
  activeId: number | null;
  onSelect: (id: number | null) => void;
  onDelete: (id: number) => Promise<void> | void;
  onNewChat: () => void;
};

export function ConversationList({
  conversations,
  activeId,
  onSelect,
  onDelete,
  onNewChat,
}: Props) {
  return (
    <div className="flex flex-col h-full border-r bg-background/60">
      <div className="flex items-center justify-between px-3 py-2 border-b">
        <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Conversations
        </span>
        <button
          className="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-xs hover:bg-accent"
          onClick={onNewChat}
        >
          <Plus className="w-3 h-3" />
          New
        </button>
      </div>
      <div className="flex-1 overflow-y-auto">
        {conversations.length === 0 && (
          <p className="text-xs text-muted-foreground p-3">
            ยังไม่มีบทสนทนา ลองเริ่ม New Chat ด้านบน
          </p>
        )}
        {conversations.map((c) => {
          const isActive = c.id === activeId;
          return (
            <div
              key={c.id}
              className={`group flex items-center justify-between px-3 py-2 text-sm cursor-pointer border-b border-border/50
                ${isActive ? "bg-accent/60" : "hover:bg-accent/30"}`}
              onClick={() => onSelect(c.id)}
            >
              <div className="flex flex-col min-w-0">
                <span className="truncate font-medium text-xs">
                  {c.title || "New Chat"}
                </span>
                <span className="text-[10px] text-muted-foreground">
                  {c.mode === "train" ? "Train ⭐" : "Test"}
                </span>
              </div>
              <button
                className="opacity-0 group-hover:opacity-100 transition-opacity p-1"
                onClick={(e) => {
                  e.stopPropagation();
                  onDelete(c.id);
                }}
              >
                <Trash2 className="w-3 h-3 text-muted-foreground hover:text-destructive" />
              </button>
            </div>
          );
        })}
      </div>
    </div>
  );
}
