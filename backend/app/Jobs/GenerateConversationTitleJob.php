<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateConversationTitleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $conversationId) {}

    public function handle(OllamaService $ollama): void
    {
        /** @var Conversation|null $conv */
        $conv = Conversation::with("messages")->find($this->conversationId);

        if (!$conv) {
            return;
        }

        // ถ้า user เปลี่ยนชื่อเองไปแล้ว ก็ไม่ต้องไปทับ
        if ($conv->is_title_generated) {
            return;
        }

        // เอาข้อความของ user 3–5 ข้อความแรกมารวมกัน
        $userTexts = $conv
            ->messages()
            ->where("role", "user")
            ->orderBy("id")
            ->take(5)
            ->pluck("content")
            ->toArray();

        if (empty($userTexts)) {
            return;
        }

        $joined = implode("\n", $userTexts);

        $prompt = <<<PROMPT
        จากข้อความด้านล่างนี้ ให้คุณสร้าง "ชื่อห้องสนทนา" ภาษาไทย/อังกฤษก็ได้ ให้:

        - สั้น ไม่เกิน 5 คำ
        - สรุปใจความหลักของสิ่งที่คุย
        - ไม่ต้องใส่คำว่า "แชท" หรือ "สนทนา"
        - ไม่ต้องใส่เครื่องหมายคำพูด

        ข้อความ:
        {$joined}
        PROMPT;

        $title = trim($ollama->generate($prompt));

        if ($title === "") {
            return;
        }

        $conv->title = $title;
        $conv->is_title_generated = true;
        $conv->save();
    }
}
