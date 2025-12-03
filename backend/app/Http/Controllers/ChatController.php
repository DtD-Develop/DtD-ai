<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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
}
