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
        if (!Schema::hasTable('checklist_items')) {
            return;
        }

        Schema::table('checklist_items', function (Blueprint $table) {
            // Add test execution tracking columns if they don't exist
            if (!Schema::hasColumn('checklist_items', 'status')) {
                $table->enum('status', ['pending', 'passed', 'failed'])
                    ->default('pending')
                    ->after('criticality')
                    ->comment('Test execution status');
            }

            if (!Schema::hasColumn('checklist_items', 'last_run_at')) {
                $table->timestamp('last_run_at')
                    ->nullable()
                    ->after('status')
                    ->comment('Last test execution timestamp');
            }

            if (!Schema::hasColumn('checklist_items', 'run_count')) {
                $table->unsignedInteger('run_count')
                    ->default(0)
                    ->after('last_run_at')
                    ->comment('Number of times this test has been run');
            }

            // Add indexes for faster queries
            if (!Schema::hasIndex('checklist_items', 'idx_checklist_items_status')) {
                $table->index('status', 'idx_checklist_items_status');
            }

            if (!Schema::hasIndex('checklist_items', 'idx_checklist_items_last_run')) {
                $table->index('last_run_at', 'idx_checklist_items_last_run');
            }

            if (!Schema::hasIndex('checklist_items', 'idx_checklist_items_checklist')) {
                $table->index('checklist_id', 'idx_checklist_items_checklist');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('checklist_items')) {
            return;
        }

        Schema::table('checklist_items', function (Blueprint $table) {
            // Drop columns in reverse order
            if (Schema::hasColumn('checklist_items', 'run_count')) {
                $table->dropColumn('run_count');
            }

            if (Schema::hasColumn('checklist_items', 'last_run_at')) {
                $table->dropColumn('last_run_at');
            }

            if (Schema::hasColumn('checklist_items', 'status')) {
                $table->dropColumn('status');
            }

            // Drop indexes
            $table->dropIndexIfExists('idx_checklist_items_status');
            $table->dropIndexIfExists('idx_checklist_items_last_run');
            $table->dropIndexIfExists('idx_checklist_items_checklist');
        });
    }
};
