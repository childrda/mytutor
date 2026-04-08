<?php

namespace App\Support\LessonGeneration;

/**
 * Shared prompts when the image API rejects content (content_policy_violation).
 */
final class LessonSlideImageModeration
{
    /**
     * Prefix the original prompt with constraints (may still contain disallowed wording from the original).
     */
    public static function soften(string $original): string
    {
        $o = trim($original);
        if ($o === '') {
            return '';
        }

        $prefix = 'Minimal flat vector diagram for a printed high school worksheet. '
            .'Use only simple geometric shapes, one neutral streamlined silhouette, and bold labeled arrows. '
            .'No photorealistic detail, no identifiable people, no cockpit interiors, no weapons. ';

        return mb_substr($prefix.$o, 0, 3800);
    }

    /**
     * Last-resort prompt: does not embed the model's long "alt" DALL·E text (often what tripped moderation).
     */
    public static function minimalSafePrompt(string $slideTitle, string $requirement, string $language): string
    {
        $title = trim($slideTitle) !== '' ? trim($slideTitle) : 'lesson topic';
        $parts = [
            'Extremely simple educational line diagram for a printed classroom handout.',
            'Concept for the slide: '.$title.'.',
            'Show one abstract neutral shape (rounded rectangle or simple streamlined blob) with 2–5 very short text labels nearby.',
            'No photographs, no realistic vehicles, no animals, no people, no airline branding, no cockpit views, no weapons.',
            'Black outlines, flat color fills, white background, textbook vector style.',
        ];
        $req = trim(mb_substr($requirement, 0, 220));
        if ($req !== '') {
            $parts[] = 'Course theme (suggest with words and arrows only; do not draw literal vehicles or animals): '.$req.'.';
        }
        if ($language !== '' && strtolower($language) !== 'en') {
            $parts[] = 'Short labels may use '.$language.' where natural.';
        }

        return mb_substr(implode(' ', $parts), 0, 3800);
    }
}
