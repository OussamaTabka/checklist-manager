<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('chef') && $project->created_by === $user->id) {
            return true;
        }

        if ($user->hasRole('testeur') && $project->testers()->where('users.id', $user->id)->exists()) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('chef');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasRole('chef') && $project->created_by === $user->id;
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasRole('chef') && $project->created_by === $user->id;
    }
}
