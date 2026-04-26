<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Project;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        // Tout utilisateur authentifié peut lister
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        // Admin voit tous
        if ($user->hasRole('admin')) {
            return true;
        }

        // Chef voit seulement ses propres projets
        if ($user->hasRole('chef') && $project->created_by === $user->id) {
            return true;
        }

        // Testeur voit seulement les projets qui lui sont assignés
        if ($user->hasRole('testeur') && $project->testers()->where('users.id', $user->id)->exists()) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        // Seulement admin et chef peuvent créer
        return $user->hasAnyRole(['admin', 'chef']);
    }

    public function update(User $user, Project $project): bool
    {
        // Seulement admin ou le créateur (si chef)
        return $user->hasRole('admin') || ($user->hasRole('chef') && $project->created_by === $user->id);
    }

    public function delete(User $user, Project $project): bool
    {
        // Seulement admin ou le créateur (si chef)
        return $user->hasRole('admin') || ($user->hasRole('chef') && $project->created_by === $user->id);
    }
}
