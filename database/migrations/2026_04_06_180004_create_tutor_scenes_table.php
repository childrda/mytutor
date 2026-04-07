<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_scenes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tutor_lesson_id')->constrained('tutor_lessons')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->unsignedInteger('scene_order')->default(0);
            $table->json('content');
            $table->json('actions')->nullable();
            $table->json('whiteboard')->nullable();
            $table->json('multi_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_scenes');
    }
};
