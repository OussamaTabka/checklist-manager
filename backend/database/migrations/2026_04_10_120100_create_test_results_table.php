<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->constrained('test_runs')->cascadeOnDelete();
            $table->foreignId('version_item_id')->constrained('version_items')->cascadeOnDelete();
            $table->enum('status', ['passed', 'failed', 'blocked', 'skipped']);
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('artifacts')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['test_run_id', 'version_item_id']);
            $table->index('status');
            $table->index('version_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_results');
    }
};
