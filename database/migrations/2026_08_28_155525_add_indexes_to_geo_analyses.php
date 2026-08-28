<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            // Add indexes for frequently queried columns
            $table->index('user_id');                     // For dashboard filtering
            $table->index('status');                      // For status-based queries
            $table->index(['user_id', 'status']);         // Composite for dashboard + status
            $table->index('created_at');                  // For sorting recent analyses
            $table->index('started_at');                  // For elapsed time queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('geo_analyses', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['started_at']);
        });
    }
};