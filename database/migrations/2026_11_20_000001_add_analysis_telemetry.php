<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive, nullable telemetry columns. SQLite and Postgres safe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            $table->string('model_used', 128)->nullable()->after('image_hash');
            $table->integer('processing_ms')->nullable()->after('model_used');
            $table->string('failure_category', 32)->nullable()->after('processing_ms');
        });
    }

    public function down(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            $table->dropColumn(['model_used', 'processing_ms', 'failure_category']);
        });
    }
};
