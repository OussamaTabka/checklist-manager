<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolesAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Creation des roles selon la nouvelle structure
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'chef']);
        Role::firstOrCreate(['name' => 'testeur']);

        // Creation utilisateur ADMINISTRATEUR SYSTEME par defaut
        $admin = User::firstOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin Systeme',
                'password' => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at' => now(),
            ]
        );

        // Attribution role admin (gestion des utilisateurs, roles, permissions, audit)
        $admin->syncRoles(['admin']);

        // Creation utilisateur CHEF DE PROJET par defaut
        $chef = User::firstOrCreate(
            ['email' => 'chef@test.com'],
            [
                'name' => 'Chef de Projet',
                'password' => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at' => now(),
            ]
        );

        // Attribution role chef (gestion complete des projets et checklists)
        $chef->syncRoles(['chef']);

        // Creation utilisateur TESTEUR par defaut
        $testeur = User::firstOrCreate(
            ['email' => 'testeur@test.com'],
            [
                'name' => 'Testeur',
                'password' => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at' => now(),
            ]
        );

        // Attribution role testeur (execution des tests)
        $testeur->syncRoles(['testeur']);

        // Creation de 5 testeurs supplementaires pour faciliter les essais
        foreach (range(1, 5) as $index) {
            $tester = User::firstOrCreate(
                ['email' => "tester{$index}@test.com"],
                [
                    'name' => "Tester {$index}",
                    'password' => Hash::make('password123'),
                    'account_status' => 'active',
                    'activated_at' => now(),
                ]
            );

            $tester->syncRoles(['testeur']);
        }
    }
}
