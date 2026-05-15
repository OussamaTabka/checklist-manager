<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_items', function (Blueprint $table) {
            if (!Schema::hasColumn('version_items', 'execution_profile')) {
                $table->json('execution_profile')->nullable()->after('tested_at');
            }
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            if (!Schema::hasColumn('checklist_items', 'execution_profile')) {
                $table->json('execution_profile')->nullable()->after('tested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('version_items', function (Blueprint $table) {
            if (Schema::hasColumn('version_items', 'execution_profile')) {
                $table->dropColumn('execution_profile');
            }
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            if (Schema::hasColumn('checklist_items', 'execution_profile')) {
                $table->dropColumn('execution_profile');
            }
        });
    }
};
