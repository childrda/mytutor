<?php

namespace App\Services\Ai;

final class TutorAgentRegistry
{
    /**
     * @return array{name: string, persona: string}
     */
    public static function resolve(string $agentId): array
    {
        $agents = config('tutor.agents', []);
        if (isset($agents[$agentId]) && is_array($agents[$agentId])) {
            return [
                'name' => (string) ($agents[$agentId]['name'] ?? self::humanize($agentId)),
                'persona' => (string) ($agents[$agentId]['persona'] ?? ''),
            ];
        }

        return [
            'name' => self::humanize($agentId),
            'persona' => 'You are a specialist agent in this lesson. Keep answers concise and grounded in the provided context.',
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function normalizeAgentIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '' && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out !== [] ? $out : ['tutor'];
    }

    private static function humanize(string $agentId): string
    {
        $s = str_replace(['_', '-'], ' ', $agentId);

        return $s !== '' ? ucwords($s) : 'Agent';
    }
}
