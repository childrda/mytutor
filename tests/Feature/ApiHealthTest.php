<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ok');
    }

    public function test_integrations_endpoint_returns_provider_shapes(): void
    {
        $response = $this->getJson('/api/integrations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'providers',
                'tts',
                'asr',
                'pdf',
                'image',
                'video',
                'webSearch',
            ]);
    }
}
