<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape aligned with the reference classroom “scene” model (camelCase, ms timestamps).
 *
 * @mixin \App\Models\TutorScene
 */
class TutorSceneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stageId' => $this->tutor_lesson_id,
            'type' => $this->type,
            'title' => $this->title,
            'order' => (int) $this->scene_order,
            'content' => $this->content ?? [],
            'actions' => $this->actions,
            'whiteboards' => $this->whiteboard,
            'multiAgent' => $this->multi_agent,
            'createdAt' => $this->created_at?->getTimestampMs(),
            'updatedAt' => $this->updated_at?->getTimestampMs(),
        ];
    }
}
