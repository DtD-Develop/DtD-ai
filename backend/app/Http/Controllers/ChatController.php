<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;
use App\Http\Controllers\Api\QueryController; // add

class ChatController extends Controller
{
    public function test(Request $request, QueryController $queryController)
    {
        return $queryController->query($request);
    }

    public function teach(Request $request)
    {
        $data = $request->validate([
            "question" => "required|string",
            "ideal_answer" => "required|string",
            "notes" => "nullable|string",
        ]);

        \Log::info("Manual teach example", $data);

        return response()->json([
            "message" => "Teaching example received",
        ]);
    }

    public function list(Request $request)
    {
        $userId = $request->attributes->get("api_key");
        $convs = Conversation::where("user_id", $userId)
            ->orderByDesc("updated_at")
            ->limit(50)
            ->get();

        return response()->json(["data" => $convs]);
    }

    public function create(Request $request)
    {
        $userId = $request->attributes->get("api_key");

        $conv = Conversation::create([
            "title" => $request->input("title", "New chat"),
            "user_id" => $userId,
        ]);

        return response()->json(["data" => $conv]);
    }

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

    public function storeMessages(Request $request)
    {
        $userId = $request->attributes->get("api_key");

        $conv = Conversation::where("id", $request->conversation_id)
            ->where("user_id", $userId)
            ->firstOrFail();

        if ($request->user_message) {
            Message::create([
                "conversation_id" => $conv->id,
                "role" => "user",
                "content" => $request->user_message,
            ]);
        }

        if ($request->assistant_message) {
            Message::create([
                "conversation_id" => $conv->id,
                "role" => "assistant",
                "content" => $request->assistant_message,
            ]);
        }

        $conv->touch();

        return response()->json(["message" => "Saved"]);
    }
}
