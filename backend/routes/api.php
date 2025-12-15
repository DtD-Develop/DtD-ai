<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KbController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\Api\ApiLogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RagController;
use App\Http\Controllers\LLMController;
use App\Http\Controllers\TrainController;

// health check
Route::get("/health", function () {
    return response()->json(["status" => "ok"]);
});

Route::middleware(["api-key", "api-log"])->group(function () {
    // KB
    Route::post("/kb/upload", [KbController::class, "upload"]);
    Route::get("/kb/files", [KbController::class, "index"]);
    Route::get("/kb/files/{id}", [KbController::class, "show"]);
    Route::patch("/kb/files/{id}/tags", [KbController::class, "updateTags"]);
    Route::post("/kb/files/{id}/confirm", [KbController::class, "confirm"]);
    Route::delete("/kb/files/{id}", [KbController::class, "destroy"]);
    Route::get("/kb/files/{id}/chunks", [KbController::class, "chunks"]);
    Route::delete("/kb/files/{id}/chunks/{chunkId}", [
        KbController::class,
        "deleteChunk",
    ]);

    // query
    Route::post("/query", [QueryController::class, "query"]);

    // log + dashboard
    Route::get("/logs", [ApiLogController::class, "index"]);
    Route::get("/dashboard/overview", [DashboardController::class, "overview"]);
    Route::get("/dashboard/query-chart", [
        DashboardController::class,
        "queryChart",
    ]);
    Route::get("/dashboard/recent-queries", [
        DashboardController::class,
        "recentQueries",
    ]);

    // chat
    Route::get("/chat/conversations", [ChatController::class, "index"]);
    Route::post("/chat/conversations", [
        ChatController::class,
        "storeConversation",
    ]);
    Route::get("/chat/conversations/{conversation}", [
        ChatController::class,
        "showConversation",
    ]);
    Route::patch("/chat/conversations/{conversation}", [
        ChatController::class,
        "updateConversation",
    ]);
    Route::delete("/chat/conversations/{conversation}", [
        ChatController::class,
        "destroyConversation",
    ]);
    Route::post("/chat/message", [ChatController::class, "message"]);
    Route::post("/chat/messages/{message}/rate", [
        ChatController::class,
        "rate",
    ]);
    Route::post("/chat/message/stream", [
        ChatController::class,
        "messageStream",
    ]);
    Route::post("/chat/conversations/{conversation}/summarize", [
        ChatController::class,
        "summarizeConversation",
    ]);

    // RAG + LLM + Train
    Route::post("/rag/query", [RagController::class, "query"]);
    Route::post("/llm/answer", [LLMController::class, "answer"]);
    Route::post("/train/feedback", [TrainController::class, "feedback"]);
});
