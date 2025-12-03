<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KbController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\ChatController;

// Route::post("/upload", [UploadController::class, "upload"]);
// Route::post("/train", [UploadController::class, "train"]);
// Route::post("/query", [QueryController::class, "query"]);

Route::get("/health", function () {
    return response()->json(["status" => "ok"]);
});

Route::middleware(["api-key", "api-log"])->group(function () {
    // Chat & manual train
    Route::post("/chat/test", [ChatController::class, "test"]);
    Route::post("/chat/teach", [ChatController::class, "teach"]);

    // KB upload / manage
    Route::post("/kb/upload", [KbController::class, "upload"]);
    Route::get("/kb/files", [KbController::class, "index"]);
    Route::get("/kb/files/{id}", [KbController::class, "show"]);
    Route::patch("/kb/files/{id}/tags", [KbController::class, "updateTags"]);
    Route::post("/kb/files/{id}/confirm", [KbController::class, "confirm"]);
    Route::delete("/kb/files/{id}", [KbController::class, "destroy"]);

    // public query
    Route::post("/query", [QueryController::class, "query"]);

    // API logs
    Route::get("/logs", [ApiLogController::class, "index"]);
});
