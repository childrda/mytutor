<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\WebSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebSearchRegistryPathTest extends TestCase
{
    #[Test]
    public function active_web_search_uses_registry_json_body_with_api_key(): void
    {
        config(['tutor.active.web_search' => 'tavily']);

        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'answer' => 'ok',
                'query' => 'hello',
                'response_time' => 1.2,
                'results' => [
                    ['title' => 'T', 'url' => 'https://a.test', 'content' => 'c', 'score' => 0.9],
                ],
            ], 200),
        ]);

        $request = Request::create('/api/web-search', 'POST', [
            'query' => 'hello world',
            'apiKey' => 'tvly-secret',
        ]);

        $response = app(WebSearchController::class)($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertSame('ok', $data['answer'] ?? null);
        $this->assertCount(1, $data['sources'] ?? []);

        Http::assertSent(function ($req) {
            $d = $req->data();

            return str_contains($req->url(), 'tavily.com/search')
                && ! $req->hasHeader('Authorization')
                && ($d['api_key'] ?? null) === 'tvly-secret'
                && ($d['max_results'] ?? null) === 5
                && ($d['include_answer'] ?? null) === 'basic';
        });
    }

    #[Test]
    public function registry_path_truncates_query_to_400_chars(): void
    {
        config(['tutor.active.web_search' => 'tavily']);

        Http::fake([
            'https://api.tavily.com/search' => Http::response(['results' => []], 200),
        ]);

        $long = str_repeat('a', 500);
        $request = Request::create('/api/web-search', 'POST', [
            'query' => $long,
            'apiKey' => 'k',
        ]);

        app(WebSearchController::class)($request);

        Http::assertSent(function ($req) {
            $q = (string) ($req->data()['query'] ?? '');

            return mb_strlen($q) === 400;
        });
    }
}
