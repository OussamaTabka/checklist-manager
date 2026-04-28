<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->foreignId('checklist_id')
                ->nullable()
                ->after('project_version_id')
                ->constrained('checklists')
                ->nullOnDelete();

            $table->foreignId('project_version_id')
                ->nullable()
                ->change();
        });

        Schema::table('test_results', function (Blueprint $table) {
            $table->foreignId('checklist_item_id')
                ->nullable()
                ->after('version_item_id')
                ->constrained('checklist_items')
                ->cascadeOnDelete();

            $table->foreignId('version_item_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('test_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checklist_item_id');
        });

        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checklist_id');
        });
    }
};
