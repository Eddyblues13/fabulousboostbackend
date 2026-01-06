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
        Schema::create('affiliate_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_program_id')->constrained()->onDelete('cascade');
            $table->integer('visits')->default(0);
            $table->integer('registrations')->default(0);
            $table->integer('referrals')->default(0);
            $table->decimal('conversion_rate', 5, 2)->default(0);
            $table->decimal('total_earnings', 10, 2)->default(0);
            $table->decimal('available_earnings', 10, 2)->default(0);
            $table->decimal('paid_earnings', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_stats');
    }
};
