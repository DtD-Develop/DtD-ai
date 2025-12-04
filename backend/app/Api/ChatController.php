<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\QueryService;
use App\Jobs\PromoteChatMessageToKbJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(protected QueryService $queryService) {}

    /**
     * GET /api/chat/conversations
     * list ห้องของ api_key ปัจจุบัน
     */
    public function index(Request $request)
    {
        $apiKey = $request->attributes->get("api_key");

        $convs = Conversation::where("user_id", $apiKey)
            ->orderByDesc("last_message_at")
            ->orderByDesc("id")
            ->get();

        return response()->json($convs);
    }

    /**
     * POST /api/chat/conversations
     * สร้างห้องใหม่
     */
    public function storeConversation(Request $request)
    {
        $apiKey = $request->attributes->get("api_key");

        $data = $request->validate([
            "title" => "nullable|string|max:255",
            "mode" => "nullable|in:test,train",
        ]);

        $conv = Conversation::create([
            "user_id" => $apiKey,
            "title" => $data["title"] ?? "New Chat",
            "mode" => $data["mode"] ?? "test",
            "last_message_at" => now(),
        ]);

        return response()->json($conv, 201);
    }

    /**
     * PATCH /api/chat/conversations/{conversation}
     * เปลี่ยน mode หรือ title
     */
    public function updateConversation(
        Request $request,
        Conversation $conversation,
    ) {
        $apiKey = $request->attributes->get("api_key");

        if ($conversation->user_id !== $apiKey) {
            return response()->json(["message" => "Forbidden"], 403);
        }

        $data = $request->validate([
            "title" => "nullable|string|max:255",
            "mode" => "nullable|in:test,train",
        ]);

        $conversation->fill($data);
        $conversation->save();

        return response()->json($conversation);
    }

    /**
     * DELETE /api/chat/conversations/{conversation}
     */
    public function destroyConversation(
        Request $request,
        Conversation $conversation,
    ) {
        $apiKey = $request->attributes->get("api_key");

        if ($conversation->user_id !== $apiKey) {
            return response()->json(["message" => "Forbidden"], 403);
        }

        $conversation->delete();

        return response()->json(["status" => "ok"]);
    }

    /**
     * GET /api/chat/conversations/{conversation}
     * โหลด messages ในห้อง
     */
    public function showConversation(
        Request $request,
        Conversation $conversation,
    ) {
        $apiKey = $request->attributes->get("api_key");

        if ($conversation->user_id !== $apiKey) {
            return response()->json(["message" => "Forbidden"], 403);
        }

        $conversation->load([
            "messages" => function ($q) {
                $q->orderBy("id");
            },
        ]);

        return response()->json($conversation);
    }

    /**
     * POST /api/chat/message
     * ส่งข้อความ + ได้คำตอบ + auto save ทั้งคู่
     */
    public function message(Request $request)
    {
        $apiKey = $request->attributes->get("api_key");

        $data = $request->validate([
            "conversation_id" => "nullable|integer|exists:conversations,id",
            "message" => "required|string",
            "mode" => "nullable|in:test,train",
        ]);

        // 1) หา/สร้าง conversation
        $conversation = null;

        if (!empty($data["conversation_id"])) {
            $conversation = Conversation::findOrFail($data["conversation_id"]);

            if ($conversation->user_id !== $apiKey) {
                return response()->json(["message" => "Forbidden"], 403);
            }

            if (
                !empty($data["mode"]) &&
                $data["mode"] !== $conversation->mode
            ) {
                $conversation->mode = $data["mode"];
            }
        } else {
            $conversation = Conversation::create([
                "user_id" => $apiKey,
                // ตั้งชื่อเริ่มต้นจากประโยคแรกไปก่อน เผื่อ AI ช้าหรือพัง
                "title" => \Illuminate\Support\Str::limit($data["message"], 60),
                "mode" => $data["mode"] ?? "test",
                "last_message_at" => now(),
                "is_title_generated" => false,
            ]);

            // ยิง job ให้ AI ตั้งชื่อห้องที่ "ฉลาดขึ้น" แบบ async
            \App\Jobs\GenerateConversationTitleJob::dispatch($conversation->id);
        }

        // 2) เซฟ user message
        $userMsg = $conversation->messages()->create([
            "role" => "user",
            "content" => $data["message"],
        ]);

        // 3) เรียก QueryService ให้ตอบ
        $answer = $this->queryService->answer([
            "query" => $data["message"],
            "conversation_id" => $conversation->id,
        ]);

        // 4) เซฟ assistant message
        $assistantMsg = $conversation->messages()->create([
            "role" => "assistant",
            "content" => $answer["text"],
        ]);

        $conversation->last_message_at = now();
        $conversation->save();

        return response()->json([
            "conversation_id" => $conversation->id,
            "conversation_mode" => $conversation->mode,
            "user_message_id" => $userMsg->id,
            "assistant_message_id" => $assistantMsg->id,
            "answer" => $answer["text"],
            "kb_hits" => $answer["kb_hits"] ?? [],
        ]);
    }

    /**
     * POST /api/chat/messages/{message}/rate
     * ให้คะแนนคำตอบ (assistant message เท่านั้น)
     */
    public function rate(Request $request, Message $message)
    {
        $apiKey = $request->attributes->get("api_key");

        $message->load("conversation");

        if ($message->conversation?->user_id !== $apiKey) {
            return response()->json(["message" => "Forbidden"], 403);
        }

        if ($message->role !== "assistant") {
            return response()->json(
                ["message" => "Only assistant messages can be rated"],
                422,
            );
        }

        $data = $request->validate([
            "score" => "required|integer|min:1|max:5",
            "comment" => "nullable|string",
        ]);

        $message->score = $data["score"];
        $message->rated_at = now();

        $meta = $message->meta ?? [];
        $meta["comment"] = $data["comment"] ?? null;
        $message->meta = $meta;

        $message->save();

        // B) แก้คะแนนย้อนหลังได้ แต่ train แค่ครั้งแรกเท่านั้น
        // => เช็คว่า ยังไม่เคย is_training และ score >= threshold และ mode = train
        $threshold = config("dtd.train_min_score", 4);

        if (
            !$message->is_training &&
            $message->score >= $threshold &&
            $message->conversation?->mode === "train"
        ) {
            dispatch(new PromoteChatMessageToKbJob($message->id));
        }

        return response()->json([
            "status" => "ok",
            "message" => $message,
        ]);
    }
}
