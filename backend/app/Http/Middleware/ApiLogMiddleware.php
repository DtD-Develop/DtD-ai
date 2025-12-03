<?php
namespace App\Http\Middleware;

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
            \App\Models\ApiLog::create([
                "endpoint" => $request->path(),
                "method" => $request->method(),
                "status_code" => $response->getStatusCode(),
                "latency_ms" => $duration,
                "ip" => $request->ip(),
                "api_key" => $request->attributes->get("api_key"),
                "request_body" => $request->all(),
                "response_body" =>
                    json_decode($response->getContent(), true) ?? null,
            ]);
        } catch (\Throwable $e) {
            // อย่าทำให้ request พังเพราะ log error
        }

        return $response;
    }
}
