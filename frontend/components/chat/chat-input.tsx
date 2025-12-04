"use client";

import { FormEvent, useState } from "react";

type Props = {
  disabled?: boolean;
  onSend: (text: string) => Promise<void> | void;
};

export function ChatInput({ disabled, onSend }: Props) {
  const [value, setValue] = useState("");

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    const text = value.trim();
    if (!text || disabled) return;
    setValue("");
    await onSend(text);
  };

  return (
    <form
      onSubmit={handleSubmit}
      className="border rounded-xl p-2 flex flex-col gap-2 bg-background"
    >
      <textarea
        rows={2}
        className="w-full resize-none bg-transparent text-sm outline-none"
        placeholder="ลองถามอะไรก็ได้ หรือสลับเป็นโหมด Train เพื่อเพิ่มความรู้ให้ระบบ!"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        disabled={disabled}
      />
      <div className="flex justify-end">
        <button
          type="submit"
          disabled={disabled}
          className="px-4 py-1.5 rounded-lg bg-blue-500 text-white text-sm font-medium disabled:opacity-60"
        >
          ส่ง
        </button>
      </div>
    </form>
  );
}
