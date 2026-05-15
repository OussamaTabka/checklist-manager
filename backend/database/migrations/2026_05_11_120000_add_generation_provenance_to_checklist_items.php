<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            if (!Schema::hasColumn('checklist_items', 'source_type')) {
                $table->string('source_type', 30)->nullable()->after('execution_profile');
            }

            if (!Schema::hasColumn('checklist_items', 'source_checklist_id')) {
                $table->foreignId('source_checklist_id')
                    ->nullable()
                    ->after('source_type')
                    ->constrained('checklists')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('checklist_items', 'source_checklist_name')) {
                $table->string('source_checklist_name')->nullable()->after('source_checklist_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            if (Schema::hasColumn('checklist_items', 'source_checklist_id')) {
                $table->dropConstrainedForeignId('source_checklist_id');
            }

            if (Schema::hasColumn('checklist_items', 'source_checklist_name')) {
                $table->dropColumn('source_checklist_name');
            }

            if (Schema::hasColumn('checklist_items', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
