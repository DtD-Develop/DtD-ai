<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Message;

class ConversationMemoryService
{
    /**
     * แปลง memory ที่เก็บใน DB ให้เป็น system prompt
     * เช่น "ผู้ใช้คนนี้ชื่อ ที"
     */
    public function buildMemoryPrompt(Conversation $conversation): ?string
    {
        $memory = $conversation->memory ?? [];

        if (empty($memory)) {
            return null;
        }

        $lines = [];

        if (!empty($memory["user_name"])) {
            $lines[] = "ชื่อของผู้ใช้คือ {$memory["user_name"]}";
        }

        // เผื่ออนาคตเก็บอย่างอื่น เช่น language, company, preference
        foreach ($memory as $key => $value) {
            if ($key === "user_name") {
                continue;
            }
            $lines[] = "{$key}: {$value}";
        }

        if (empty($lines)) {
            return null;
        }

        return "ข้อมูลพื้นฐานเกี่ยวกับผู้ใช้:\n" . implode("\n", $lines);
    }

    /**
     * อ่านคู่ข้อความ user + assistant ล่าสุด แล้ว update memory
     * ตัวอย่าง: ถ้า user พิมพ์ "ฉันชื่อ ที" → memory['user_name'] = "ที"
     */
    public function updateMemoryFromExchange(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage,
    ): void {
        $memory = $conversation->memory ?? [];

        $text = $userMessage->content;

        // กรณีภาษาไทย: "ฉันชื่อ ที" / "ผมชื่อ ที" / "ดิฉันชื่อ ที"
        if (
            preg_match(
                "/(?:ฉันชื่อ|ผมชื่อ|ดิฉันชื่อ)\s*([^\s,.!?]+)/u",
                $text,
                $m,
            )
        ) {
            $memory["user_name"] = $m[1];
        }
        // กรณีอังกฤษ: "my name is Tee"
        elseif (preg_match("/my name is\s+([^\s,.!?]+)/i", $text, $m)) {
            $memory["user_name"] = $m[1];
        }

        // ถ้ามีการเปลี่ยน memory จริง ๆ ค่อยเซฟ
        if ($memory !== ($conversation->memory ?? [])) {
            $conversation->memory = $memory;
            $conversation->save();
        }
    }
}
