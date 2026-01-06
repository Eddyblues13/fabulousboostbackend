<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFundsTable extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('gateway_id')->nullable();
            $table->string('gateway_currency', 191)->nullable();
            $table->decimal('amount', 18, 8)->default(0.00000000);
            $table->decimal('charge', 18, 8)->default(0.00000000);
            $table->decimal('rate', 18, 8)->default(0.00000000);
            $table->decimal('final_amount', 18, 8)->default(0.00000000);
            $table->decimal('btc_amount', 18, 8)->nullable();
            $table->string('btc_wallet', 191)->nullable();
            $table->string('transaction', 25)->nullable();
            $table->integer('try')->nullable();
            $table->tinyInteger('status')->default(0)->comment('1=> Complete, 2=> Pending, 3 => Cancel');
            $table->timestamps();
            $table->text('detail')->nullable();
            $table->text('feedback')->nullable();
            $table->string('payment_id', 61)->nullable();

            // Foreign key constraints (optional, if users & gateways tables exist)
            // $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            // $table->foreign('gateway_id')->references('id')->on('gateways')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
}
