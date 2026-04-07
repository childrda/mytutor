<?php

namespace App\Http\Requests\Tutor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTutorLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson && $this->user()?->can('update', $lesson);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'language' => ['nullable', 'string', 'max:32'],
            'style' => ['nullable', 'string', 'max:128'],
            'currentSceneId' => ['nullable', 'string', 'max:64'],
            'agentIds' => ['nullable', 'array'],
            'agentIds.*' => ['string', 'max:128'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
