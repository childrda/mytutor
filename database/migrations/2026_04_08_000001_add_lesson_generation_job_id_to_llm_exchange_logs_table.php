<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_exchange_logs', function (Blueprint $table) {
            $table->string('lesson_generation_job_id', 26)->nullable()->after('user_id');
            $table->index('lesson_generation_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('llm_exchange_logs', function (Blueprint $table) {
            $table->dropIndex(['lesson_generation_job_id']);
            $table->dropColumn('lesson_generation_job_id');
        });
    }
};
