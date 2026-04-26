<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Add user story fields to checklists table
     * This allows checklists to contain user story data directly
     */
    public function up(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            // Add project assignment
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete()->after('created_by');

            // User Story fields (section 2)
            $table->text('as_a')->nullable()->after('description');
            $table->text('i_want_that')->nullable()->after('as_a');
            $table->text('so_that')->nullable()->after('i_want_that');

            // Acceptance Criteria (section 1 extended)
            $table->text('acceptance_criteria')->nullable()->after('so_that');

            // Business Rules (section 3)
            $table->json('business_rules')->nullable()->after('acceptance_criteria');

            // Priority for the checklist (general info section)
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium')->after('is_active');

            // Status
            $table->enum('status', ['backlog', 'in_progress', 'ready_for_test', 'completed'])->default('backlog')->after('priority');

            // Metadata
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete()->after('status');
            $table->timestamp('started_at')->nullable()->after('assigned_to');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['project_id']);
            $table->dropForeignKeyIfExists(['assigned_to']);
            
            $table->dropColumn([
                'project_id',
                'as_a',
                'i_want_that',
                'so_that',
                'acceptance_criteria',
                'business_rules',
                'priority',
                'status',
                'assigned_to',
                'started_at',
                'completed_at',
            ]);
        });
    }
};
