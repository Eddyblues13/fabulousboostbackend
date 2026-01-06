<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_providers', function (Blueprint $table) {
            $table->id();
            $table->string('api_name', 191)->nullable();
            $table->string('url', 191)->nullable();
            $table->string('api_key', 191)->nullable();
            $table->string('balance', 191)->nullable();
            $table->string('currency', 191)->nullable();
            $table->string('convention_rate', 20)->default('1');
            $table->tinyInteger('status')->nullable();
            $table->longText('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_providers');
    }
};
