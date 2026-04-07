<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_lessons', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->json('document');
            $table->timestamp('published_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_lessons');
    }
};
