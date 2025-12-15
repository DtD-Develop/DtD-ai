import React from "react";
import axios from "axios";

export default function FeedbackButtons({
  question,
  answer,
  conversationId,
  messageId,
  onSaved,
}) {
  // quick 3-button scoring or slider
  const giveScore = async (score) => {
    try {
      await axios.post("/api/train/feedback", {
        question,
        answer,
        score,
        conversation_id: conversationId,
        message_id: messageId,
      });
      if (onSaved) onSaved(score);
    } catch (e) {
      console.error(e);
      alert("ไม่สามารถส่งคะแนนได้");
    }
  };

  return (
    <div className="feedback">
      <span>ให้คะแนนคำตอบ:</span>
      <button onClick={() => giveScore(2)}>ไม่ดี</button>
      <button onClick={() => giveScore(6)}>พอใช้</button>
      <button onClick={() => giveScore(9)}>ดีมาก (บันทึก)</button>
    </div>
  );
}
