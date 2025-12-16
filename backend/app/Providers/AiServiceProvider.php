<?php

namespace App\Providers;

use App\Services\Ai\LLM\DefaultLLMRouter;
use App\Services\Ai\LLM\GeminiAdapter;
use App\Services\Ai\LLM\LLMRouter;
use App\Services\Ai\LLM\LocalAdapter;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register AI-related bindings.
     */
    public function register(): void
    {
        $this->registerAdapters();
        $this->registerRouter();
    }

    /**
     * Register concrete LLM adapter implementations.
     *
     * These are low-level engines:
     * - LocalAdapter: local GPU model via Ollama (or similar)
     * - GeminiAdapter: cloud LLM (Google Gemini)
     */
    protected function registerAdapters(): void
    {
        // We register them as singletons so that any internal HTTP clients
        // or configuration are reused efficiently.
        $this->app->singleton(LocalAdapter::class, function ($app) {
            return new LocalAdapter(
                $app->make(\App\Services\Ai\LLM\OllamaService::class),
            );
        });

        $this->app->singleton(GeminiAdapter::class, function ($app) {
            return new GeminiAdapter();
        });
    }

    /**
     * Register the high-level LLM router.
     *
     * Application code should depend on LLMRouter instead of a specific
     * engine. The router will decide which underlying adapter to use
     * based on task type, metadata, and configuration.
     */
    protected function registerRouter(): void
    {
        $this->app->bind(LLMRouter::class, function ($app) {
            return new DefaultLLMRouter(
                $app->make(LocalAdapter::class),
                $app->make(GeminiAdapter::class),
            );
        });
    }

    /**
     * Bootstrap any AI-related services.
     */
    public function boot(): void
    {
        //
    }
}
