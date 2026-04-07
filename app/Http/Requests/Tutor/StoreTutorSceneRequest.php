<?php

namespace App\Http\Requests\Tutor;

use App\Models\TutorScene;
use App\Support\Tutor\TeachingActionsValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTutorSceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson && $this->user()?->can('create', [TutorScene::class, $lesson]);
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('actions')) {
                return;
            }
            $lesson = $this->route('lesson');
            if (! $lesson) {
                return;
            }
            $content = $this->input('content');
            $type = $this->input('type');
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
