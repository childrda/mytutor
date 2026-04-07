<?php

namespace App\Services\Integrations;

/**
 * Resolves which third-party integrations are configured (metadata only; no secrets).
 */
final class IntegrationCatalog
{
    /**
     * @param  array<string, string>  $envPrefixToId
     * @return array<string, array{models?: list<string>, baseUrl?: string}>
     */
    public static function llmProviders(): array
    {
        return self::section(config('tutor.llm', []), requiresBaseUrl: false);
    }

    /** @return array<string, array{baseUrl?: string}> */
    public static function ttsProviders(): array
    {
        return self::simpleSection(config('tutor.tts', []));
    }

    /** @return array<string, array{baseUrl?: string}> */
    public static function asrProviders(): array
    {
        return self::simpleSection(config('tutor.asr', []));
    }

    /** @return array<string, array{baseUrl?: string}> */
    public static function pdfProviders(): array
    {
        return self::section(config('tutor.pdf', []), requiresBaseUrl: true);
    }

    /** @return array<string, array<string, never>> */
    public static function imageProviders(): array
    {
        return self::emptyMetadataSection(config('tutor.image', []));
    }

    /** @return array<string, array<string, never>> */
    public static function videoProviders(): array
    {
        return self::emptyMetadataSection(config('tutor.video', []));
    }

    /** @return array<string, array<string, never>> */
    public static function webSearchProviders(): array
    {
        return self::emptyMetadataSection(config('tutor.web_search', []));
    }

    public static function hasTavily(): bool
    {
        return (bool) env('TAVILY_API_KEY');
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, array{models?: list<string>, baseUrl?: string}>
     */
    private static function section(array $map, bool $requiresBaseUrl): array
    {
        $out = [];
        foreach ($map as $envPrefix => $id) {
            $key = env("{$envPrefix}_API_KEY");
            $url = env("{$envPrefix}_BASE_URL");
            $modelsRaw = env("{$envPrefix}_MODELS");
            if ($requiresBaseUrl) {
                if (! $url) {
                    continue;
                }
            } elseif (! $key) {
                continue;
            }
            $entry = [];
            if ($url) {
                $entry['baseUrl'] = $url;
            }
            if (is_string($modelsRaw) && $modelsRaw !== '') {
                $entry['models'] = array_values(array_filter(array_map('trim', explode(',', $modelsRaw))));
            }
            $out[$id] = $entry;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, array{baseUrl?: string}>
     */
    private static function simpleSection(array $map): array
    {
        $out = [];
        foreach ($map as $envPrefix => $id) {
            if (! env("{$envPrefix}_API_KEY") && ! env("{$envPrefix}_BASE_URL")) {
                continue;
            }
            $entry = [];
            if ($url = env("{$envPrefix}_BASE_URL")) {
                $entry['baseUrl'] = $url;
            }
            $out[$id] = $entry;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, array<string, never>>
     */
    private static function emptyMetadataSection(array $map): array
    {
        $out = [];
        foreach ($map as $envPrefix => $id) {
            if (! env("{$envPrefix}_API_KEY")) {
                continue;
            }
            $out[$id] = [];
        }

        return $out;
    }
}
