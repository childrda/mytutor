<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));
        if ($query === '') {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, 'query is required');
        }

        $clientKey = (string) $request->input('apiKey', '');
        $apiKey = $clientKey !== '' ? $clientKey : (string) env('TAVILY_API_KEY');
        if ($apiKey === '') {
            return ApiJson::error(
                ApiJson::MISSING_API_KEY,
                400,
                'Tavily API key is not configured. Set TAVILY_API_KEY.',
            );
        }

        $truncated = mb_substr($query, 0, 400);

        try {
            $res = Http::withToken($apiKey, 'Bearer')
                ->acceptJson()
                ->timeout(60)
                ->post('https://api.tavily.com/search', [
                    'query' => $truncated,
                    'search_depth' => 'basic',
                    'max_results' => 5,
                    'include_answer' => 'basic',
                ]);

            if (! $res->successful()) {
                return ApiJson::error(
                    ApiJson::UPSTREAM_ERROR,
                    502,
                    'Search provider error',
                    $res->body(),
                );
            }

            $data = $res->json();
            $sources = [];
            foreach ($data['results'] ?? [] as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $sources[] = [
                    'title' => (string) ($r['title'] ?? ''),
                    'url' => (string) ($r['url'] ?? ''),
                    'content' => (string) ($r['content'] ?? ''),
                    'score' => (float) ($r['score'] ?? 0),
                ];
            }

            return ApiJson::success([
                'answer' => (string) ($data['answer'] ?? ''),
                'query' => (string) ($data['query'] ?? $truncated),
                'responseTime' => (float) ($data['response_time'] ?? 0),
                'sources' => $sources,
            ]);
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::UPSTREAM_ERROR,
                502,
                'Search failed',
                $e->getMessage(),
            );
        }
    }
}
