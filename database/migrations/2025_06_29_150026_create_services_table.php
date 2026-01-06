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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('service_title', 191)->nullable();

            // Category relationship
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->string('link', 191)->nullable();
            $table->string('username', 191)->nullable();
            $table->integer('min_amount')->nullable();
            $table->integer('max_amount')->nullable();

            $table->decimal('price', 10, 2)->nullable(); // Changed from string to decimal
            $table->double('price_percentage_increase', 8, 2)->default(1.00);
            $table->tinyInteger('service_status')->nullable();
            $table->string('service_type', 191)->nullable();
            $table->longText('description')->nullable();
            $table->string('rate_per_1000', 255)->nullable();
            $table->string('average_time', 255)->nullable();
            

            // API Provider relationship
            $table->foreignId('api_provider_id')
                ->nullable()
                ->constrained('api_providers')
                ->nullOnDelete();

            $table->integer('api_service_id')->nullable();
            $table->decimal('api_provider_price', 10, 2)->nullable(); // Changed from string to decimal

            $table->boolean('drip_feed')->nullable();
            $table->boolean('refill')->default(0);
            $table->boolean('is_refill_automatic')->default(0);

            // Indexes
            $table->index('category_id');
            $table->index('api_provider_id');
            $table->index('service_status');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
