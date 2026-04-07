<?php

namespace App\Http\Requests\Tutor;

use Illuminate\Foundation\Http\FormRequest;

class ReorderTutorScenesRequest extends FormRequest
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
            'sceneIds' => ['required', 'array'],
            'sceneIds.*' => ['required', 'string', 'max:64'],
        ];
    }
}
