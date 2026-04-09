<?php

namespace Tests\Unit;

use App\Services\Ai\ModelRegistryException;
use App\Services\Ai\ModelRegistryTemplate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelRegistryTemplateTest extends TestCase
{
    #[Test]
    public function response_path_converts_bracket_indices_to_dot_notation(): void
    {
        $this->assertSame(
            'choices.0.message.content',
            ModelRegistryTemplate::responsePathToDataGetKey('choices[0].message.content'),
        );
        $this->assertSame(
            'candidates.0.content.parts.0.text',
            ModelRegistryTemplate::responsePathToDataGetKey('candidates[0].content.parts[0].text'),
        );
    }

    #[Test]
    public function expand_request_format_uses_defaults_and_structured_messages(): void
    {
        $format = [
            'model' => '{model|gpt-4o-mini}',
            'messages' => '{messages}',
            'temperature' => '{temperature|0.7}',
            'max_completion_tokens' => '{max_completion_tokens|2048}',
        ];
        $messages = [['role' => 'user', 'content' => 'hi']];
        $out = ModelRegistryTemplate::expandRequestFormat($format, ['messages' => $messages]);

        $this->assertSame('gpt-4o-mini', $out['model']);
        $this->assertSame($messages, $out['messages']);
        $this->assertSame(0.7, $out['temperature']);
        $this->assertSame(2048, $out['max_completion_tokens']);
    }

    #[Test]
    public function expand_url_interpolates_multiple_placeholders(): void
    {
        $url = ModelRegistryTemplate::expandUrl(
            '{base_url}/v1beta/models/{model|gemini-pro}:generateContent',
            ['base_url' => 'https://generativelanguage.googleapis.com', 'model' => 'gemini-2.0-flash'],
        );
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
            $url,
        );
    }

    #[Test]
    public function missing_required_placeholder_throws(): void
    {
        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('messages');
        ModelRegistryTemplate::expandRequestFormat(['messages' => '{messages}'], []);
    }

    #[Test]
    public function nested_objects_expand(): void
    {
        $format = [
            'generationConfig' => [
                'temperature' => '{temperature|0.5}',
                'maxOutputTokens' => '{max_output_tokens|1024}',
            ],
        ];
        $out = ModelRegistryTemplate::expandRequestFormat($format, []);
        $this->assertSame(0.5, $out['generationConfig']['temperature']);
        $this->assertSame(1024, $out['generationConfig']['maxOutputTokens']);
    }
}
