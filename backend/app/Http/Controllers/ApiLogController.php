<?php

namespace App\Http\Controllers;

use App\Models\ApiLog;
use Illuminate\Http\Request;

class ApiLogController extends Controller
{
    /**
     * GET /api/logs
     * filter: endpoint, status_code, api_key, date_from, date_to
     */
    public function index(Request $request)
    {
        $query = ApiLog::query();

        if ($request->filled("endpoint")) {
            $query->where("endpoint", "like", "%" . $request->endpoint . "%");
        }

        if ($request->filled("status_code")) {
            $query->where("status_code", (int) $request->status_code);
        }

        if ($request->filled("api_key")) {
            $query->where("api_key", $request->api_key);
        }

        if ($request->filled("date_from")) {
            $query->where("created_at", ">=", $request->date_from);
        }

        if ($request->filled("date_to")) {
            $query->where("created_at", "<=", $request->date_to);
        }

        $perPage = min((int) $request->get("per_page", 50), 200);

        return response()->json($query->orderByDesc("id")->paginate($perPage));
    }
}
