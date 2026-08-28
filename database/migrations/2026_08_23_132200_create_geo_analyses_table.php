<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('geo_analyses');
        
        Schema::create('geo_analyses', function (Blueprint $table) {
            $table->id();  // ✅ Auto-increment
            $table->string('status')->default('processing');
            $table->integer('stage')->default(0);
            $table->string('stage_label')->default('Input');
            $table->integer('progress')->default(0);
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_analyses');
    }
};