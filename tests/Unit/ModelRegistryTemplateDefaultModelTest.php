<?php

namespace Tests\Unit;

use App\Services\Ai\ModelRegistryTemplate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelRegistryTemplateDefaultModelTest extends TestCase
{
    #[Test]
    public function reads_default_from_request_format_model(): void
    {
        $entry = [
            'request_format' => [
                'model' => '{model|gpt-4o-mini}',
                'messages' => '{messages}',
            ],
        ];
        $this->assertSame('gpt-4o-mini', ModelRegistryTemplate::defaultModelIdFromEntry($entry));
    }

    #[Test]
    public function reads_default_from_endpoint_when_model_not_in_body(): void
    {
        $entry = [
            'endpoint' => '{base_url}/v1beta/models/{model|gemini-2.0-flash}:generateContent',
            'request_format' => [
                'contents' => '{contents}',
            ],
        ];
        $this->assertSame('gemini-2.0-flash', ModelRegistryTemplate::defaultModelIdFromEntry($entry));
    }

    #[Test]
    public function returns_null_when_no_placeholder(): void
    {
        $this->assertNull(ModelRegistryTemplate::defaultModelIdFromEntry(['request_format' => ['model' => '{model}']]));
    }
}
