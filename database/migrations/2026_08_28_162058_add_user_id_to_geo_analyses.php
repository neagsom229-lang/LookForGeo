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
            // Only add the column if it doesn't already exist
            if (!Schema::hasColumn('geo_analyses', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            }
        });

        // Check if the index already exists in SQLite before creating it
        $indexExists = false;
        try {
            $indexes = DB::select("PRAGMA index_list('geo_analyses')");
            foreach ($indexes as $index) {
                if ($index->name === 'geo_analyses_user_id_index') {
                    $indexExists = true;
                    break;
                }
            }
        } catch (\Exception $e) {
            // Ignore if table doesn't exist
        }

        if (!$indexExists) {
            Schema::table('geo_analyses', function (Blueprint $table) {
                $table->index('user_id');
            });
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