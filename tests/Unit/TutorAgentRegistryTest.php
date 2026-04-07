<?php

namespace Tests\Unit;

use App\Services\Ai\TutorAgentRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TutorAgentRegistryTest extends TestCase
{
    #[Test]
    public function normalize_agent_ids_defaults_to_tutor_when_empty(): void
    {
        $this->assertSame(['tutor'], TutorAgentRegistry::normalizeAgentIds([]));
    }

    #[Test]
    public function normalize_agent_ids_dedupes_and_skips_empty_strings(): void
    {
        $this->assertSame(
            ['tutor', 'socratic'],
            TutorAgentRegistry::normalizeAgentIds(['tutor', 'tutor', 'socratic', '', 'socratic']),
        );
    }

    #[Test]
    public function resolve_known_agent_returns_config_name_and_persona(): void
    {
        $r = TutorAgentRegistry::resolve('tutor');
        $this->assertSame('Tutor', $r['name']);
        $this->assertNotSame('', $r['persona']);
    }

    #[Test]
    public function resolve_unknown_agent_humanizes_id_and_uses_fallback_persona(): void
    {
        $r = TutorAgentRegistry::resolve('custom-bot');
        $this->assertSame('Custom Bot', $r['name']);
        $this->assertStringContainsString('specialist', $r['persona']);
    }
}
