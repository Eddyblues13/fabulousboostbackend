<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserServiceRatesTable extends Migration
{
    public function up()
    {
        Schema::create('user_service_rates', function (Blueprint $table) {
            $table->id(); // id is unsignedBigInteger by default
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->decimal('price', 11, 2)->default(0.00);
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('service_id')
                ->references('id')->on('services')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_service_rates');
    }
}
