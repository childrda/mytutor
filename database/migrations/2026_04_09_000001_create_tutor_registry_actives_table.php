<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_registry_actives', function (Blueprint $table) {
            $table->id();
            $table->string('capability', 32)->unique();
            $table->string('active_key', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_registry_actives');
    }
};
