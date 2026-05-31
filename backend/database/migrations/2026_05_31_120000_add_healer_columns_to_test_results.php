<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('test_results', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt')->default(1)->after('executed_at');
            $table->boolean('healed')->default(false)->after('attempt');
            $table->text('heal_diagnosis')->nullable()->after('healed');
        });
    }

    public function down(): void
    {
        Schema::table('test_results', function (Blueprint $table) {
            $table->dropColumn(['attempt', 'healed', 'heal_diagnosis']);
        });
    }
};
