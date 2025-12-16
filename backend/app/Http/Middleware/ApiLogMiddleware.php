<?php
namespace App\Http\Middleware;

use App\Models\ApiLog;
use Closure;
use Illuminate\Http\Request;

class ApiLogMiddleware
{
    public function handle($request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = (int) ((microtime(true) - $start) * 1000);

        try {
            ApiLog::create([
                "endpoint" => $request->path(),
                "method" => $request->method(),
                "status_code" => $response->getStatusCode(),
                "latency_ms" => $duration,
                "llm_driver" => $request->attributes->get("llm_driver"),
                "llm_task" => $request->attributes->get("llm_task"),
                "ip" => $request->ip(),
                "api_key" => $request->attributes->get("api_key"),
                "request_body" => $request->all(),
                "response_body" =>
                    json_decode($response->getContent(), true) ?? null,
                "created_at" => now(),
            ]);
        } catch (\Throwable $e) {
            // Do not break the request because of logging error
        }

        return $response;
    }
}
