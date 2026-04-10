<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\LlmClient;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class QuizGradeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $question = (string) $request->input('question', '');
        $userAnswer = (string) $request->input('userAnswer', '');
        $points = (float) $request->input('points', 0);
        $commentPrompt = $request->input('commentPrompt');

        if ($question === '' || $userAnswer === '') {
            return ApiJson::error(
                ApiJson::MISSING_REQUIRED_FIELD,
                400,
                'question and userAnswer are required',
            );
        }

        if (! is_finite($points) || $points <= 0) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 400, 'points must be a positive number');
        }

        $baseUrl = (string) $request->header('X-LLM-Base-Url', config('tutor.default_chat.base_url'));
        $apiKey = (string) $request->header('X-LLM-Api-Key', config('tutor.default_chat.api_key'));
        $model = (string) $request->header('X-LLM-Model', config('tutor.default_chat.model'));

        if ($apiKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'LLM API key is required');
        }

        $p = (int) round($points);
        $system = "You are a professional educational assessor. Reply in JSON only:\n{\"score\": <integer from 0 to {$p}>, \"comment\": \"<brief feedback>\"}";

        $user = "Question: {$question}\nFull marks: {$p} points\n"
            .(is_string($commentPrompt) && $commentPrompt !== '' ? "Grading guidance: {$commentPrompt}\n" : '')
            ."Student answer: {$userAnswer}";

        try {
            $text = trim(LlmClient::chat($baseUrl, $apiKey, $model, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], 0.2, 512, [
                'user_id' => $request->user()?->getKey(),
                'source' => 'quiz_grade',
            ]));
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            $score = (int) ($decoded['score'] ?? 0);
            $comment = (string) ($decoded['comment'] ?? '');
            $score = max(0, min($p, $score));

            return ApiJson::success([
                'score' => $score,
                'comment' => $comment,
                'maxPoints' => $p,
            ]);
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::UPSTREAM_ERROR,
                502,
                'Grading failed',
                $e->getMessage(),
            );
        }
    }
}
