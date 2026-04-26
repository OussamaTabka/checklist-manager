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
        Schema::table('user_stories', function (Blueprint $table) {
            // Story structure (En tant que / Je veux que / Afin de)
            $table->text('as_a')->nullable()->after('description');
            $table->text('i_want_that')->nullable()->after('as_a');
            $table->text('so_that')->nullable()->after('i_want_that');
            
            // Business rules and scenarios
            $table->json('business_rules')->nullable()->after('so_that');
            $table->json('scenarios')->nullable()->after('business_rules');
            
            // Effort and value
            $table->integer('effort_points')->nullable()->after('scenarios');
            $table->integer('business_value')->nullable()->after('effort_points');
            
            // Dates
            $table->date('start_date')->nullable()->after('business_value');
            $table->date('target_completion_date')->nullable()->after('start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_stories', function (Blueprint $table) {
            $table->dropColumn([
                'as_a',
                'i_want_that',
                'so_that',
                'business_rules',
                'scenarios',
                'effort_points',
                'business_value',
                'start_date',
                'target_completion_date',
            ]);
        });
    }
};
