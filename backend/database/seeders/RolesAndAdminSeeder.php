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
        // Création des rôles
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $chefRole = Role::firstOrCreate(['name' => 'chef']);
        $testeurRole = Role::firstOrCreate(['name' => 'testeur']);

        // Création admin par défaut
        $admin = User::firstOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password123'),
            ]
        );

        // Attribution rôle admin
        $admin->assignRole($adminRole);

        // Création testeur par défaut
        $testeur = User::firstOrCreate(
            ['email' => 'testeur@test.com'],
            [
                'name' => 'Testeur',
                'password' => Hash::make('password123'),
            ]
        );

        // Attribution rôle testeur
        $testeur->assignRole($testeurRole);

        // Création chef par défaut
        $chef = User::firstOrCreate(
            ['email' => 'chef@test.com'],
            [
                'name' => 'Chef de Projet',
                'password' => Hash::make('password123'),
            ]
        );

        // Attribution rôle chef
        $chef->assignRole($chefRole);
    }
}