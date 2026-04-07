<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_generation_jobs', function (Blueprint $table) {
            $table->string('phase', 48)->default('queued')->after('status');
            $table->unsignedTinyInteger('progress')->default(0)->after('phase');
            $table->json('phase_detail')->nullable()->after('progress');
        });

        DB::table('lesson_generation_jobs')->where('status', 'completed')->update([
            'phase' => 'completed',
            'progress' => 100,
        ]);
        DB::table('lesson_generation_jobs')->where('status', 'failed')->update([
            'phase' => 'failed',
        ]);
        DB::table('lesson_generation_jobs')->where('status', 'running')->update([
            'phase' => 'page_content',
            'progress' => 40,
        ]);
    }

    public function down(): void
    {
        Schema::table('lesson_generation_jobs', function (Blueprint $table) {
            $table->dropColumn(['phase', 'progress', 'phase_detail']);
        });
    }
};
