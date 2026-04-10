<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use App\Services\Ai\ModelRegistryHttpExecutor;
use App\Services\Ai\RegistryTemplateVarsResolver;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tavily-backed web search. Legacy path uses Authorization: Bearer. When {@see config('tutor.active.web_search')}
 * is set, uses {@see config/models.json} (Phase 8 — api_key in JSON body for tavily entry).
 */
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

        if (app(ModelRegistry::class)->hasActive('web_search')) {
            return $this->searchViaModelRegistry($request, $apiKey, $truncated);
        }

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
            if (! is_array($data)) {
                return ApiJson::error(ApiJson::UPSTREAM_ERROR, 502, 'Search provider returned invalid JSON');
            }

            return ApiJson::success(self::formatTavilyResponse($data, $truncated));
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::UPSTREAM_ERROR,
                502,
                'Search failed',
                $e->getMessage(),
            );
        }
    }

    private function searchViaModelRegistry(Request $request, string $apiKey, string $truncated): JsonResponse
    {
        $registry = app(ModelRegistry::class);
        $entry = $registry->activeEntry('web_search');
        if (! isset($entry['request_format']) || ! is_array($entry['request_format'])) {
            $key = $registry->activeKey('web_search') ?? '';

            return ApiJson::error(
                ApiJson::INVALID_REQUEST,
                400,
                'Active web_search registry provider "'.$key.'" has no request_format (stub). '
                .'Set TUTOR_ACTIVE_WEB_SEARCH, save an executable provider in Settings (when env is unset), or clear the active key for legacy search.',
            );
        }

        $vars = RegistryTemplateVarsResolver::merge('web_search', $entry, [
            'api_key' => $apiKey,
            'query' => $truncated,
            'timeout' => 60.0,
        ]);

        $depth = $request->input('search_depth');
        if (is_string($depth) && trim($depth) !== '') {
            $vars['search_depth'] = trim($depth);
        }

        $max = $request->input('max_results');
        if (is_numeric($max)) {
            $vars['max_results'] = max(1, min(20, (int) $max));
        }

        $include = $request->input('include_answer');
        if (is_string($include) && trim($include) !== '') {
            $vars['include_answer'] = trim($include);
        }

        try {
            $result = app(ModelRegistryHttpExecutor::class)->execute($entry, $vars);
        } catch (ModelRegistryException $e) {
            return self::jsonFromModelRegistryException($e);
        }

        $data = $result->json;
        if (! is_array($data)) {
            return ApiJson::error(ApiJson::UPSTREAM_ERROR, 502, 'Search provider returned invalid JSON');
        }

        return ApiJson::success(self::formatTavilyResponse($data, $truncated));
    }

    /**
     * @return array{answer: string, query: string, responseTime: float, sources: list<array{title: string, url: string, content: string, score: float}>}
     */
    private static function formatTavilyResponse(array $data, string $truncatedQuery): array
    {
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

        return [
            'answer' => (string) ($data['answer'] ?? ''),
            'query' => (string) ($data['query'] ?? $truncatedQuery),
            'responseTime' => (float) ($data['response_time'] ?? 0),
            'sources' => $sources,
        ];
    }

    private static function jsonFromModelRegistryException(ModelRegistryException $e): JsonResponse
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);
        $httpStatus = self::parseRegistryHttpFailureStatus($e);

        if (str_contains($lower, 'missing template variable')
            || str_contains($lower, 'invalid model registry provider entry')) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 400, $msg);
        }

        if ($httpStatus === 401) {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'Invalid or missing search API key', $msg);
        }

        return ApiJson::error(ApiJson::UPSTREAM_ERROR, 502, 'Search provider error', $msg);
    }

    private static function parseRegistryHttpFailureStatus(ModelRegistryException $e): ?int
    {
        if (preg_match('/Model registry HTTP request failed \\((\\d+)\\):/', $e->getMessage(), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
