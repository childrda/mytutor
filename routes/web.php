<?php

use App\Http\Controllers\Tutor\TutorLessonController;
use App\Http\Controllers\Tutor\TutorLessonExportController;
use App\Http\Controllers\Tutor\TutorLessonImportController;
use App\Http\Controllers\Tutor\TutorSceneController;
use App\Http\Controllers\Web\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Web\Auth\RegisteredUserController;
use App\Http\Controllers\Web\ClassroomLessonController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\LessonGenerationPreviewController;
use App\Http\Controllers\Web\LessonGenerationWebController;
use App\Http\Controllers\Web\LessonStudioController;
use App\Http\Controllers\Web\PublicLessonController;
use App\Http\Controllers\Web\SettingsCatalogController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\SettingsRegistryActiveController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/lesson/{id}', [PublicLessonController::class, 'show'])->name('lesson.public');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('settings', SettingsController::class)->name('settings');
    Route::get('settings/registry-active', [SettingsRegistryActiveController::class, 'show']);
    Route::put('settings/registry-active', [SettingsRegistryActiveController::class, 'update']);

    Route::prefix('settings/catalog')->group(function (): void {
        Route::get('providers', [SettingsCatalogController::class, 'providers']);
        Route::get('models', [SettingsCatalogController::class, 'modelsIndex']);
        Route::get('models/{capability}', [SettingsCatalogController::class, 'modelsForCapability'])
            ->where('capability', '[a-z_]+');
        Route::post('models/{capability}', [SettingsCatalogController::class, 'storeModel'])
            ->where('capability', '[a-z_]+');
        Route::post('models/{capability}/provider-base-url', [SettingsCatalogController::class, 'updateProviderBaseUrl'])
            ->where('capability', '[a-z_]+');
        Route::post('models/{capability}/variant', [SettingsCatalogController::class, 'storeModelVariant'])
            ->where('capability', '[a-z_]+');
        Route::post('models/{capability}/delete-bundle', [SettingsCatalogController::class, 'destroyModelBundle'])
            ->where('capability', '[a-z_]+');
        Route::put('models/{capability}/{id}', [SettingsCatalogController::class, 'updateModel'])
            ->where('capability', '[a-z_]+')
            ->where('id', '[a-zA-Z0-9_.-]+');
        Route::delete('models/{capability}/{id}', [SettingsCatalogController::class, 'destroyModel'])
            ->where('capability', '[a-z_]+')
            ->where('id', '[a-zA-Z0-9_.-]+');
        Route::post('models/{capability}/{id}/test', [SettingsCatalogController::class, 'testModel'])
            ->where('capability', '[a-z_]+')
            ->where('id', '[a-zA-Z0-9_.-]+');
        Route::get('active', [SettingsCatalogController::class, 'activeShow']);
        Route::put('active', [SettingsCatalogController::class, 'activeUpdate']);
    });
    Route::get('studio', [LessonStudioController::class, 'index'])->name('studio');
    Route::get('studio/{lesson}', [LessonStudioController::class, 'show'])->name('studio.lesson');
    Route::get('classroom/{lesson}', [ClassroomLessonController::class, 'show'])->name('classroom.lesson');
    Route::get('generation/{job}', LessonGenerationPreviewController::class)->name('generation.preview');

    Route::prefix('tutor-api')->group(function (): void {
        Route::post('generate-lesson', [LessonGenerationWebController::class, 'store'])
            ->middleware('throttle.tutor:generate_lesson');
        Route::get('lessons', [TutorLessonController::class, 'index']);
        Route::post('lessons', [TutorLessonController::class, 'store']);
        Route::post('lessons/import-from-job', TutorLessonImportController::class);
        Route::get('lessons/{lesson}', [TutorLessonController::class, 'show']);
        Route::patch('lessons/{lesson}', [TutorLessonController::class, 'update']);
        Route::delete('lessons/{lesson}', [TutorLessonController::class, 'destroy']);
        Route::get('lessons/{lesson}/export/html-zip', [TutorLessonExportController::class, 'htmlZip']);

        Route::post('lessons/{lesson}/scenes', [TutorSceneController::class, 'store']);
        Route::patch('lessons/{lesson}/scenes/{scene}', [TutorSceneController::class, 'update']);
        Route::delete('lessons/{lesson}/scenes/{scene}', [TutorSceneController::class, 'destroy']);
        Route::post('lessons/{lesson}/scenes/reorder', [TutorSceneController::class, 'reorder']);
    });
});
