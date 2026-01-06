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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('service_id')->nullable();
            $table->integer('api_order_id')->nullable();
            $table->integer('api_refill_id')->nullable();
            $table->string('link', 255)->nullable();
            $table->integer('quantity')->nullable();
            $table->double('price', 18, 8)->nullable();
            $table->string('status', 191)->nullable();
            $table->string('refill_status', 20)->nullable();
            $table->string('status_description', 191)->nullable();
            $table->text('reason')->nullable();
            $table->tinyInteger('agree')->nullable();
            $table->bigInteger('start_counter')->nullable();
            $table->bigInteger('remains')->nullable();
            $table->tinyInteger('runs')->nullable();
            $table->tinyInteger('interval')->nullable();
            $table->tinyInteger('drip_feed')->nullable();
            $table->timestamp('refilled_at')->nullable();
            $table->timestamp('added_on')->useCurrent();
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('category_id');
            $table->index('service_id');
            $table->index('api_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
