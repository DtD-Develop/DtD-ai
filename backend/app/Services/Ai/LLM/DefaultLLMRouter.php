<?php

namespace App\Services\Ai\LLM;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * DefaultLLMRouter
 *
 * Task-based router for LLM calls.
 *
 * This class decides, for each generation request, whether to use:
 * - LocalAdapter  (typically a local GPU model via Ollama)
 * - GeminiAdapter (cloud LLM)
 *
 * based on the "task" and other metadata provided in the payload.
 *
 * It lets you:
 * - Use your VM's GPU for most workloads (fast & cheap).
 * - Route specific, high-value tasks to Gemini (or other cloud LLMs).
 */
class DefaultLLMRouter implements LLMRouter
{
    public function __construct(
        protected LocalAdapter $local,
        protected GeminiAdapter $gemini,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function generate(array $payload): string
    {
        $metadata = $payload["metadata"] ?? [];
        $task = (string) ($metadata["task"] ?? "default");

        $driver = $this->decideDriver($task, $metadata);

        // Attach driver/task info to current HTTP request (if any)
        try {
            $request = Request::instance();
            if ($request) {
                $request->attributes->set("llm_driver", $driver);
                $request->attributes->set("llm_task", $task);
            }
        } catch (\Throwable $e) {
            // If there's no HTTP request context (e.g. running in CLI/job),
            // just ignore; ApiLogMiddleware won't use these attributes anyway.
        }

        Log::info("llm.router.selected_driver", [
            "task" => $task,
            "driver" => $driver,
            "job" => $metadata["job"] ?? null,
            "source" => $metadata["source"] ?? null,
        ]);

        try {
            return match ($driver) {
                "gemini" => $this->gemini->generate($payload),
                "local" => $this->local->generate($payload),
                default => $this->local->generate($payload),
            };
        } catch (\Throwable $e) {
            Log::error("llm.router.generate_failed", [
                "task" => $task,
                "driver" => $driver,
                "error" => $e->getMessage(),
            ]);

            // Fallback strategy: if local failed, try Gemini once.
            if ($driver === "local") {
                try {
                    Log::warning("llm.router.fallback_to_gemini", [
                        "task" => $task,
                    ]);

                    return $this->gemini->generate($payload);
                } catch (\Throwable $fallbackException) {
                    Log::error("llm.router.fallback_gemini_failed", [
                        "task" => $task,
                        "error" => $fallbackException->getMessage(),
                    ]);
                }
            }

            // Last resort: return empty string to caller.
            return "";
        }
    }

    /**
     * Decide which driver (local/gemini) to use for a given task.
     *
     * You can adjust this policy to match your business needs.
     *
     * Examples:
     * - Most tasks use local GPU model (fast/cheap).
     * - Some "high value" tasks use Gemini (better quality / bigger context).
     *
     * @param  string               $task
     * @param  array<string,mixed>  $metadata
     * @return string               "local" | "gemini"
     */
    protected function decideDriver(string $task, array $metadata): string
    {
        // You can also inspect other metadata here, e.g.:
        // - $metadata['priority']
        // - $metadata['length'] (estimated tokens)
        // - $metadata['user_tier'] (free vs enterprise)
        //
        // For now we implement a simple task-based policy.

        return match ($task) {
            // Chat / Q&A with KB: use local GPU by default
            "chat", "kb_answer" => "local",
            // KB ingestion helpers (summary / tags / title):
            "kb_summary",
            "kb_auto_tag",
            "title_generation",
            "training_to_kb"
                => "local",
            // Explicitly mark some tasks as "high_quality"
            // to force cloud LLM usage (e.g. for investor demo, complex docs, etc.)
            "high_quality", "investor_demo" => "gemini",
            // Default: respect global driver config (ai.driver)
            default => config("ai.driver", "local"),
        };
    }
}
