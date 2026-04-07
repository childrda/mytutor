<?php

namespace App\Http\Requests\Tutor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTutorSceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scene = $this->route('scene');

        return $scene && $this->user()?->can('update', $scene);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(['slide', 'quiz', 'interactive', 'pbl'])],
            'title' => ['sometimes', 'string', 'max:500'],
            'order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'content' => ['sometimes', 'array'],
            'actions' => ['nullable', 'array'],
            'whiteboards' => ['nullable', 'array'],
            'multiAgent' => ['nullable', 'array'],
        ];
    }
}
