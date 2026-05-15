<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('notifications', 'role')) {
                $table->string('role', 50)->nullable()->after('user_id');
            }

            if (!Schema::hasColumn('notifications', 'title')) {
                $table->string('title')->nullable()->after('type');
            }

            if (!Schema::hasColumn('notifications', 'message')) {
                $table->text('message')->nullable()->after('title');
            }

            if (!Schema::hasColumn('notifications', 'priority')) {
                $table->string('priority', 20)->nullable()->after('message');
            }

            if (!Schema::hasColumn('notifications', 'link')) {
                $table->text('link')->nullable()->after('priority');
            }

            if (!Schema::hasColumn('notifications', 'target_type')) {
                $table->string('target_type', 100)->nullable()->after('link');
            }

            if (!Schema::hasColumn('notifications', 'target_id')) {
                $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            }

            if (!Schema::hasColumn('notifications', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('target_id')->constrained('projects')->nullOnDelete();
            }

            if (!Schema::hasColumn('notifications', 'version_id')) {
                $table->foreignId('version_id')->nullable()->after('project_id')->constrained('project_versions')->nullOnDelete();
            }

            if (!Schema::hasColumn('notifications', 'checklist_id')) {
                $table->foreignId('checklist_id')->nullable()->after('version_id')->constrained('checklists')->nullOnDelete();
            }

            if (!Schema::hasColumn('notifications', 'test_case_id')) {
                $table->foreignId('test_case_id')->nullable()->after('checklist_id')->constrained('version_items')->nullOnDelete();
            }

            if (!Schema::hasColumn('notifications', 'comment_id')) {
                $table->foreignId('comment_id')->nullable()->after('test_case_id')->constrained('comments')->nullOnDelete();
            }

            if (!Schema::hasColumn('notifications', 'section')) {
                $table->string('section', 100)->nullable()->after('comment_id');
            }

            if (!Schema::hasColumn('notifications', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('section');
            }

            if (!Schema::hasColumn('notifications', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('is_read');
            }
        });

        DB::table('notifications')
            ->whereNotNull('read_at')
            ->update([
                'is_read' => true,
            ]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_archived', 'created_at'], 'notifications_user_archived_created_idx');
            $table->index(['user_id', 'is_read'], 'notifications_user_read_idx');
            $table->index(['user_id', 'type'], 'notifications_user_type_idx');
            $table->index(['project_id', 'version_id'], 'notifications_project_version_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_archived_created_idx');
            $table->dropIndex('notifications_user_read_idx');
            $table->dropIndex('notifications_user_type_idx');
            $table->dropIndex('notifications_project_version_idx');
        });
    }
};
