<?php

use App\Http\Controllers\Api\AzureVoicesController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\Generate\StubGenerateController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LessonGenerationController;
use App\Http\Controllers\Api\ParsePdfController;
use App\Http\Controllers\Api\ProjectTutorChatController;
use App\Http\Controllers\Api\ProxyMediaController;
use App\Http\Controllers\Api\PublishedLessonController;
use App\Http\Controllers\Api\PublishedLessonMediaController;
use App\Http\Controllers\Api\QuizGradeController;
use App\Http\Controllers\Api\TranscriptionController;
use App\Http\Controllers\Api\StudioSceneGenerationController;
use App\Http\Controllers\Api\VideoGenerationController;
use App\Http\Controllers\Api\VerifyIntegrationController;
use App\Http\Controllers\Api\WebSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');
Route::get('/integrations', IntegrationController::class)->name('api.integrations');

Route::post('/chat', ChatController::class)
    ->middleware(['auth:sanctum', 'throttle.tutor:chat'])
    ->name('api.chat');
Route::post('/web-search', WebSearchController::class)->name('api.web-search');
Route::post('/quiz-grade', QuizGradeController::class)->name('api.quiz-grade');
Route::post('/proxy-media', ProxyMediaController::class)->name('api.proxy-media');
Route::post('/parse-pdf', ParsePdfController::class)
    ->middleware(['auth:sanctum', 'throttle.tutor:parse_pdf'])
    ->name('api.parse-pdf');
Route::post('/transcription', TranscriptionController::class)->name('api.transcription');
Route::post('/project-tutor/chat', ProjectTutorChatController::class)->name('api.project-tutor.chat');

Route::post('/published-lessons', [PublishedLessonController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle.tutor:publish'])
    ->name('api.published-lessons.store');
Route::get('/published-lessons', [PublishedLessonController::class, 'show'])->name('api.published-lessons.show');
Route::get('/published-lessons/{lessonId}/files/{path}', [PublishedLessonMediaController::class, 'show'])
    ->where('path', '.*')
    ->name('api.published-lessons.files');

Route::middleware(['auth:sanctum', 'throttle.tutor:generate_lesson'])->group(function (): void {
    Route::post('/generate-lesson', [LessonGenerationController::class, 'store'])->name('api.generate-lesson.store');
    Route::get('/generate-lesson/{jobId}', [LessonGenerationController::class, 'show'])->name('api.generate-lesson.show');
});

Route::middleware(['throttle.generate'])->prefix('generate')->group(function (): void {
    Route::post('/image', [StubGenerateController::class, 'image']);
    Route::post('/video', [VideoGenerationController::class, 'store']);
    Route::get('/video/{jobId}', [VideoGenerationController::class, 'show']);
    Route::post('/tts', [StubGenerateController::class, 'tts']);
    Route::post('/scene-outlines-stream', [StudioSceneGenerationController::class, 'sceneOutlinesStream']);
    Route::post('/scene-actions', [StudioSceneGenerationController::class, 'sceneActions']);
    Route::post('/scene-content', [StudioSceneGenerationController::class, 'sceneContent']);
    Route::post('/agent-profiles', [StudioSceneGenerationController::class, 'agentProfiles']);
});

Route::prefix('verify')->group(function (): void {
    Route::post('/model', [VerifyIntegrationController::class, 'model']);
    Route::post('/image-provider', [VerifyIntegrationController::class, 'image']);
    Route::post('/video-provider', [VerifyIntegrationController::class, 'video']);
    Route::post('/pdf-provider', [VerifyIntegrationController::class, 'pdf']);
});

Route::get('/azure-voices', [AzureVoicesController::class, 'index']);
