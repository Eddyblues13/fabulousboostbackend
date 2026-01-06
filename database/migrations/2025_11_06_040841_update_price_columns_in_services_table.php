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
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('api_provider_price', 15, 4)->nullable()->change();
            $table->decimal('rate_per_1000', 15, 4)->nullable()->change();
            $table->decimal('min_amount', 15, 2)->nullable()->change();
            $table->decimal('max_amount', 15, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('api_provider_price', 10, 2)->nullable()->change();
            $table->string('rate_per_1000', 255)->nullable()->change();
            $table->integer('min_amount')->nullable()->change();
            $table->integer('max_amount')->nullable()->change();
        });
    }
};
