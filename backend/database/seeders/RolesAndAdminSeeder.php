<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class RolesAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Création des rôles selon la nouvelle structure
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $chefRole = Role::firstOrCreate(['name' => 'chef']);
        $testeurRole = Role::firstOrCreate(['name' => 'testeur']);

        // Création utilisateur ADMINISTRATEUR SYSTÈME par défaut
        $admin = User::firstOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin Système',
                'password' => Hash::make('password123'),
            ]
        );

        // Attribution rôle admin (Gestion des utilisateurs, rôles, permissions, audit)
        $admin->syncRoles(['admin']);

        // Création utilisateur CHEF DE PROJET par défaut
        $chef = User::firstOrCreate(
            ['email' => 'chef@test.com'],
            [
                'name' => 'Chef de Projet',
                'password' => Hash::make('password123'),
            ]
        );

        // Attribution rôle chef (Gestion complète des projets et checklists)
        $chef->syncRoles(['chef']);

        // Création utilisateur TESTEUR par défaut
        $testeur = User::firstOrCreate(
            ['email' => 'testeur@test.com'],
            [
                'name' => 'Testeur',
                'password' => Hash::make('password123'),
            ]
        );

        // Attribution rôle testeur (Exécution des tests)
        $testeur->syncRoles(['testeur']);
    }
}
