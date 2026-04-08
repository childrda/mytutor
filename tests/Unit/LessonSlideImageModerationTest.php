<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\LessonSlideImageModeration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonSlideImageModerationTest extends TestCase
{
    #[Test]
    public function soften_prefixes_original(): void
    {
        $out = LessonSlideImageModeration::soften('A diagram of lift.');
        $this->assertStringStartsWith('Minimal flat vector diagram', $out);
        $this->assertStringContainsString('A diagram of lift.', $out);
    }

    #[Test]
    public function minimal_safe_prompt_uses_title_not_long_alt(): void
    {
        $out = LessonSlideImageModeration::minimalSafePrompt('What Makes Flight Possible?', 'aviation unit', 'en');
        $this->assertStringContainsString('What Makes Flight Possible?', $out);
        $this->assertStringContainsString('aviation unit', $out);
        $this->assertStringNotContainsString('commercial jet', $out);
        $this->assertStringNotContainsString('bird', $out);
    }
}
