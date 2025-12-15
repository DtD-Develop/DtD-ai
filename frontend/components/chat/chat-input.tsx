"use client";

import { FormEvent, KeyboardEvent, useState } from "react";

type Props = {
  disabled?: boolean;
  onSend: (text: string) => Promise<void> | void;
};

export function ChatInput({ disabled, onSend }: Props) {
  const [value, setValue] = useState("");

  const handleSubmit = async (e?: FormEvent) => {
    if (e) e.preventDefault();

    const text = value.trim();
    if (!text || disabled) return;
    setValue("");
    await onSend(text);
  };

  const handleKeyDown = async (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      await handleSubmit();
    }
  };

  const handleClear = () => {
    if (disabled) return;
    setValue("");
  };

  const hasText = value.trim().length > 0;

  return (
    <form
      onSubmit={handleSubmit}
      className="border rounded-xl p-2 flex flex-col gap-2 bg-background"
    >
      <textarea
        rows={2}
        className="w-full resize-none bg-transparent text-sm outline-none"
        placeholder="ลองถามอะไรก็ได้ หรือสลับเป็นโหมด Train เพื่อเพิ่มความรู้ให้ระบบ! (Enter เพื่อส่ง, Shift+Enter ขึ้นบรรทัดใหม่)"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={handleKeyDown}
        disabled={disabled}
      />

      <div className="flex justify-between items-center gap-2 text-[11px] text-muted-foreground">
        <span className="hidden sm:inline">
          Enter เพื่อส่ง • Shift+Enter ขึ้นบรรทัดใหม่
        </span>
        <div className="flex items-center gap-2 ml-auto">
          {hasText && (
            <button
              type="button"
              onClick={handleClear}
              disabled={disabled}
              className="px-3 py-1 rounded-lg border text-xs disabled:opacity-60"
            >
              ล้าง
            </button>
          )}
          <button
            type="submit"
            disabled={disabled || !hasText}
            className="px-4 py-1.5 rounded-lg bg-blue-500 text-white text-sm font-medium disabled:opacity-60"
          >
            ส่ง
          </button>
        </div>
      </div>
    </form>
  );
}
