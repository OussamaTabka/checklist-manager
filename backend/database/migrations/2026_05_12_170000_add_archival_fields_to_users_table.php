<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('archived_previous_status', 20)->nullable()->after('account_status');
            $table->timestamp('archived_at')->nullable()->after('activated_at');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_previous_status', 'archived_at']);
        });
    }
};
