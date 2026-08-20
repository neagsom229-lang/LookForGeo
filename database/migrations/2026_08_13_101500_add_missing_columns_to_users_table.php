<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("users", function (Blueprint $table) {
            if (!Schema::hasColumn("users", "email_verified_token")) {
                $table->string("email_verified_token")->nullable()->after("email_verified_at");
            }
            
            if (!Schema::hasColumn("users", "is_admin")) {
                $table->boolean("is_admin")->default(false)->after("remember_token");
            }
        });
    }

    public function down(): void
    {
        Schema::table("users", function (Blueprint $table) {
            if (Schema::hasColumn("users", "email_verified_token")) {
                $table->dropColumn("email_verified_token");
            }
            if (Schema::hasColumn("users", "is_admin")) {
                $table->dropColumn("is_admin");
            }
        });
    }
};
