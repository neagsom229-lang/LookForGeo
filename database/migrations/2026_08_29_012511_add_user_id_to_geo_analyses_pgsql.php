<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            // Add user_id only if it doesn't already exist
            if (!Schema::hasColumn('geo_analyses', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable();
                $table->foreign('user_id')
                      ->references('id')
                      ->on('users')
                      ->onDelete('cascade');
                $table->index('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            if (Schema::hasColumn('geo_analyses', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};