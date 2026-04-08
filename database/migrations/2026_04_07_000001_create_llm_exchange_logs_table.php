<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_exchange_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('source', 64)->nullable();
            $table->string('correlation_id', 26);
            $table->string('endpoint', 128)->default('/chat/completions');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('payload');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_exchange_logs');
    }
};
