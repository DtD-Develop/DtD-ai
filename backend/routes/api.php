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
use App\Http\Controllers\Api\Ai\ChatController as AiChatController;

// -----------------------------------------------------------------------------
// Health check
// -----------------------------------------------------------------------------
Route::get("/health", function () {
    return response()->json(["status" => "ok"]);
});

// -----------------------------------------------------------------------------
// AI Platform Routes (/api/ai/*) - New official AI entrypoints
// -----------------------------------------------------------------------------
Route::prefix("ai")
    ->middleware(["api-key", "api-log"])
    ->group(function () {
        // Simple health for AI group
        Route::get("/health", [AiChatController::class, "health"]);

        // Test LLM endpoint (uses LLMRouter under the hood)
        Route::post("/test-llm", [AiChatController::class, "testLlm"]);

        // Official RAG Chat endpoint for the AI Platform
        Route::post("/chat", [AiChatController::class, "chat"]);
    });

// -----------------------------------------------------------------------------
// Core platform routes (protected by api-key + api-log)
// These are the routes that represent the "current" AI Platform surface:
// - KB management
// - API logs
// - Dashboard
// -----------------------------------------------------------------------------
Route::middleware(["api-key", "api-log"])->group(function () {
    // KB management
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

    // API logs + dashboard
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
});

// -----------------------------------------------------------------------------
// Legacy / experimental AI routes
//
// These routes come from the earlier design of the system and are kept under
// a separate /legacy namespace so they don't mix with the new AI Platform
// entrypoints. They are not used by the new /api/ai/* flow, but can still be
// useful for internal testing or reference while refactoring.
// -----------------------------------------------------------------------------
Route::prefix("legacy")
    ->middleware(["api-key", "api-log"])
    ->group(function () {
        // Legacy query endpoint
        Route::post("/query", [QueryController::class, "query"]);

        // Legacy chat conversation system
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

        // Legacy RAG + LLM + training endpoints
        Route::post("/rag/query", [RagController::class, "query"]);
        Route::post("/llm/answer", [LLMController::class, "answer"]);
        Route::post("/train/feedback", [TrainController::class, "feedback"]);
    });
