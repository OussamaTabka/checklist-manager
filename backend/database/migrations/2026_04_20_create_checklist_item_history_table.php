<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Create table to track history of checklist item status changes
     * This allows us to see when scenarios were updated, by whom, and what changed
     */
    public function up(): void
    {
        Schema::create('checklist_item_history', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users')->cascadeOnDelete();
            
            // What was changed
            $table->string('field_name'); // e.g., 'status', 'priority', 'criticality', 'title', 'description'
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            
            // Metadata
            $table->string('change_type'); // 'created', 'updated', 'status_changed'
            $table->text('notes')->nullable(); // Additional context (e.g., failure reason)
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('checklist_item_id');
            $table->index('changed_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_item_history');
    }
};
