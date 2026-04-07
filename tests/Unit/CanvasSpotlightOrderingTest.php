<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\CanvasSpotlightOrdering;
use PHPUnit\Framework\TestCase;

class CanvasSpotlightOrderingTest extends TestCase
{
    public function test_orders_images_before_cards_before_text_then_by_position(): void
    {
        $canvas = [
            'elements' => [
                ['type' => 'card', 'id' => 'c_right', 'x' => 500, 'y' => 100],
                ['type' => 'image', 'id' => 'img', 'x' => 40, 'y' => 100],
                ['type' => 'text', 'id' => 't1', 'x' => 48, 'y' => 400],
            ],
        ];

        $ids = CanvasSpotlightOrdering::spotlightElementIds($canvas);

        $this->assertSame(['img', 'c_right', 't1'], $ids);
    }

    public function test_sorts_same_type_by_y_then_x(): void
    {
        $canvas = [
            'elements' => [
                ['type' => 'card', 'id' => 'c2', 'x' => 100, 'y' => 200],
                ['type' => 'card', 'id' => 'c1', 'x' => 50, 'y' => 200],
                ['type' => 'card', 'id' => 'c0', 'x' => 300, 'y' => 100],
            ],
        ];

        $ids = CanvasSpotlightOrdering::spotlightElementIds($canvas);

        $this->assertSame(['c0', 'c1', 'c2'], $ids);
    }
}
