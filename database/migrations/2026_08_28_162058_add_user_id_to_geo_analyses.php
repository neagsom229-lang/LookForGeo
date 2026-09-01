<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            if (!Schema::hasColumn('geo_analyses', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            }
        });

        // Check if index exists before creating it
        $indexExists = false;
        try {
            $indexes = DB::select("PRAGMA index_list('geo_analyses')"); 
            // Note: For PostgreSQL, use a specific query or just try/catch the creation
        } catch (\Exception $e) {}
        
        // For PostgreSQL/Render, just try to create it, but catch the duplicate error
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
        Schema::table('geo_analyses', function (Blueprint $table) {
            if (Schema::hasColumn('geo_analyses', 'user_id')) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};