<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_generation_jobs', function (Blueprint $table) {
            $table->json('classroom_roles')->nullable()->after('phase_detail');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_generation_jobs', function (Blueprint $table) {
            $table->dropColumn('classroom_roles');
        });
    }
};
