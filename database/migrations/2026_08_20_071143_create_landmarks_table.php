<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('landmarks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('country');
            $table->string('region')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->text('description')->nullable();
            $table->text('historical_context')->nullable();
            $table->json('tags')->nullable();
            $table->string('type')->default('landmark');
            $table->string('image_url')->nullable();
            $table->timestamps();
            
            $table->index('name');
            $table->index('country');
            $table->index('latitude');
            $table->index('longitude');
        });
    }

    public function down()
    {
        Schema::dropIfExists('landmarks');
    }
};