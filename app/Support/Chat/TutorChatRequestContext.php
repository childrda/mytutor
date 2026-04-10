<?php

namespace App\Support\Chat;

/**
 * Parsed / normalized chat request context from POST /api/chat JSON (Phase 3.4).
 */
final class TutorChatRequestContext
{
    /**
     * @param  array<string, mixed>  $store  Normalized lesson / scene / session fields
     * @param  array<string, mixed>  $director  Sanitized directorState for prompts
     */
    public function __construct(
        public readonly array $store,
        public readonly array $director,
    ) {}

    /**
     * @param  array<string, mixed>  $body  Decoded JSON body
     */
    public static function fromRequestBody(array $body): self
    {
        $storeRaw = isset($body['storeState']) && is_array($body['storeState']) ? $body['storeState'] : [];
        $config = isset($body['config']) && is_array($body['config']) ? $body['config'] : [];

        $sessionFromConfig = isset($config['sessionType']) && is_string($config['sessionType'])
            ? trim($config['sessionType'])
            : '';

        $lessonId = isset($storeRaw['lessonId']) && is_string($storeRaw['lessonId'])
            ? trim($storeRaw['lessonId'])
            : '';
        $lessonName = isset($storeRaw['lessonName']) && is_string($storeRaw['lessonName'])
            ? trim($storeRaw['lessonName'])
            : '';

        $sessionType = $sessionFromConfig !== ''
            ? $sessionFromConfig
            : (isset($storeRaw['sessionType']) && is_string($storeRaw['sessionType'])
                ? trim($storeRaw['sessionType'])
                : 'qa');
        if (! in_array($sessionType, ['qa', 'discussion', 'lecture'], true)) {
            $sessionType = 'qa';
        }

        $language = isset($storeRaw['language']) && is_string($storeRaw['language'])
            ? substr(trim($storeRaw['language']), 0, 32)
            : 'en';
        if ($language === '') {
            $language = 'en';
        }

        $version = isset($storeRaw['version']) && is_numeric($storeRaw['version'])
            ? max(1, (int) $storeRaw['version'])
            : 1;

        $scene = self::parseScene($storeRaw['scene'] ?? null);
        $scenePosition = self::parseScenePosition($storeRaw['scenePosition'] ?? null);
        $teachingProgress = self::parseTeachingProgress($storeRaw['teachingProgress'] ?? null);

        $store = [
            'version' => $version,
            'lessonId' => substr($lessonId, 0, 256),
            'lessonName' => substr($lessonName, 0, 512),
            'sessionType' => $sessionType,
            'language' => $language,
            'scene' => $scene,
            'scenePosition' => $scenePosition,
            'teachingProgress' => $teachingProgress,
        ];

        $director = TutorChatDirectorState::sanitizeIncoming($body['directorState'] ?? null);

        return new self($store, $director);
    }

    /**
     * @return ?array{id: string, title: string, type: string, contentSummary: string}
     */
    private static function parseScene(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $id = isset($raw['id']) && is_string($raw['id']) ? substr(trim($raw['id']), 0, 128) : '';
        $title = isset($raw['title']) && is_string($raw['title']) ? substr(trim($raw['title']), 0, 512) : '';
        $type = isset($raw['type']) && is_string($raw['type']) ? substr(trim($raw['type']), 0, 64) : '';
        $summary = isset($raw['contentSummary']) && is_string($raw['contentSummary'])
            ? self::truncateUtf8($raw['contentSummary'], 12_000)
            : '';

        if ($id === '' && $title === '' && $type === '' && $summary === '') {
            return null;
        }

        return [
            'id' => $id,
            'title' => $title,
            'type' => $type,
            'contentSummary' => $summary,
        ];
    }

    /**
     * @return ?array{index: int, total: int}
     */
    private static function parseScenePosition(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $index = isset($raw['index']) && is_numeric($raw['index']) ? (int) $raw['index'] : null;
        $total = isset($raw['total']) && is_numeric($raw['total']) ? (int) $raw['total'] : null;
        if ($index === null || $total === null || $total < 1 || $total > 10_000) {
            return null;
        }

        if ($index < 0) {
            $index = 0;
        }
        if ($index >= $total) {
            $index = $total - 1;
        }

        return ['index' => $index, 'total' => $total];
    }

    /**
     * @return ?array{stepIndex: int, stepCount: int, transcriptHeadline: string, transcriptSnippet: string, spotlightElementId: string, spotlightSummary: string}
     */
    private static function parseTeachingProgress(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $stepIndex = isset($raw['stepIndex']) && is_numeric($raw['stepIndex']) ? (int) $raw['stepIndex'] : null;
        $stepCount = isset($raw['stepCount']) && is_numeric($raw['stepCount']) ? (int) $raw['stepCount'] : null;
        if ($stepIndex === null || $stepCount === null || $stepCount < 1 || $stepCount > 500) {
            return null;
        }

        if ($stepIndex < 0) {
            $stepIndex = 0;
        }
        if ($stepIndex >= $stepCount) {
            $stepIndex = $stepCount - 1;
        }

        $headline = isset($raw['transcriptHeadline']) && is_string($raw['transcriptHeadline'])
            ? self::truncateUtf8(trim($raw['transcriptHeadline']), 256)
            : '';
        $snippet = isset($raw['transcriptSnippet']) && is_string($raw['transcriptSnippet'])
            ? self::truncateUtf8(trim($raw['transcriptSnippet']), 800)
            : '';
        $spotEl = isset($raw['spotlightElementId']) && is_string($raw['spotlightElementId'])
            ? substr(trim($raw['spotlightElementId']), 0, 128)
            : '';
        $spotSum = isset($raw['spotlightSummary']) && is_string($raw['spotlightSummary'])
            ? self::truncateUtf8(trim($raw['spotlightSummary']), 800)
            : '';

        return [
            'stepIndex' => $stepIndex,
            'stepCount' => $stepCount,
            'transcriptHeadline' => $headline,
            'transcriptSnippet' => $snippet,
            'spotlightElementId' => $spotEl,
            'spotlightSummary' => $spotSum,
        ];
    }

    private static function truncateUtf8(string $s, int $maxBytes): string
    {
        if (strlen($s) <= $maxBytes) {
            return $s;
        }

        return substr($s, 0, $maxBytes).'…';
    }
}
