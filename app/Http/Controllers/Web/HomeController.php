<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\TutorLessonResource;
use App\Models\TutorLesson;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lessons = [];
        if ($request->user()) {
            $lessons = TutorLessonResource::collection(
                TutorLesson::query()
                    ->where('user_id', $request->user()->id)
                    ->orderByDesc('updated_at')
                    ->get(),
            )->resolve();
        }

        return Inertia::render('Home', [
            'healthUrl' => url('/api/health'),
            'lessons' => $lessons,
            'languageOptions' => [
                ['value' => 'en', 'label' => 'English'],
                ['value' => 'es', 'label' => 'Español'],
                ['value' => 'fr', 'label' => 'Français'],
                ['value' => 'de', 'label' => 'Deutsch'],
            ],
        ]);
    }
}
