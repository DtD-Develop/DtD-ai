<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $table = "api_logs";

    // ตารางนี้มีแค่ created_at ตามสเปค (ไม่มี updated_at)
    public $timestamps = false;

    protected $fillable = [
        "endpoint",
        "method",
        "status_code",
        "latency_ms",
        "llm_driver",
        "llm_task",
        "ip",
        "api_key",
        "request_body",
        "response_body",
        "created_at",
    ];

    protected $casts = [
        "status_code" => "integer",
        "latency_ms" => "integer",
        "request_body" => "array",
        "response_body" => "array",
        "created_at" => "datetime",
        "llm_driver" => "string",
        "llm_task" => "string",
    ];
}
