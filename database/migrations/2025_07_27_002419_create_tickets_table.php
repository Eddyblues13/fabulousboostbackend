<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTicketsTable extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('name', 91)->nullable();
            $table->string('email', 91)->nullable();
            $table->string('ticket', 191)->nullable();
            $table->string('category_id', 191)->nullable();
            $table->string('order_ids', 191)->nullable();
            $table->string('request_type', 191)->nullable();
            $table->longText('message')->nullable();
            $table->string('subject', 191)->nullable();
            $table->tinyInteger('status')->default(0)->comment('0: Open, 1: Answered, 2: Replied, 3: Closed');
            $table->dateTime('last_reply')->nullable();
            $table->timestamps();

            // Optional: Add a foreign key constraint if `users` table exists
            // $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
}
