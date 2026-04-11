<?php

namespace Tests\Unit;

use App\Services\Ai\RegistryTemplateVarsResolver;
use App\Support\RuntimeEnv;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistryTemplateVarsResolverTest extends TestCase
{
    #[Test]
    public function caller_api_key_and_base_url_win_over_catalog(): void
    {
        $vars = RegistryTemplateVarsResolver::merge('llm', ['provider' => 'openai'], [
            'api_key' => 'caller-key',
            'base_url' => 'https://example.com/v1',
            'model' => 'x',
        ]);
        $this->assertSame('caller-key', $vars['api_key']);
        $this->assertSame('https://example.com/v1', $vars['base_url']);
    }

    #[Test]
    public function fills_fixed_provider_base_url_when_empty(): void
    {
        $vars = RegistryTemplateVarsResolver::merge('llm', ['provider' => 'anthropic'], [
            'api_key' => 'sk-ant-test',
            'base_url' => '',
            'model' => 'claude-3-5-sonnet-20241022',
        ]);
        $this->assertSame('sk-ant-test', $vars['api_key']);
        $this->assertSame('https://api.anthropic.com/v1', $vars['base_url']);
    }

    #[Test]
    public function fills_api_key_from_env_when_empty_and_provider_has_env_key(): void
    {
        $vars = RegistryTemplateVarsResolver::merge('llm', ['provider' => 'openai'], [
            'api_key' => '',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
        ]);
        $this->assertIsString($vars['api_key'] ?? null);
        $this->assertSame(RuntimeEnv::get('OPENAI_API_KEY'), $vars['api_key']);
    }

    #[Test]
    public function skips_placeholder_env_key_on_custom_provider(): void
    {
        $vars = RegistryTemplateVarsResolver::merge('llm', ['provider' => 'custom'], [
            'api_key' => '',
            'base_url' => 'https://proxy.example/v1',
        ]);
        $this->assertSame('', $vars['api_key']);
        $this->assertSame('https://proxy.example/v1', $vars['base_url']);
    }

    #[Test]
    public function does_not_replace_concrete_base_with_placeholder_provider_url(): void
    {
        $vars = RegistryTemplateVarsResolver::merge('llm', ['provider' => 'qwen'], [
            'api_key' => 'k',
            'base_url' => '',
        ]);
        $this->assertSame('k', $vars['api_key']);
        $this->assertArrayHasKey('base_url', $vars);
        $this->assertSame('', $vars['base_url']);
    }

    #[Test]
    public function fills_image_base_from_tutor_config_when_provider_catalog_is_placeholder(): void
    {
        config(['tutor.image_generation.base_url' => 'https://nano-proxy.test/v1']);

        $vars = RegistryTemplateVarsResolver::merge('image', ['provider' => 'nano-banana'], [
            'api_key' => 'k',
            'base_url' => '',
            'model' => 'm',
        ]);

        $this->assertSame('https://nano-proxy.test/v1', $vars['base_url']);
    }

    #[Test]
    public function models_json_base_url_on_row_wins_over_tutor_fallback(): void
    {
        config(['tutor.image_generation.base_url' => 'https://from-env.test/v1']);

        $vars = RegistryTemplateVarsResolver::merge('image', [
            'provider' => 'nano-banana',
            'base_url' => 'https://from-json.test/v1',
        ], [
            'api_key' => 'k',
            'base_url' => '',
            'model' => 'm',
        ]);

        $this->assertSame('https://from-json.test/v1', $vars['base_url']);
    }

    #[Test]
    public function fills_image_api_key_from_tutor_config_when_provider_env_not_resolved(): void
    {
        config(['tutor.image_generation.api_key' => 'legacy-img-key']);

        $vars = RegistryTemplateVarsResolver::merge('image', [
            'provider' => 'custom',
            'base_url' => 'https://proxy.test/v1',
        ], [
            'api_key' => '',
            'base_url' => '',
            'model' => 'm',
        ]);

        $this->assertSame('legacy-img-key', $vars['api_key']);
        $this->assertSame('https://proxy.test/v1', $vars['base_url']);
    }
}
