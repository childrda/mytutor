<?php

namespace App\Services\Ai;

/**
 * Allowlisted chat tools for POST /api/chat (Phase 3.3).
 *
 * Definitions live in config('tutor.chat_tools.tools'); handlers are built-in keys only.
 */
final class ChatToolRegistry
{
    /**
     * OpenAI-compatible `tools` array for chat/completions.
     *
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public static function openAiToolDefinitions(): array
    {
        $out = [];
        foreach (self::normalizedToolConfigs() as $row) {
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'parameters' => $row['parameters'],
                ],
            ];
        }

        return $out;
    }

    public static function isRegistered(string $name): bool
    {
        return self::findConfig($name) !== null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidToolArgumentsException
     */
    public static function parseAndValidateArguments(string $name, string $argumentsJson): array
    {
        $max = max(256, (int) config('tutor.chat_tools.max_argument_bytes', 8192));
        if (strlen($argumentsJson) > $max) {
            throw new InvalidToolArgumentsException('Tool arguments exceed size limit');
        }

        $trimmed = trim($argumentsJson);
        if ($trimmed === '') {
            $trimmed = '{}';
        }

        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidToolArgumentsException('Invalid JSON for tool arguments: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new InvalidToolArgumentsException('Tool arguments must be a JSON object');
        }

        $config = self::findConfig($name);
        if ($config === null) {
            throw new InvalidToolArgumentsException('Unknown tool: '.$name);
        }

        self::validateAgainstSchema($decoded, $config['parameters']);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function execute(string $name, array $args): array
    {
        $config = self::findConfig($name);
        if ($config === null) {
            return ['ok' => false, 'error' => 'Unknown tool'];
        }

        $handler = $config['handler'];

        return match ($handler) {
            'noop' => self::handleNoop($name, $args),
            default => ['ok' => false, 'error' => 'Unknown handler: '.$handler],
        };
    }

    /**
     * @return list<array{name: string, description: string, parameters: array<string, mixed>, handler: string}>
     */
    private static function normalizedToolConfigs(): array
    {
        $raw = config('tutor.chat_tools.tools', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
            if ($name === '') {
                continue;
            }
            $desc = isset($row['description']) && is_string($row['description']) ? $row['description'] : '';
            $params = isset($row['parameters']) && is_array($row['parameters']) ? $row['parameters'] : ['type' => 'object', 'properties' => []];
            $handler = isset($row['handler']) && is_string($row['handler']) ? $row['handler'] : 'noop';
            $out[] = [
                'name' => $name,
                'description' => $desc,
                'parameters' => $params,
                'handler' => $handler,
            ];
        }

        return $out;
    }

    /**
     * @return ?array{name: string, description: string, parameters: array<string, mixed>, handler: string}
     */
    private static function findConfig(string $name): ?array
    {
        foreach (self::normalizedToolConfigs() as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     *
     * @throws InvalidToolArgumentsException
     */
    private static function validateAgainstSchema(array $data, array $schema): void
    {
        if (($schema['type'] ?? '') !== 'object') {
            return;
        }

        $required = $schema['required'] ?? [];
        if (is_array($required)) {
            foreach ($required as $key) {
                if (! is_string($key)) {
                    continue;
                }
                if (! array_key_exists($key, $data)) {
                    throw new InvalidToolArgumentsException('Missing required property: '.$key);
                }
            }
        }

        $props = $schema['properties'] ?? [];
        if (! is_array($props)) {
            $props = [];
        }

        $additional = $schema['additionalProperties'] ?? true;

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (! array_key_exists($key, $props)) {
                if ($additional === false) {
                    throw new InvalidToolArgumentsException('Unknown property: '.$key);
                }

                continue;
            }

            $ptype = is_array($props[$key]) ? ($props[$key]['type'] ?? null) : null;
            if ($ptype === 'string' && ! is_string($value)) {
                throw new InvalidToolArgumentsException('Property '.$key.' must be a string');
            }
            if ($ptype === 'integer' && ! is_int($value)) {
                throw new InvalidToolArgumentsException('Property '.$key.' must be an integer');
            }
            if ($ptype === 'boolean' && ! is_bool($value)) {
                throw new InvalidToolArgumentsException('Property '.$key.' must be a boolean');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function handleNoop(string $name, array $args): array
    {
        return [
            'ok' => true,
            'tool' => $name,
            'message' => 'Demo tool executed; no persistent server-side effect in Phase 3.3.',
            'echo' => $args,
        ];
    }
}
