<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->string('landmark_name');
            $table->string('local_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->integer('confidence')->default(0);
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->string('image_path')->nullable();
            $table->string('share_token')->unique()->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('landmark_name');
            $table->index('country');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('analyses');
    }
};