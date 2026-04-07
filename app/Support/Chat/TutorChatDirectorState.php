<?php

namespace App\Support\Chat;

/**
 * Normalizes and merges `directorState` from the client for prompts and `done` payloads (Phase 3.4).
 */
final class TutorChatDirectorState
{
    /**
     * @return array{turnCount: int, agentResponses: list<array<string, mixed>>, whiteboardLedger: list<array<string, mixed>>}
     */
    public static function empty(): array
    {
        return [
            'turnCount' => 0,
            'agentResponses' => [],
            'whiteboardLedger' => [],
        ];
    }

    /**
     * @return array{turnCount: int, agentResponses: list<array<string, mixed>>, whiteboardLedger: list<array<string, mixed>>}
     */
    public static function sanitizeIncoming(mixed $raw): array
    {
        if (! is_array($raw)) {
            return self::empty();
        }

        $out = self::empty();

        if (isset($raw['turnCount']) && is_numeric($raw['turnCount'])) {
            $out['turnCount'] = max(0, min(1_000_000, (int) $raw['turnCount']));
        }

        if (isset($raw['agentResponses']) && is_array($raw['agentResponses'])) {
            $filtered = array_values(array_filter($raw['agentResponses'], fn (mixed $v): bool => is_array($v)));
            $out['agentResponses'] = array_slice($filtered, -40);
        }

        if (isset($raw['whiteboardLedger']) && is_array($raw['whiteboardLedger'])) {
            $filtered = array_values(array_filter($raw['whiteboardLedger'], fn (mixed $v): bool => is_array($v)));
            $out['whiteboardLedger'] = array_slice($filtered, -50);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $thisTurnAgentResponses
     * @param  list<array<string, mixed>>  $serverLedgerDelta
     * @return array{turnCount: int, agentResponses: list<array<string, mixed>>, whiteboardLedger: list<array<string, mixed>>}
     */
    public static function mergeForDone(
        array $baseline,
        array $thisTurnAgentResponses,
        array $serverLedgerDelta = [],
    ): array {
        $out = self::sanitizeIncoming($baseline);
        $out['agentResponses'] = array_slice(
            array_merge($out['agentResponses'], $thisTurnAgentResponses),
            -40,
        );
        $out['whiteboardLedger'] = array_slice(
            array_merge($out['whiteboardLedger'], $serverLedgerDelta),
            -50,
        );

        return $out;
    }
}
