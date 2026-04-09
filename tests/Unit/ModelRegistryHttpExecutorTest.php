<?php

namespace Tests\Unit;

use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use App\Services\Ai\ModelRegistryHttpExecutor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelRegistryHttpExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::fake();

        parent::tearDown();
    }

    #[Test]
    public function executes_openai_style_chat_and_extracts_content(): void
    {
        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'pong']],
                ],
            ], 200),
        ]);

        $entry = app(ModelRegistry::class)->get('llm', 'openai');
        $exec = new ModelRegistryHttpExecutor(30.0);
        $result = $exec->execute($entry, [
            'base_url' => 'https://api.test/v1',
            'api_key' => 'sk-test',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);

        $this->assertTrue($result->successful);
        $this->assertSame('pong', $result->extracted);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'api.test/v1/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer sk-test')
                && ($data['model'] ?? null) === 'gpt-4o-mini'
                && ($data['messages'][0]['content'] ?? null) === 'ping';
        });
    }

    #[Test]
    public function binary_response_returns_raw_body(): void
    {
        Http::fake([
            'https://api.test/v1/audio/speech' => Http::response("\x00\x01binary", 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $entry = app(ModelRegistry::class)->get('tts', 'openai-tts');
        $exec = new ModelRegistryHttpExecutor;
        $result = $exec->execute($entry, [
            'base_url' => 'https://api.test/v1',
            'api_key' => 'sk-test',
            'text' => 'Hello',
        ]);

        $this->assertSame("\x00\x01binary", $result->extracted);
        $this->assertSame("\x00\x01binary", $result->rawBody);
    }

    #[Test]
    public function binary_base64_decodes_using_response_path(): void
    {
        $entry = [
            'provider' => 'test',
            'endpoint' => 'https://api.test/v1/encode',
            'request_format' => ['q' => '{query}'],
            'response_path' => 'data[0].blob',
            'response_type' => 'binary_base64',
            'auth_header' => null,
            'auth_scheme' => null,
        ];

        Http::fake([
            'https://api.test/v1/encode' => Http::response([
                'data' => [['blob' => base64_encode('xyz')]],
            ], 200),
        ]);

        $exec = new ModelRegistryHttpExecutor;
        $result = $exec->execute($entry, ['query' => 'x']);

        $this->assertSame('xyz', $result->extracted);
    }

    #[Test]
    public function tavily_style_no_auth_header(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response(['results' => [['title' => 'A']]], 200),
        ]);

        $entry = app(ModelRegistry::class)->get('web_search', 'tavily');
        $exec = new ModelRegistryHttpExecutor;
        $result = $exec->execute($entry, [
            'api_key' => 'tvly-test',
            'query' => 'flight',
        ]);

        $this->assertIsArray($result->extracted);
        $this->assertSame('A', $result->extracted[0]['title'] ?? null);
        Http::assertSent(function ($request) {
            return ! $request->hasHeader('Authorization')
                && ($request->data()['api_key'] ?? null) === 'tvly-test';
        });
    }

    #[Test]
    public function http_error_throws_with_status(): void
    {
        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response(['error' => 'nope'], 401),
        ]);

        $entry = app(ModelRegistry::class)->get('llm', 'openai');
        $exec = new ModelRegistryHttpExecutor;

        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('401');

        $exec->execute($entry, [
            'base_url' => 'https://api.test/v1',
            'api_key' => 'bad',
            'messages' => [['role' => 'user', 'content' => 'x']],
        ]);
    }

    #[Test]
    public function stub_provider_without_request_format_throws(): void
    {
        $entry = app(ModelRegistry::class)->get('pdf', 'unpdf');
        $exec = new ModelRegistryHttpExecutor;

        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('request_format');

        $exec->execute($entry, []);
    }

    #[Test]
    public function multipart_request_encoding_throws_not_supported(): void
    {
        $entry = [
            'provider' => 'x',
            'endpoint' => 'https://api.test/v1/up',
            'request_format' => ['a' => '1'],
            'request_encoding' => 'multipart',
            'response_path' => 'ok',
            'auth_header' => null,
            'auth_scheme' => null,
        ];
        $exec = new ModelRegistryHttpExecutor;

        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('multipart');

        $exec->execute($entry, []);
    }

    #[Test]
    public function container_resolves_executor_singleton(): void
    {
        $this->assertSame(
            $this->app->make(ModelRegistryHttpExecutor::class),
            $this->app->make(ModelRegistryHttpExecutor::class),
        );
    }
}
