<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use IF NOT EXISTS so this is safe to run even if an earlier
        // migration (e.g. add_user_id_to_geo_analyses_pgsql) already
        // created the user_id index.
        DB::statement('CREATE INDEX IF NOT EXISTS geo_analyses_user_id_index ON geo_analyses (user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS geo_analyses_status_index ON geo_analyses (status)');
        DB::statement('CREATE INDEX IF NOT EXISTS geo_analyses_user_id_status_index ON geo_analyses (user_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS geo_analyses_created_at_index ON geo_analyses (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS geo_analyses_started_at_index ON geo_analyses (started_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS geo_analyses_user_id_index');
        DB::statement('DROP INDEX IF EXISTS geo_analyses_status_index');
        DB::statement('DROP INDEX IF EXISTS geo_analyses_user_id_status_index');
        DB::statement('DROP INDEX IF EXISTS geo_analyses_created_at_index');
        DB::statement('DROP INDEX IF EXISTS geo_analyses_started_at_index');
    }
};