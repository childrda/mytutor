<?php

namespace App\Support\LessonGeneration;

use Illuminate\Support\Str;

/**
 * Normalizes LLM (or template) output into meta.classroomRoles shape for lessons and generation jobs.
 *
 * @phpstan-type Persona array{id: string, role: string, name: string, bio: string, accentColor?: string}
 */
final class ClassroomRolesNormalizer
{
    public const VERSION = 1;

    public const ROLES = ['teacher', 'assistant', 'student'];

    /**
     * @return array{version: int, personas: list<Persona>}
     */
    public static function normalize(?array $raw, string $requirement, string $lessonName): array
    {
        $personas = [];
        if (is_array($raw)) {
            $list = $raw['personas'] ?? null;
            if (is_array($list)) {
                foreach ($list as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $p = self::normalizePersona($row);
                    if ($p !== null) {
                        $personas[] = $p;
                    }
                }
            }
        }

        if ($personas === []) {
            return self::fallback($requirement, $lessonName);
        }

        $version = 1;
        if (is_array($raw) && isset($raw['version']) && is_numeric($raw['version'])) {
            $version = max(1, (int) $raw['version']);
        }

        return [
            'version' => $version,
            'personas' => array_values($personas),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return Persona|null
     */
    private static function normalizePersona(array $row): ?array
    {
        $role = isset($row['role']) && is_string($row['role']) ? strtolower(trim($row['role'])) : '';
        if (! in_array($role, self::ROLES, true)) {
            $role = 'student';
        }

        $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
        if ($name === '') {
            $name = match ($role) {
                'teacher' => 'Lead instructor',
                'assistant' => 'Teaching assistant',
                default => 'Student',
            };
        }

        $bio = isset($row['bio']) && is_string($row['bio']) ? trim($row['bio']) : '';
        if ($bio === '') {
            $bio = 'Participates in this lesson.';
        }
        if (mb_strlen($bio) > 500) {
            $bio = mb_substr($bio, 0, 497).'…';
        }

        $id = isset($row['id']) && is_string($row['id']) && $row['id'] !== '' ? $row['id'] : (string) Str::ulid();

        $out = [
            'id' => $id,
            'role' => $role,
            'name' => $name,
            'bio' => $bio,
        ];

        $color = isset($row['accentColor']) && is_string($row['accentColor']) ? trim($row['accentColor']) : '';
        if ($color !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $out['accentColor'] = strtoupper($color);
        }

        return $out;
    }

    /**
     * @return array{version: int, personas: list<Persona>}
     */
    public static function fallback(string $requirement, string $lessonName): array
    {
        $topic = trim($lessonName) !== '' ? trim($lessonName) : 'this topic';
        $snippet = trim($requirement) !== '' ? mb_substr(trim($requirement), 0, 120) : 'the learning goals you described.';

        return [
            'version' => self::VERSION,
            'personas' => [
                [
                    'id' => (string) Str::ulid(),
                    'role' => 'teacher',
                    'name' => 'Lead instructor',
                    'bio' => 'Guides instruction on '.$topic.'.',
                    'accentColor' => '#4F46E5',
                ],
                [
                    'id' => (string) Str::ulid(),
                    'role' => 'assistant',
                    'name' => 'Teaching assistant',
                    'bio' => 'Supports practice, checks understanding, and answers questions.',
                    'accentColor' => '#0D9488',
                ],
                [
                    'id' => (string) Str::ulid(),
                    'role' => 'student',
                    'name' => 'Alex',
                    'bio' => 'Engages with: '.$snippet,
                    'accentColor' => '#EA580C',
                ],
                [
                    'id' => (string) Str::ulid(),
                    'role' => 'student',
                    'name' => 'Jordan',
                    'bio' => 'Works through examples and discusses ideas with the class.',
                    'accentColor' => '#7C3AED',
                ],
            ],
        ];
    }
}
