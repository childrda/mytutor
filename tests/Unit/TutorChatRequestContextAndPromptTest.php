<?php

namespace Tests\Unit;

use App\Support\Chat\TutorChatPromptBuilder;
use App\Support\Chat\TutorChatRequestContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TutorChatRequestContextAndPromptTest extends TestCase
{
    #[Test]
    public function scene_title_appears_in_prompt_grounding_section(): void
    {
        $ctx = TutorChatRequestContext::fromRequestBody([
            'storeState' => [
                'lessonId' => 'les-1',
                'lessonName' => 'Algebra',
                'sessionType' => 'qa',
                'language' => 'en',
                'scene' => [
                    'id' => 'sc-1',
                    'title' => 'Quadratic intro',
                    'type' => 'lecture',
                    'contentSummary' => '{"blocks":[]}',
                ],
            ],
            'config' => ['agentIds' => ['tutor']],
            'directorState' => ['turnCount' => 0, 'agentResponses' => [], 'whiteboardLedger' => []],
        ]);

        $prompt = TutorChatPromptBuilder::build($ctx);

        $this->assertStringContainsString('Quadratic intro', $prompt);
        $this->assertStringContainsString('sc-1', $prompt);
        $this->assertStringContainsString('Algebra', $prompt);
        $this->assertStringContainsString('## Current scene', $prompt);
    }

    #[Test]
    public function config_session_type_overrides_store_state(): void
    {
        $ctx = TutorChatRequestContext::fromRequestBody([
            'storeState' => [
                'lessonId' => 'x',
                'sessionType' => 'lecture',
            ],
            'config' => [
                'sessionType' => 'discussion',
                'agentIds' => ['tutor'],
            ],
        ]);

        $this->assertSame('discussion', $ctx->store['sessionType']);
    }

    #[Test]
    public function invalid_session_type_falls_back_to_qa(): void
    {
        $ctx = TutorChatRequestContext::fromRequestBody([
            'storeState' => [
                'lessonId' => 'x',
                'sessionType' => 'not-a-mode',
            ],
            'config' => ['agentIds' => ['tutor']],
        ]);

        $this->assertSame('qa', $ctx->store['sessionType']);
    }

    #[Test]
    public function no_scene_section_explains_lesson_level_context(): void
    {
        $ctx = TutorChatRequestContext::fromRequestBody([
            'storeState' => [
                'lessonId' => 'x',
                'lessonName' => 'Y',
            ],
            'config' => ['agentIds' => ['tutor']],
        ]);

        $prompt = TutorChatPromptBuilder::build($ctx);
        $this->assertStringContainsString('No scene is currently focused', $prompt);
    }

    #[Test]
    public function director_state_json_is_embedded_in_prompt(): void
    {
        $ctx = TutorChatRequestContext::fromRequestBody([
            'storeState' => ['lessonId' => 'z'],
            'config' => ['agentIds' => ['tutor']],
            'directorState' => [
                'turnCount' => 3,
                'agentResponses' => [['agentId' => 'tutor', 'content' => 'hi']],
                'whiteboardLedger' => [],
            ],
        ]);

        $prompt = TutorChatPromptBuilder::build($ctx);
        $this->assertStringContainsString('"turnCount":3', $prompt);
        $this->assertStringContainsString('## Director state', $prompt);
    }

    #[Test]
    public function scene_position_and_teaching_progress_appear_in_prompt(): void
    {
        $ctx = TutorChatRequestContext::fromRequestBody([
            'storeState' => [
                'lessonId' => 'les-1',
                'lessonName' => 'Media literacy',
                'scenePosition' => ['index' => 2, 'total' => 15],
                'teachingProgress' => [
                    'stepIndex' => 0,
                    'stepCount' => 8,
                    'transcriptHeadline' => 'Speech',
                    'transcriptSnippet' => 'Today we discuss bias.',
                    'spotlightElementId' => 'card-1',
                    'spotlightSummary' => 'Confirmation bias — favors agreeing evidence.',
                ],
                'scene' => [
                    'id' => 'sc-3',
                    'title' => 'Types of bias',
                    'type' => 'slide',
                    'contentSummary' => 'Slide title: Types of bias',
                ],
            ],
            'config' => ['agentIds' => ['tutor']],
            'directorState' => ['turnCount' => 0, 'agentResponses' => [], 'whiteboardLedger' => []],
        ]);

        $prompt = TutorChatPromptBuilder::build($ctx);

        $this->assertStringContainsString('Scene position (1-based', $prompt);
        $this->assertStringContainsString('3 of 15', $prompt);
        $this->assertStringContainsString('Scripted step: 1 of 8', $prompt);
        $this->assertStringContainsString('Confirmation bias', $prompt);
        $this->assertStringContainsString('infer what the learner is probably looking at', $prompt);
    }

    #[Test]
    public function scene_position_index_is_clamped_to_total(): void
    {
        $ctx = TutorChatRequestContext::fromRequestBody([
            'storeState' => [
                'lessonId' => 'x',
                'scenePosition' => ['index' => 99, 'total' => 3],
            ],
            'config' => ['agentIds' => ['tutor']],
        ]);

        $this->assertSame(2, $ctx->store['scenePosition']['index']);
        $this->assertSame(3, $ctx->store['scenePosition']['total']);
    }
}
