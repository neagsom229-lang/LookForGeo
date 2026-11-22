<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive, nullable millisecond timings. SQLite and Postgres safe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            $table->integer('queue_ms')->nullable()->after('processing_ms');
            $table->integer('gemini_ms')->nullable()->after('queue_ms');
            $table->integer('total_ms')->nullable()->after('gemini_ms');
        });
    }

    public function down(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            $table->dropColumn(['queue_ms', 'gemini_ms', 'total_ms']);
        });
    }
};
