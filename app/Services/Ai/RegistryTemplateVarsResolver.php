<?php

namespace App\Services\Ai;

use App\Support\RuntimeEnv;

/**
 * Phase 3 — fills empty {@code api_key} / {@code base_url} template vars from {@see ProviderRegistry}
 * so runtime matches the two-layer catalog (provider env_key + default base URL) without duplicating logic in each caller.
 *
 * Resolution order for {@code base_url}: non-empty caller vars → {@code base_url} on the {@code models.json} row
 * (authoritative in UI) → provider catalog concrete URL → legacy {@code config('tutor.*')} fallback when still empty.
 *
 * Non-empty caller values always win.
 */
final class RegistryTemplateVarsResolver
{
    /**
     * @param  array<string, mixed>  $entry  Merged model row from {@see ModelRegistry::activeEntry()} / {@see get()}
     * @param  array<string, mixed>  $vars  Executor/template vars (typically includes api_key, base_url)
     * @return array<string, mixed>
     */
    public static function merge(string $capability, array $entry, array $vars): array
    {
        $out = $vars;
        $pid = $entry['provider'] ?? null;
        if (! is_string($pid) || $pid === '') {
            return $out;
        }

        $providers = self::providerRegistry();
        if (! $providers->has($pid)) {
            return $out;
        }

        $p = $providers->get($pid);

        if (self::isEmptyString($out['base_url'] ?? null)) {
            $eb = $entry['base_url'] ?? null;
            if (is_string($eb)) {
                $t = trim($eb);
                if ($t !== '' && self::isConcreteHttpUrl($t)) {
                    $out['base_url'] = rtrim($t, '/');
                }
            }
        }

        if (self::isEmptyString($out['api_key'] ?? null) && self::isResolvableProviderEnvKey($p['env_key'] ?? null)) {
            /** @var string $envName */
            $envName = $p['env_key'];
            $out['api_key'] = RuntimeEnv::get($envName);
        }

        if (self::isEmptyString($out['base_url'] ?? null)) {
            $candidate = isset($p['base_url']) && is_string($p['base_url']) ? $p['base_url'] : '';
            if (self::isConcreteHttpUrl($candidate)) {
                $out['base_url'] = rtrim($candidate, '/');
            }
        }

        if (self::isEmptyString($out['base_url'] ?? null)) {
            $fb = self::tutorConfigBaseUrlFallback($capability);
            if ($fb !== '') {
                $out['base_url'] = rtrim($fb, '/');
            }
        }

        if (self::isEmptyString($out['api_key'] ?? null)) {
            $keyFb = self::tutorConfigApiKeyFallback($capability);
            if ($keyFb !== '') {
                $out['api_key'] = $keyFb;
            }
        }

        return $out;
    }

    /**
     * When the row's provider env_key is unset in the environment, fall back to the same legacy
     * config chain as pre-registry callers (e.g. TUTOR_IMAGE_API_KEY / OPENAI_API_KEY for image).
     */
    private static function tutorConfigApiKeyFallback(string $capability): string
    {
        // Non-empty config wins (tests, explicit tutor.image_generation.api_key overrides).
        $fromConfig = trim(match ($capability) {
            'image' => (string) config('tutor.image_generation.api_key', ''),
            'tts' => (string) config('tutor.tts_generation.api_key', ''),
            default => '',
        });
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        // Same env var chains as config/tutor.php, via RuntimeEnv when config is empty (e.g. config:cache
        // baked nulls, or queue workers) so .env is still honored.
        if ($capability === 'image') {
            foreach ([
                'TUTOR_IMAGE_API_KEY',
                'TUTOR_IMAGE_AI_KEY',
                'IMAGE_NANO_BANANA_API_KEY',
                'tutor_image_api_key',
                'tutor_image_ai_key',
                'TUTOR_DEFAULT_LLM_API_KEY',
                'OPENAI_API_KEY',
            ] as $envName) {
                $v = trim(RuntimeEnv::get($envName));
                if ($v !== '') {
                    return $v;
                }
            }
        }
        if ($capability === 'tts') {
            foreach ([
                'TUTOR_TTS_API_KEY',
                'TTS_OPENAI_API_KEY',
                'TUTOR_DEFAULT_LLM_API_KEY',
                'OPENAI_API_KEY',
            ] as $envName) {
                $v = trim(RuntimeEnv::get($envName));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    /**
     * When the provider catalog only has a {@code {base_url}} placeholder, align with legacy {@code tutor.*} resolution
     * (same chain as {@see config('tutor.image_generation.base_url')} etc.).
     */
    private static function tutorConfigBaseUrlFallback(string $capability): string
    {
        $url = match ($capability) {
            'image' => (string) config('tutor.image_generation.base_url', ''),
            'tts' => (string) config('tutor.tts_generation.base_url', ''),
            'asr' => (string) config('tutor.default_chat.base_url', ''),
            default => '',
        };
        $url = trim($url);

        return self::isConcreteHttpUrl($url) ? $url : '';
    }

    private static function providerRegistry(): ProviderRegistry
    {
        if (function_exists('app') && app()->bound(ProviderRegistry::class)) {
            return app(ProviderRegistry::class);
        }

        return new ProviderRegistry;
    }

    private static function isEmptyString(mixed $v): bool
    {
        return ! is_string($v) || trim($v) === '';
    }

    private static function isResolvableProviderEnvKey(mixed $envKey): bool
    {
        if (! is_string($envKey) || $envKey === '') {
            return false;
        }
        if ($envKey === '{env_key}') {
            return false;
        }

        return (bool) preg_match('/^[A-Z][A-Z0-9_]*$/', $envKey);
    }

    private static function isConcreteHttpUrl(string $url): bool
    {
        if ($url === '' || str_contains($url, '{')) {
            return false;
        }

        return (bool) preg_match('#\Ahttps?://#i', $url);
    }
}
