<?php

namespace App\Services;

use App\Services\OllamaService;

class AiScoringService
{
    public function __construct(protected OllamaService $ollama) {}

    public function evaluate($question, $answer)
    {
        $prompt = "
        Score the assistant's answer from 1 to 5.
        Return only JSON.

        {
            \"score\": X,
            \"reason\": \"...\"
        }

        Question: $question
        Answer: $answer
        ";

        $result = $this->ollama->complete($prompt);

        $json = json_decode($result, true);

        return $json["score"] ?? 3;
    }
}
