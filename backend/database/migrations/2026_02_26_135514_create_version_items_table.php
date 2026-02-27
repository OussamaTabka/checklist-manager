<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('version_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_version_id')
                ->constrained('project_versions')
                ->cascadeOnDelete();

            // snapshot data
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['Low', 'Medium', 'High'])->default('Medium');
            $table->enum('criticality', ['Minor', 'Major', 'Critical'])->default('Minor');
            $table->integer('order')->default(0);

            // execution status
            $table->enum('status', ['Not Tested', 'Passed', 'Failed', 'Blocked'])->default('Not Tested');
            $table->foreignId('tested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tested_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_items');
    }
};