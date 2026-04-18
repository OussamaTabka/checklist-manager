<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_story_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_generated_from_arxis')->default(false);
            $table->timestamps();
            $table->unique(['user_story_id', 'checklist_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_story_checklists');
    }
};
