<?php

namespace App\Http\Requests\Tutor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTutorSceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson && $this->user()?->can('create', [\App\Models\TutorScene::class, $lesson]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['slide', 'quiz', 'interactive', 'pbl'])],
            'title' => ['required', 'string', 'max:500'],
            'order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'content' => ['required', 'array'],
            'actions' => ['nullable', 'array'],
            'whiteboards' => ['nullable', 'array'],
            'multiAgent' => ['nullable', 'array'],
        ];
    }
}
