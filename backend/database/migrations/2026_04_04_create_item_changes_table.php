<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_item_id')->constrained('version_items')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users')->cascadeOnDelete();
            
            // What was changed
            $table->string('field_name'); // e.g., 'status', 'priority', 'criticality', 'title', 'description'
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            
            // Metadata
            $table->string('change_type'); // 'created', 'updated', 'status_changed'
            $table->text('notes')->nullable(); // Additional context
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('version_item_id');
            $table->index('changed_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_changes');
    }
};
