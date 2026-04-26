<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Add history tracking fields to checklist_items
     */
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            // Track who tested and when
            $table->foreignId('tested_by')->nullable()->constrained('users')->nullOnDelete()->after('run_count');
            $table->timestamp('tested_at')->nullable()->after('tested_by');
            
            // Add criticality if not already present
            if (!Schema::hasColumn('checklist_items', 'criticality')) {
                $table->enum('criticality', ['Low', 'Medium', 'High', 'Critical'])->default('Medium')->after('priority');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            if (Schema::hasColumn('checklist_items', 'tested_by')) {
                $table->dropForeignKeyIfExists(['tested_by']);
            }
            
            $table->dropColumnIfExists(['tested_by', 'tested_at']);
        });
    }
};
