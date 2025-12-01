"use client";
import { useEffect, useState } from "react";

type Mode = "test" | "train" | "prod";

export default function ChatPage() {
  const [convId, setConvId] = useState<string | null>(null);
  const [userId, setUserId] = useState<string | null>(null);
  const [mode, setMode] = useState<Mode>("test");
  const [messages, setMessages] = useState<{ role: string; text: string }[]>(
    [],
  );
  const [query, setQuery] = useState("");

  useEffect(() => {
    const savedConv = localStorage.getItem("conversation_id");
    if (savedConv) setConvId(savedConv);

    let savedUser = localStorage.getItem("user_id");
    if (!savedUser) {
      savedUser = crypto.randomUUID?.() ?? `user_${Date.now()}`;
      localStorage.setItem("user_id", savedUser);
    }
    setUserId(savedUser);
  }, []);

  async function send() {
    if (!query.trim()) return;
    const res = await fetch(
      `${process.env.NEXT_PUBLIC_API_URL"}/api/query`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          query,
          conversation_id: convId ?? undefined,
          user_id: userId ?? undefined,
          mode,
        }),
      },
    );

    const data = await res.json();

    if (!convId && data.conversation_id) {
      localStorage.setItem("conversation_id", data.conversation_id);
      setConvId(data.conversation_id);
    }

    setMessages((prev) => [
      ...prev,
      { role: "user", text: query },
      { role: "assistant", text: data.answer ?? "(no answer)" },
    ]);
    setQuery("");
  }

  return (
    <div style={{ padding: 16 }}>
      <div style={{ marginBottom: 8 }}>
        Mode:{" "}
        <select value={mode} onChange={(e) => setMode(e.target.value as Mode)}>
          <option value="test">test</option>
          <option value="train">train</option>
          <option value="prod">prod</option>
        </select>
      </div>

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
    </div>
  );
}
