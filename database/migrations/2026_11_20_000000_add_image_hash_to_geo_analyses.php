<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user repeat-upload cache: a second upload of identical bytes returns
 * the existing completed analysis with zero Gemini cost. The hash is scoped
 * by user_id (no global/privacy-crossing cache).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            $table->string('image_hash', 64)->nullable()->after('image_url');
            $table->index(['user_id', 'image_hash'], 'geo_analyses_user_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            $table->dropIndex('geo_analyses_user_hash_index');
            $table->dropColumn('image_hash');
        });
    }
};
