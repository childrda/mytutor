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
    public function merges_request_headers_on_execute_with_resolved_json_body(): void
    {
        Http::fake([
            'https://api.test/v1/messages' => Http::response([
                'content' => [['text' => 'ok']],
            ], 200),
        ]);

        $entry = app(ModelRegistry::class)->get('llm', 'anthropic');
        $exec = new ModelRegistryHttpExecutor(30.0);
        $vars = [
            'base_url' => 'https://api.test/v1',
            'api_key' => 'sk-ant',
            'model' => 'claude-test',
        ];
        $body = [
            'model' => 'claude-test',
            'max_tokens' => 8,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ];
        $result = $exec->executeWithResolvedJsonBody($entry, $body, $vars);

        $this->assertTrue($result->successful);
        $this->assertSame('ok', $result->extracted);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/messages')
                && $request->hasHeader('x-api-key', 'sk-ant')
                && $request->hasHeader('anthropic-version', '2023-06-01');
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
        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'api.test/v1/audio/speech')
                && ($data['speed'] ?? null) === 1;
        });
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
            $d = $request->data();

            return ! $request->hasHeader('Authorization')
                && ($d['api_key'] ?? null) === 'tvly-test'
                && ($d['query'] ?? null) === 'flight'
                && ($d['search_depth'] ?? null) === 'basic'
                && ($d['max_results'] ?? null) === 5
                && ($d['include_answer'] ?? null) === 'basic';
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
    public function unknown_request_encoding_throws(): void
    {
        $entry = [
            'provider' => 'x',
            'endpoint' => 'https://api.test/v1/up',
            'request_format' => ['a' => '1'],
            'request_encoding' => 'application/x-unknown',
            'response_path' => 'ok',
            'auth_header' => null,
            'auth_scheme' => null,
        ];
        $exec = new ModelRegistryHttpExecutor;

        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('application/x-unknown');

        $exec->execute($entry, []);
    }

    #[Test]
    public function multipart_post_attaches_file_and_sends_text_fields(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'asr');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'fake-audio');

        Http::fake([
            'https://api.test/v1/audio/transcriptions' => Http::response(['text' => 'hello from whisper'], 200),
        ]);

        $entry = app(ModelRegistry::class)->get('asr', 'openai-whisper');
        $exec = new ModelRegistryHttpExecutor;
        $result = $exec->execute($entry, [
            'base_url' => 'https://api.test/v1',
            'api_key' => 'sk-test',
            'audio_file' => ['path' => $tmp, 'filename' => 'clip.mp3'],
        ]);

        @unlink($tmp);

        $this->assertSame('hello from whisper', $result->extracted);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'audio/transcriptions')) {
                return false;
            }
            if (! $request->hasHeader('Authorization', 'Bearer sk-test')) {
                return false;
            }
            $body = (string) $request->body();
            if (! str_contains($body, 'Content-Disposition: form-data; name="model"')) {
                return false;
            }
            if (! str_contains($body, 'whisper-1')) {
                return false;
            }
            if (! str_contains($body, 'name="file"')) {
                return false;
            }
            if (! str_contains($body, 'fake-audio')) {
                return false;
            }

            return true;
        });
    }

    #[Test]
    public function execute_with_resolved_json_body_posts_payload_as_given(): void
    {
        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'pong']]],
            ], 200),
        ]);

        $entry = app(ModelRegistry::class)->get('llm', 'openai');
        $exec = new ModelRegistryHttpExecutor;
        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'temperature' => 0.2,
            'max_tokens' => 9,
        ];
        $result = $exec->executeWithResolvedJsonBody($entry, $body, [
            'base_url' => 'https://api.test/v1',
            'api_key' => 'sk-x',
            'timeout' => 25,
        ]);

        $this->assertSame('pong', $result->extracted);
        Http::assertSent(function ($request) use ($body) {
            return $request->data() === $body
                && $request->hasHeader('Authorization', 'Bearer sk-x');
        });
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
