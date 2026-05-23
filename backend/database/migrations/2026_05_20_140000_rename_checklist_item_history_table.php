<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('checklist_item_history') && !Schema::hasTable('checklist_item_histories')) {
            Schema::rename('checklist_item_history', 'checklist_item_histories');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('checklist_item_histories') && !Schema::hasTable('checklist_item_history')) {
            Schema::rename('checklist_item_histories', 'checklist_item_history');
        }
    }
};
