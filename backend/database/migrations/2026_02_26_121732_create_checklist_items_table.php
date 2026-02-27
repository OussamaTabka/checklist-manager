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

            $table->integer('order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};