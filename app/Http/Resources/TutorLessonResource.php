<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TutorLesson
 */
class TutorLessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'language' => $this->language,
            'style' => $this->style,
            'currentSceneId' => $this->current_scene_id,
            'agentIds' => $this->agent_ids,
            'meta' => $this->meta,
            'createdAt' => $this->created_at?->getTimestampMs(),
            'updatedAt' => $this->updated_at?->getTimestampMs(),
        ];

        if ($this->relationLoaded('scenes')) {
            $base['scenes'] = TutorSceneResource::collection($this->scenes);
        }

        return $base;
    }
}
