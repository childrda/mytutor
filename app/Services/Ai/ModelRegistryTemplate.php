<?php

namespace App\Services\Ai;

/**
 * Expands {@see config/model_registry.json} endpoint and request_format placeholders.
 *
 * Syntax: {@name} required, {@name|default} optional with default. Defaults are coerced to int/float/bool when numeric/boolean-like.
 * A value that is exactly "{name}" or "{name|default}" may resolve to a non-scalar (e.g. messages array).
 * Strings with embedded placeholders (e.g. URLs) stringify resolved values.
 */
final class ModelRegistryTemplate
{
    private const string PLACEHOLDER = '/\{([a-zA-Z0-9_]+)(?:\|([^}]*))?\}/';

    /**
     * Turn registry response_path (choices[0].message.content) into a data_get key.
     */
    public static function responsePathToDataGetKey(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $withDots = preg_replace('/\[(\d+)\]/', '.$1', $path);

        return ltrim((string) $withDots, '.');
    }

    public static function expandUrl(string $template, array $vars): string
    {
        return self::expandStringWithEmbeds($template, $vars);
    }

    /**
     * @param  array<string, mixed>  $requestFormat
     * @return array<string, mixed>
     */
    public static function expandRequestFormat(array $requestFormat, array $vars): array
    {
        /** @var array<string, mixed> */
        return self::expandMixed($requestFormat, $vars);
    }

    private static function expandMixed(mixed $node, array $vars): mixed
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = self::expandMixed($v, $vars);
            }

            return $out;
        }
        if ($node === null || is_bool($node) || is_int($node) || is_float($node)) {
            return $node;
        }
        if (! is_string($node)) {
            return $node;
        }

        $t = trim($node);
        if (preg_match('/^\{([a-zA-Z0-9_]+)(?:\|([^}]*))?\}$/', $t, $m)) {
            return self::resolvePlaceholder($m[1], $m[2] ?? null, $vars);
        }

        return self::expandStringWithEmbeds($node, $vars);
    }

    private static function expandStringWithEmbeds(string $s, array $vars): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            function (array $m) use ($vars): string {
                $v = self::resolvePlaceholder($m[1], $m[2] ?? null, $vars);
                if (is_array($v) || is_object($v)) {
                    throw ModelRegistryException::invalidTemplate(
                        'Cannot embed array/object in string template for key "'.$m[1].'".',
                    );
                }
                if (is_bool($v)) {
                    return $v ? 'true' : 'false';
                }

                return (string) $v;
            },
            $s,
        );
    }

    private static function resolvePlaceholder(
        string $name,
        ?string $defaultRaw,
        array $vars,
    ): mixed {
        if (array_key_exists($name, $vars)) {
            return $vars[$name];
        }
        if ($defaultRaw !== null) {
            return self::coerceDefault($defaultRaw);
        }

        throw ModelRegistryException::missingVariable($name);
    }

    private static function coerceDefault(string $d): mixed
    {
        if ($d === 'true') {
            return true;
        }
        if ($d === 'false') {
            return false;
        }
        if (ctype_digit($d)) {
            return (int) $d;
        }
        if (is_numeric($d)) {
            return (float) $d;
        }

        return $d;
    }
}
