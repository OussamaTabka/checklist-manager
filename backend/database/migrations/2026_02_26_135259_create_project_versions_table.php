<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            // checklist source (la checklist/template choisie au moment de la version)
            $table->foreignId('checklist_id')
                ->constrained('checklists')
                ->restrictOnDelete();

            $table->unsignedInteger('version_number');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_versions');
    }
};