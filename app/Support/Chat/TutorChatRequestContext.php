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

        $store = [
            'version' => $version,
            'lessonId' => substr($lessonId, 0, 256),
            'lessonName' => substr($lessonName, 0, 512),
            'sessionType' => $sessionType,
            'language' => $language,
            'scene' => $scene,
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

    private static function truncateUtf8(string $s, int $maxBytes): string
    {
        if (strlen($s) <= $maxBytes) {
            return $s;
        }

        return substr($s, 0, $maxBytes).'…';
    }
}
