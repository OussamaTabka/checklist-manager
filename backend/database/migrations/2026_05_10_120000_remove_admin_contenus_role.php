<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'admin_contenus')->value('id');
        $legacyUser = User::query()->where('email', 'admin_contenus@test.com')->first();

        if ($roleId) {
            if ($legacyUser) {
                DB::table('model_has_roles')
                    ->where('role_id', $roleId)
                    ->where('model_type', User::class)
                    ->where('model_id', $legacyUser->id)
                    ->delete();
            } else {
                DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            }

            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        if ($legacyUser) {
            $legacyUser->forceFill([
                'account_status' => 'disabled',
            ])->save();
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'admin_contenus')->value('id');

        if (!$roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'admin_contenus',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = User::query()->firstOrCreate(
            ['email' => 'admin_contenus@test.com'],
            [
                'name' => 'Admin Contenus',
                'password' => bcrypt('password123'),
                'account_status' => 'active',
            ]
        );

        $user->forceFill([
            'account_status' => 'active',
        ])->save();

        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
        ], []);
    }
};
