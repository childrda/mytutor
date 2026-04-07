<?php

namespace App\Support\Chat;

/**
 * Builds the system preamble for POST /api/chat from normalized context (Phase 3.4).
 */
final class TutorChatPromptBuilder
{
    private const int MAX_DIRECTOR_JSON_BYTES = 8_000;

    public static function build(TutorChatRequestContext $ctx): string
    {
        $s = $ctx->store;
        $lines = [
            'You are an expert tutor in an interactive lesson. Stay helpful, accurate, and concise.',
            '',
            '## Lesson context',
            '- Lesson: '.($s['lessonName'] !== '' ? $s['lessonName'] : '(unnamed)').' (`'.$s['lessonId'].'`)',
            '- Session mode: '.$s['sessionType'].' (qa = direct Q&A, discussion = collaborative, lecture = explanatory)',
            '- Learner UI language code: '.$s['language'],
            '- Client context version: '.(string) ($s['version'] ?? 1),
        ];

        $lines[] = '';
        $lines[] = '## Current scene (ground your answer here when a scene is focused)';
        if (is_array($s['scene'] ?? null)) {
            $sc = $s['scene'];
            $lines[] = '- Scene ID: '.($sc['id'] ?? '');
            $lines[] = '- Title: '.($sc['title'] ?? '');
            $lines[] = '- Type: '.($sc['type'] ?? '');
            $lines[] = '- Content summary (JSON excerpt for this scene):';
            $lines[] = (string) ($sc['contentSummary'] ?? '');
        } else {
            $lines[] = 'No scene is currently focused; stay at the overall lesson level unless the learner specifies otherwise.';
        }

        $lines[] = '';
        $lines[] = '## Director state (round-trip from the client; may include prior turns)';
        $dirJson = json_encode($ctx->director, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($dirJson === false) {
            $dirJson = '{}';
        }
        if (strlen($dirJson) > self::MAX_DIRECTOR_JSON_BYTES) {
            $dirJson = substr($dirJson, 0, self::MAX_DIRECTOR_JSON_BYTES).'…';
        }
        $lines[] = $dirJson;

        return implode("\n", $lines);
    }
}
