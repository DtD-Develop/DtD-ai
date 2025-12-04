<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\KbFile;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class PromoteChatMessageToKbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId) {}

    public function handle(OllamaService $ollama): void
    {
        /** @var Message $msg */
        $msg = Message::with([
            "conversation",
            "conversation.messages",
        ])->findOrFail($this->messageId);

        // กันไม่ให้ train ซ้ำ
        if ($msg->is_training || $msg->role !== "assistant") {
            return;
        }

        $conversation = $msg->conversation;
        if (!$conversation) {
            return;
        }

        // หา user message ก่อนหน้าอันนี้
        $userMsg = $conversation
            ->messages()
            ->where("role", "user")
            ->where("id", "<=", $msg->id)
            ->orderByDesc("id")
            ->first();

        $question = $userMsg?->content ?? "";
        $answer = $msg->content;

        // สร้าง prompt ให้ LLM สรุปเป็น knowledge
        $prompt = <<<PROMPT
        แปลง Q&A ด้านล่างให้เป็น "บทความความรู้" สั้น ๆ กระชับ เหมาะสำหรับเก็บใน Knowledge Base

        เงื่อนไข:
        - ไม่ต้องพูดถึงการถาม-ตอบ หรือคำว่าแชท
        - ไม่ต้องใส่ชื่อคน
        - เขียนเป็นเนื้อหาความรู้ตรง ๆ
        - ถ้ามีขั้นตอนหรือรายการ ให้จัดรูปแบบเป็นข้อ ๆ อ่านง่าย

        [คำถาม]
        {$question}

        [คำตอบ]
        {$answer}
        PROMPT;

        $kbText = $ollama->generate($prompt);

        if (!is_string($kbText) || trim($kbText) === "") {
            return;
        }

        $relativeDir = config("dtd.chat_train_dir", "kb-chat-train");
        $storagePath = $relativeDir . "/chat_" . $msg->id . ".txt";

        // เซฟไฟล์ลง storage/app/...
        $fullPathDir = storage_path("app/" . $relativeDir);
        if (!is_dir($fullPathDir)) {
            @mkdir($fullPathDir, 0775, true);
        }

        file_put_contents(storage_path("app/" . $storagePath), $kbText);

        // สร้าง KbFile record
        $kb = KbFile::create([
            "source" => "chat_train",
            "filename" => basename($storagePath),
            "original_name" =>
                "chat-train-" . $conversation->id . "-" . $msg->id . ".txt",
            "mime_type" => "text/plain",
            "size_bytes" => strlen($kbText),
            "storage_path" => $storagePath,
            "status" => "embedding", // หรือจะใช้ 'uploaded' + ParseKbFileJob ก็ได้
            "progress" => 80,
            "auto_tags" => null,
            "tags" => null,
        ]);

        // ถ้าคุณอยากใช้ pipeline เดิมแบบเต็มๆ:
        // dispatch(new ParseKbFileJob($kb->id));
        // แล้วให้ frontend มากด confirm embed เอง
        //
        // แต่ใน Train เราเอา embed เลยก็ได้:
        dispatch(new \App\Jobs\EmbedKbFileJob($kb->id));

        // mark ว่า message นี้ถูกใช้ train แล้ว
        $msg->is_training = true;
        $msg->save();
    }
}
