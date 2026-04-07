<?php

namespace App\Support\LessonGeneration;

/**
 * Pulls complete JSON objects from a partial assistant message for {"outline":[ {...}, ... ]}.
 */
final class StreamingLessonOutlineParser
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function extractOutlineObjects(string $buffer): array
    {
        if (preg_match('/"outline"\s*:\s*\[/s', $buffer, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $pos = $m[0][1] + strlen($m[0][0]);
        $len = strlen($buffer);
        $items = [];

        while ($pos < $len) {
            while ($pos < $len && ctype_space($buffer[$pos])) {
                $pos++;
            }
            if ($pos >= $len) {
                break;
            }
            if ($buffer[$pos] === ']') {
                break;
            }
            if ($buffer[$pos] === ',') {
                $pos++;

                continue;
            }
            if ($buffer[$pos] !== '{') {
                break;
            }
            $pair = self::readBalancedObject($buffer, $pos);
            if ($pair === null) {
                break;
            }
            [$objStr, $nextPos] = $pair;
            try {
                $decoded = json_decode($objStr, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $items[] = $decoded;
                }
            } catch (\JsonException) {
                // Incomplete or malformed segment; wait for more tokens.
                break;
            }
            $pos = $nextPos;
        }

        return $items;
    }

    public static function stripMarkdownFences(string $raw): string
    {
        $t = trim($raw);
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/', $t, $m) === 1) {
            return trim($m[1]);
        }

        return $t;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function readBalancedObject(string $s, int $start): ?array
    {
        $len = strlen($s);
        if ($start >= $len || $s[$start] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($c === '\\') {
                    $escape = true;

                    continue;
                }
                if ($c === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($c === '"') {
                $inString = true;

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return [substr($s, $start, $i - $start + 1), $i + 1];
                }
            }
        }

        return null;
    }
}
