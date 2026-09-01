<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only add the column if it doesn't exist
        if (!Schema::hasColumn('geo_analyses', 'user_id')) {
            Schema::table('geo_analyses', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
        }

        // Try to add the index, but ignore if it already exists
        try {
            Schema::table('geo_analyses', function (Blueprint $table) {
                $table->index('user_id');
            });
        } catch (\Exception $e) {
            // Index already exists, ignore!
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('geo_analyses', 'user_id')) {
            Schema::table('geo_analyses', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};