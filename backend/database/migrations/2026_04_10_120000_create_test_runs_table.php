<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->foreignId('project_version_id')->constrained('project_versions')->cascadeOnDelete();
            $table->string('schema_version', 10)->default('1.0');
            $table->string('base_url', 2048);
            $table->string('mode', 50)->default('agent');
            $table->enum('status', ['created', 'running', 'completed', 'failed'])->default('created');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('summary_total')->default(0);
            $table->unsignedInteger('summary_passed')->default(0);
            $table->unsignedInteger('summary_failed')->default(0);
            $table->unsignedInteger('summary_blocked')->default(0);
            $table->unsignedInteger('summary_skipped')->default(0);
            $table->json('request_payload')->nullable();
            $table->timestamps();

            $table->index(['project_version_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_runs');
    }
};
