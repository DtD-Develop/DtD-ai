import React, { useState } from "react";
import axios from "axios";

export default function ChatFooter({ mode, setMessages, conversationId }) {
  const [text, setText] = useState("");

  const send = async () => {
    if (!text.trim()) return;
    const question = text;
    setText("");
    // optimistically add message
    setMessages((msgs) => [...msgs, { role: "user", text: question }]);

    try {
      // call /api/llm/answer to get answer (backend will run retrieval + LLM)
      const res = await axios.post("/api/llm/answer", {
        question,
        mode,
        conversation_id: conversationId,
      });
      const answer = res.data.answer;
      const contexts = res.data.contexts;
      setMessages((msgs) => [
        ...msgs,
        { role: "assistant", text: answer, contexts },
      ]);
    } catch (e) {
      setMessages((msgs) => [
        ...msgs,
        { role: "assistant", text: "เกิดข้อผิดพลาดขอให้ลองอีกครั้ง" },
      ]);
    }
  };

  return (
    <div className="chat-footer">
      <textarea
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder="พิมพ์ข้อความ..."
      />
      <button onClick={send}>ส่ง</button>
    </div>
  );
}
