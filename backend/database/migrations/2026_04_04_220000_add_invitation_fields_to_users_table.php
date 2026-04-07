<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->enum('account_status', ['pending', 'active', 'disabled'])->default('active')->after('password');
            $table->foreignId('invited_by')->nullable()->after('remember_token')->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable()->after('invited_by');
            $table->timestamp('activated_at')->nullable()->after('invited_at');

            $table->index('account_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropForeign(['invited_by']);
            $table->dropColumn(['account_status', 'invited_by', 'invited_at', 'activated_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
