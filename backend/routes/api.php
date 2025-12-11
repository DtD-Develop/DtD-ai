<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KbController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;

// Route::post("/upload", [UploadController::class, "upload"]);
// Route::post("/train", [UploadController::class, "train"]);
// Route::post("/query", [QueryController::class, "query"]);

Route::get("/health", function () {
    return response()->json(["status" => "ok"]);
});

Route::middleware(["api-key", "api-log"])->group(function () {
    // Chat & manual train
    // Route::get("/chat/conversations", [ChatController::class, "list"]);
    // Route::post("/chat/conversations", [ChatController::class, "create"]);
    // Route::get("/chat/conversations/{id}", [ChatController::class, "show"]);
    // Route::delete("/chat/conversations/{id}", [
    //     ChatController::class,
    //     "destroy",
    // ]);
    // Route::post("/chat/store", [ChatController::class, "storeMessages"]);

    // Route::post("/chat/test", [ChatController::class, "test"]);
    // Route::post("/chat/teach", [ChatController::class, "teach"]);

    // Route::post("/chat", [ChatController::class, "handle"]);
    // Route::post("/chat/{id}/rate", [ChatController::class, "rate"]);

    // KB upload / manage
    Route::post("/kb/upload", [KbController::class, "upload"]);
    Route::get("/kb/files", [KbController::class, "index"]);
    Route::get("/kb/files/{id}", [KbController::class, "show"]);
    Route::patch("/kb/files/{id}/tags", [KbController::class, "updateTags"]);
    Route::post("/kb/files/{id}/confirm", [KbController::class, "confirm"]);
    Route::delete("/kb/files/{id}", [KbController::class, "destroy"]);

    // Get chunks
    Route::get("/kb/files/{id}/chunks", [KbController::class, "chunks"]);

    // Delete chunk
    Route::delete("/kb/files/{id}/chunks/{chunkId}", [
        KbController::class,
        "deleteChunk",
    ]);

    // public query
    Route::post("/query", [QueryController::class, "query"]);

    // API logs
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

    Route::get("/chat/conversations", [ChatApiController::class, "index"]);
    Route::post("/chat/conversations", [
        ChatApiController::class,
        "storeConversation",
    ]);
    Route::get("/chat/conversations/{conversation}", [
        ChatApiController::class,
        "showConversation",
    ]);
    Route::patch("/chat/conversations/{conversation}", [
        ChatApiController::class,
        "updateConversation",
    ]);
    Route::delete("/chat/conversations/{conversation}", [
        ChatApiController::class,
        "destroyConversation",
    ]);

    Route::post("/chat/message", [ChatApiController::class, "message"]);
    Route::post("/chat/messages/{message}/rate", [
        ChatApiController::class,
        "rate",
    ]);
});
