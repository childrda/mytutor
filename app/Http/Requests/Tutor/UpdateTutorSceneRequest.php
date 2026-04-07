<?php

namespace App\Http\Requests\Tutor;

use App\Support\Tutor\TeachingActionsValidator;
use Illuminate\Contracts\Validation\Validator;
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('actions')) {
                return;
            }
            $scene = $this->route('scene');
            $lesson = $this->route('lesson');
            if (! $scene || ! $lesson) {
                return;
            }
            $content = $this->has('content') ? $this->input('content') : $scene->content;
            $type = $this->has('type') ? $this->input('type') : $scene->type;
            $messages = TeachingActionsValidator::messagesFor(
                $this->input('actions'),
                is_array($content) ? $content : null,
                is_array($lesson->meta) ? $lesson->meta : null,
                is_string($type) ? $type : null,
            );
            foreach ($messages as $msg) {
                $v->errors()->add('actions', $msg);
            }
        });
    }
}
