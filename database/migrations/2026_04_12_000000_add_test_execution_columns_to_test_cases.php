<?php

/**
 * Database Migration for Test Case Execution Tracking
 * File: database/migrations/YYYY_MM_DD_HHMMSS_add_test_execution_columns_to_test_cases.php
 * 
 * Run with: php artisan migrate
 */

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
        Schema::table('test_cases', function (Blueprint $table) {
            // Add status column (pending, passed, failed)
            if (!Schema::hasColumn('test_cases', 'status')) {
                $table->enum('status', ['pending', 'passed', 'failed'])
                    ->default('pending')
                    ->after('description')
                    ->comment('Test execution status');
            }

            // Add last run timestamp
            if (!Schema::hasColumn('test_cases', 'last_run_at')) {
                $table->timestamp('last_run_at')
                    ->nullable()
                    ->after('status')
                    ->comment('Timestamp of last test execution');
            }

            // Add run count (optional - for tracking)
            if (!Schema::hasColumn('test_cases', 'run_count')) {
                $table->integer('run_count')
                    ->default(0)
                    ->after('last_run_at')
                    ->comment('Number of times test has been run');
            }

            // Optional: Add duration column
            if (!Schema::hasColumn('test_cases', 'duration_seconds')) {
                $table->decimal('duration_seconds', 8, 2)
                    ->nullable()
                    ->after('run_count')
                    ->comment('Duration of last test run in seconds');
            }

            // Optional: Add error message column
            if (!Schema::hasColumn('test_cases', 'error_message')) {
                $table->text('error_message')
                    ->nullable()
                    ->after('duration_seconds')
                    ->comment('Error message if test failed');
            }

            // Add index for faster queries
            $table->index('status');
            $table->index('last_run_at');
            $table->index('checklist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['last_run_at']);
            $table->dropIndex(['checklist_id']);

            $table->dropColumn([
                'status',
                'last_run_at',
                'run_count',
                'duration_seconds',
                'error_message'
            ]);
        });
    }
};
