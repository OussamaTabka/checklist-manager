<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('checklist_id')
                  ->constrained('checklists')
                  ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('priority', ['Low', 'Medium', 'High'])
                  ->default('Medium');

            $table->enum('criticality', ['Minor', 'Major', 'Critical'])
                  ->default('Minor');

            $table->enum('status', ['pending', 'passed', 'failed'])
                  ->default('pending');

            $table->timestamp('last_run_at')->nullable();

            $table->unsignedInteger('run_count')->default(0);

            $table->integer('order')->default(0);

            $table->index('status', 'idx_checklist_items_status');
            $table->index('last_run_at', 'idx_checklist_items_last_run');
            $table->index('checklist_id', 'idx_checklist_items_checklist');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
