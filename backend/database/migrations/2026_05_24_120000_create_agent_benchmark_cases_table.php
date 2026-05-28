<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_benchmark_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->string('source_app');
            $table->string('project_url', 2048);
            $table->string('expected_scenario_type');
            $table->json('provided_inputs')->nullable();
            $table->json('expected_result');
            $table->string('test_kind');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique('checklist_item_id');
            $table->index(['enabled', 'source_app']);
            $table->index('expected_scenario_type');
            $table->index('test_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_benchmark_cases');
    }
};
