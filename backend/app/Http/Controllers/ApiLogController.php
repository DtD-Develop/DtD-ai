<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiLogController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query("q");
        $method = $request->query("method"); // GET / POST / ...
        $statusGroup = $request->query("status_group"); // 2xx / 4xx / 5xx / null
        $from = $request->query("from"); // YYYY-MM-DD
        $to = $request->query("to"); // YYYY-MM-DD
        $perPage = min($request->integer("per_page", 50), 200);

        $query = ApiLog::query();

        if ($from) {
            $query->whereDate("created_at", ">=", $from);
        }
        if ($to) {
            $query->whereDate("created_at", "<=", $to);
        }

        if ($method) {
            $query->where("method", strtoupper($method));
        }

        if ($statusGroup) {
            if ($statusGroup === "2xx") {
                $query->whereBetween("status_code", [200, 299]);
            } elseif ($statusGroup === "4xx") {
                $query->whereBetween("status_code", [400, 499]);
            } elseif ($statusGroup === "5xx") {
                $query->whereBetween("status_code", [500, 599]);
            }
        }

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where("endpoint", "like", "%{$q}%")->orWhere(
                    "api_key",
                    "like",
                    "%{$q}%",
                );
            });
        }

        $query->orderByDesc("created_at");

        $logs = $query->paginate($perPage);

        // Stats สำหรับ chart (เช่น 7 วันที่ผ่านมา)
        $statsQuery = clone $query;
        $statsRange = now()->subDays(6); // 7 วันล่าสุด
        $stats = ApiLog::query()
            ->where("created_at", ">=", $statsRange)
            ->selectRaw(
                "DATE(created_at) as d,
                COUNT(*) as total,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error",
            )
            ->groupBy("d")
            ->orderBy("d")
            ->get();

        $byEndpoint = ApiLog::query()
            ->select("endpoint", DB::raw("COUNT(*) as c"))
            ->groupBy("endpoint")
            ->orderByDesc("c")
            ->limit(10)
            ->get();

        return response()->json([
            "data" => $logs->items(),
            "meta" => [
                "current_page" => $logs->currentPage(),
                "last_page" => $logs->lastPage(),
                "per_page" => $logs->perPage(),
                "total" => $logs->total(),
            ],
            "stats" => [
                "trend" => $stats,
                "by_endpoint" => $byEndpoint,
            ],
        ]);
    }
}
