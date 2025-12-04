<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;

class ChatController extends Controller
{
    /**
     * POST /api/chat/test
     * body: { "message": "..." }
     * ตอนนี้ให้ reuse QueryController ไปก่อน
     */
    public function test(Request $request, QueryController $queryController)
    {
        // คุณอาจเพิ่ม field พิเศษ เช่น debug = true เป็นต้น
        return $queryController->query($request);
    }

    /**
     * POST /api/chat/teach
     * body: { "question": "...", "ideal_answer": "...", "notes": "optional" }
     * ตอนนี้ยังไม่มี table สำหรับเก็บ, เอาเป็น log ไว้ก่อน
     */
    public function teach(Request $request)
    {
        $data = $request->validate([
            "question" => "required|string",
            "ideal_answer" => "required|string",
            "notes" => "nullable|string",
        ]);

        // เก็บลง log ไปก่อน (อนาคตค่อยสร้าง training_examples table)
        \Log::info("Manual teach example", $data);

        return response()->json([
            "message" => "Teaching example received",
        ]);
    }

    // GET /api/chat/conversations
    public function list(Request $request)
    {
        $userId = $request->attributes->get("api_key"); // ตามที่เลือกไว้
        $convs = Conversation::where("user_id", $userId)
            ->orderByDesc("updated_at")
            ->limit(50)
            ->get();

        return response()->json([
            "data" => $convs,
        ]);
    }

    // POST /api/chat/conversations
    public function create(Request $request)
    {
        $userId = $request->attributes->get("api_key");

        $conv = Conversation::create([
            "title" => $request->input("title", "New chat"),
            "user_id" => $userId,
        ]);

        return response()->json([
            "data" => $conv,
        ]);
    }

    // GET /api/chat/conversations/{id}
    public function show(Request $request, $id)
    {
        $userId = $request->attributes->get("api_key");

        $conv = Conversation::where("id", $id)
            ->where("user_id", $userId)
            ->firstOrFail();

        $messages = $conv->messages()->orderBy("created_at")->get();

        return response()->json([
            "conversation" => $conv,
            "messages" => $messages,
        ]);
    }

    // DELETE /api/chat/conversations/{id}
    public function destroy(Request $request, $id)
    {
        $userId = $request->attributes->get("api_key");

        $conv = Conversation::where("id", $id)
            ->where("user_id", $userId)
            ->firstOrFail();

        $conv->delete();

        return response()->json([
            "message" => "Conversation deleted",
        ]);
    }

    // POST /api/chat/store
    // body: { conversation_id, user_message, assistant_message }
    public function storeMessages(Request $request)
    {
        $userId = $request->attributes->get("api_key");

        $conversationId = (int) $request->input("conversation_id");
        $conv = Conversation::where("id", $conversationId)
            ->where("user_id", $userId)
            ->firstOrFail();

        $userMessage = $request->input("user_message");
        $assistantMessage = $request->input("assistant_message");

        if ($userMessage) {
            Message::create([
                "conversation_id" => $conv->id,
                "role" => "user",
                "content" => $userMessage,
            ]);
        }

        if ($assistantMessage) {
            Message::create([
                "conversation_id" => $conv->id,
                "role" => "assistant",
                "content" => $assistantMessage,
            ]);
        }

        $conv->touch(); // update updated_at

        return response()->json([
            "message" => "Messages saved",
        ]);
    }
}
