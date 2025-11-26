"use client";
import { useEffect, useState } from "react";

export default function ChatPage() {
  const [convId, setConvId] = useState<string | null>(null);
  const [messages, setMessages] = useState<{ role: string; text: string }[]>(
    [],
  );
  const [query, setQuery] = useState("");

  useEffect(() => {
    const saved = localStorage.getItem("conversation_id");
    if (saved) setConvId(saved);
  }, []);

  async function send() {
    if (!query.trim()) return;

    const res = await fetch("http://localhost:8000/api/query", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        query,
        conversation_id: convId ?? undefined,
      }),
    });

    const data = await res.json();

    if (!convId && data.conversation_id) {
      setConvId(data.conversation_id);
      localStorage.setItem("conversation_id", data.conversation_id);
    }

    setMessages((m) => [
      ...m,
      { role: "user", text: query },
      { role: "assistant", text: data.answer },
    ]);

    setQuery("");
  }

  return (
    <div>
      <div
        style={{
          minHeight: 200,
          border: "1px solid #ddd",
          padding: 12,
          marginBottom: 12,
        }}
      >
        {messages.map((m, i) => (
          <div
            key={i}
            style={{ textAlign: m.role === "user" ? "right" : "left" }}
          >
            <b>{m.role}</b>: {m.text}
          </div>
        ))}
      </div>
      <div>
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          style={{ width: "70%", padding: 8 }}
        />
        <button onClick={send} style={{ marginLeft: 8, padding: "8px 12px" }}>
          Send
        </button>
      </div>
    </div>
  );
}
