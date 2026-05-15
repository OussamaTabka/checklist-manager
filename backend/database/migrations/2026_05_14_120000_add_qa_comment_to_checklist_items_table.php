<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            if (!Schema::hasColumn('checklist_items', 'qa_comment')) {
                $table->text('qa_comment')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            if (Schema::hasColumn('checklist_items', 'qa_comment')) {
                $table->dropColumn('qa_comment');
            }
        });
    }
};
