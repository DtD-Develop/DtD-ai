<?php

namespace App\Http\Controllers;

use App\Models\KbFile;
use App\Models\KbChunk;
use App\Models\ApiLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/overview
     * สรุปตัวเลขหลักบน Dashboard
     */
    public function overview()
    {
        $since = now()->subDay();

        $kbFilesTotal = KbFile::count();
        $kbReady = KbFile::where("status", "ready")->count();
        $kbChunks = KbChunk::count();

        $queries24h = ApiLog::where("endpoint", "/api/query")
            ->where("created_at", ">=", $since)
            ->count();

        // สถานะคิว (ใช้ redis queue:default)
        $defaultQueueSize = 0;
        try {
            $defaultQueueSize = Redis::llen("queues:default");
        } catch (\Throwable $e) {
            // ถ้า redis ล่ม ก็ไม่ต้องทำให้ API พัง
        }

        // failed_jobs table (ถ้าคุณใช้ Horizon + failed jobs)
        $failedJobs = 0;
        if (Schema::hasTable("failed_jobs")) {
            $failedJobs = DB::table("failed_jobs")->count();
        }

        return response()->json([
            "kb_files_total" => $kbFilesTotal,
            "kb_ready" => $kbReady,
            "kb_chunks" => $kbChunks,
            "queries_24h" => $queries24h,
            "queue_default" => $defaultQueueSize,
            "failed_jobs" => $failedJobs,
        ]);
    }

    /**
     * GET /api/dashboard/query-chart
     * คืน chart count / ชั่วโมง ย้อนหลัง 24 ชม. สำหรับ /api/query
     */
    public function queryChart()
    {
        $since = now()->subHours(24);

        $rows = ApiLog::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour"),
            DB::raw("COUNT(*) as cnt"),
        )
            ->where("endpoint", "/api/query")
            ->where("created_at", ">=", $since)
            ->groupBy("hour")
            ->orderBy("hour")
            ->get();

        // map เป็น array [timestamp => count] ก่อน
        $byHour = [];
        foreach ($rows as $row) {
            $byHour[$row->hour] = (int) $row->cnt;
        }

        // เติมช่องว่างชั่วโมงที่ไม่มี log ให้เป็น 0
        $points = [];
        $cursor = $since->copy()->startOfHour();
        $now = now()->startOfHour();

        while ($cursor <= $now) {
            $key = $cursor->format("Y-m-d H:00:00");
            $points[] = [
                "timestamp" => $key,
                "label" => $cursor->format("H:i"),
                "count" => $byHour[$key] ?? 0,
            ];
            $cursor->addHour();
        }

        return response()->json([
            "points" => $points,
        ]);
    }

    /**
     * GET /api/dashboard/recent-queries
     * ใช้ API logs เป็น "recent chat queries"
     */
    public function recentQueries()
    {
        $logs = ApiLog::whereIn("endpoint", ["/api/query", "/api/chat/test"])
            ->orderByDesc("created_at")
            ->limit(20)
            ->get();

        $data = $logs->map(function (ApiLog $log) {
            $body = $log->request_body;

            // ถ้ายังไม่ได้ cast เป็น array, เผื่อไว้
            if (is_string($body)) {
                $decoded = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $body = $decoded;
                } else {
                    $body = [];
                }
            }

            $q = $body["query"] ?? ($body["message"] ?? null);
            $preview = $q ? mb_strimwidth($q, 0, 150, "…") : null;

            return [
                "id" => $log->id,
                "endpoint" => $log->endpoint,
                "method" => $log->method,
                "status_code" => $log->status_code,
                "created_at" => $log->created_at,
                "query" => $preview,
                "latency_ms" => $log->latency_ms,
            ];
        });

        return response()->json([
            "data" => $data,
        ]);
    }
}
