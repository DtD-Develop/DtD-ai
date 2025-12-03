<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $provided = $request->header("X-API-KEY");

        $keys = config("services.api_keys");
        $validKeys = collect(explode(",", $keys))
            ->map(fn($k) => trim($k))
            ->filter()
            ->all();

        if (!$provided || !in_array($provided, $validKeys, true)) {
            return response()->json(
                [
                    "message" => "Unauthorized",
                ],
                401,
            );
        }

        // ถ้าอยากรู้ว่า request นี้มาจาก key ไหน
        $request->attributes->set("api_key", $provided);

        return $next($request);
    }
}
