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

        if (is_array($s['scenePosition'] ?? null)) {
            /** @var array{index: int, total: int} $sp */
            $sp = $s['scenePosition'];
            $human = (int) ($sp['index'] ?? 0) + 1;
            $tot = (int) ($sp['total'] ?? 0);
            if ($tot > 0) {
                $lines[] = '- Scene position (1-based, as in the learner UI): '.$human.' of '.$tot;
            }
        }

        if (is_array($s['teachingProgress'] ?? null)) {
            /** @var array{stepIndex: int, stepCount: int, transcriptHeadline: string, transcriptSnippet: string, spotlightElementId: string, spotlightSummary: string} $tp */
            $tp = $s['teachingProgress'];
            $stepHuman = (int) ($tp['stepIndex'] ?? 0) + 1;
            $stepTot = (int) ($tp['stepCount'] ?? 0);
            if ($stepTot > 0) {
                $lines[] = '- Scripted step: '.$stepHuman.' of '.$stepTot.' in the current scene timeline';
            }
            if (($tp['transcriptHeadline'] ?? '') !== '' || ($tp['transcriptSnippet'] ?? '') !== '') {
                $lines[] = '- Current step / transcript cue: '.($tp['transcriptHeadline'] ?? '');
                if (($tp['transcriptSnippet'] ?? '') !== '') {
                    $lines[] = '  '.str_replace("\n", ' ', (string) $tp['transcriptSnippet']);
                }
            }
            if (($tp['spotlightElementId'] ?? '') !== '' || ($tp['spotlightSummary'] ?? '') !== '') {
                $lines[] = '- Visually emphasized on the slide (spotlight): '.($tp['spotlightSummary'] !== ''
                    ? (string) $tp['spotlightSummary']
                    : '(element id: '.($tp['spotlightElementId'] ?? '').')');
            }
        }

        $lines[] = '';
        $lines[] = '## Current scene (ground your answer here when a scene is focused)';
        if (is_array($s['scene'] ?? null)) {
            $sc = $s['scene'];
            $lines[] = '- Scene ID: '.($sc['id'] ?? '');
            $lines[] = '- Title: '.($sc['title'] ?? '');
            $lines[] = '- Type: '.($sc['type'] ?? '');
            $lines[] = '- Content summary (structured text for this scene, when provided):';
            $lines[] = (string) ($sc['contentSummary'] ?? '');
            $lines[] = '';
            $lines[] = 'When the question is vague (e.g. “help me”, “I don’t get it”), infer what the learner is probably looking at from the scene title, position, step/transcript cue, spotlight, and content summary; acknowledge that context in one short phrase, then help.';
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
