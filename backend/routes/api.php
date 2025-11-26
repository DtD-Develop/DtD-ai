<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\TrainWebhookController;

Route::post("/upload", [UploadController::class, "upload"]);
Route::post("/train", [UploadController::class, "train"]);
Route::post("/query", [QueryController::class, "query"]);

Route::post("/train-webhook", [TrainWebhookController::class, "handle"]);
