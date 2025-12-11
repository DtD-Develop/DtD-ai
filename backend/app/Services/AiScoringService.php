<?php

namespace App\Services;

use App\Services\LLMService;

class AiScoringService
{
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

        $result = app(LLMService::class)->complete($prompt);

        $json = json_decode($result, true);

        return $json["score"] ?? 3;
    }
}
