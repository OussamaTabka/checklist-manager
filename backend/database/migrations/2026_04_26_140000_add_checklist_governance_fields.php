<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            $table->enum('template_scope', ['global', 'project'])->default('global')->after('category');
            $table->enum('lifecycle_status', ['draft', 'approved', 'archived'])->default('approved')->after('template_scope');
            $table->enum('generated_from', ['manual', 'ai', 'reuse'])->default('manual')->after('lifecycle_status');
            $table->foreignId('source_user_story_id')->nullable()->after('generated_from')->constrained('user_stories')->nullOnDelete();
        });

        Schema::table('user_story_checklists', function (Blueprint $table) {
            $table->unsignedTinyInteger('relevance_score')->nullable()->after('is_generated_from_arxis');
            $table->string('link_type')->default('attached')->after('relevance_score');
        });
    }

    public function down(): void
    {
        Schema::table('user_story_checklists', function (Blueprint $table) {
            $table->dropColumn(['relevance_score', 'link_type']);
        });

        Schema::table('checklists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_user_story_id');
            $table->dropColumn(['template_scope', 'lifecycle_status', 'generated_from']);
        });
    }
};
