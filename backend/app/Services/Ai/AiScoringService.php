<?php

namespace App\Services\Ai;

use App\Services\Ai\LLM\LLMRouter;

class AiScoringService
{
    public function __construct(protected LLMRouter $llm) {}

    /**
     * Evaluate the quality of an answer for a given question.
     *
     * Returns a score from 1 to 5 (integer).
     *
     * @param  string  $question
     * @param  string  $answer
     * @return int
     */
    public function evaluate(string $question, string $answer): int
    {
        $prompt = $this->buildPrompt($question, $answer);

        $raw = $this->llm->generate([
            "prompt" => $prompt,
            "metadata" => [
                "task" => "answer_scoring",
                "source" => "AiScoringService",
            ],
        ]);

        $score = $this->parseScoreFromResponse($raw);

        // Fallback to neutral score if parsing fails
        return $score ?? 3;
    }

    /**
     * Build the scoring prompt for the LLM.
     */
    protected function buildPrompt(string $question, string $answer): string
    {
        return <<<PROMPT
        You are a strict evaluator for an AI assistant's answer.

        Your task:
        - Read the user's question and the assistant's answer.
        - Give a score from 1 to 5.
        - Return ONLY a valid JSON object, nothing else.

        Scoring rules (1–5):
        - 1 = Completely wrong, irrelevant, or harmful.
        - 2 = Mostly incorrect or incomplete.
        - 3 = Partially correct, but missing details or has minor issues.
        - 4 = Mostly correct and useful, with small imperfections.
        - 5 = Fully correct, clear, and helpful.

        Output format (JSON only):
        {
          "score": X,
          "reason": "short explanation in one or two sentences"
        }

        User question:
        {$question}

        Assistant answer:
        {$answer}

        Return only JSON:
        PROMPT;
    }

    /**
     * Parse out the "score" field from the LLM response.
     *
     * @param  string  $raw
     * @return int|null
     */
    protected function parseScoreFromResponse(string $raw): ?int
    {
        $raw = trim($raw);

        // Try to find the first JSON object in the response (in case model adds extra text)
        $jsonString = $this->extractJsonObject($raw);

        $data = json_decode($jsonString, true);

        if (!is_array($data) || !isset($data["score"])) {
            return null;
        }

        $score = (int) $data["score"];

        // Clamp score between 1 and 5
        if ($score < 1) {
            $score = 1;
        } elseif ($score > 5) {
            $score = 5;
        }

        return $score;
    }

    /**
     * Best-effort extraction of a JSON object from the LLM response.
     *
     * @param  string  $text
     * @return string
     */
    protected function extractJsonObject(string $text): string
    {
        // If it already decodes, return as-is.
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $text;
        }

        // Otherwise, try to find the first {...} block.
        $start = strpos($text, "{");
        $end = strrpos($text, "}");

        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $candidate;
            }
        }

        // Fallback: return raw text; caller will handle decode failure.
        return $text;
    }
}
