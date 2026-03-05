<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with(['roles:id,name'])
            ->select(['id', 'name', 'email', 'created_at'])
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json($users);
    }

    public function store(Request $request)
{
    $data = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users,email'],
        'password' => ['nullable', 'string', 'min:6'],
        'role' => ['required', Rule::in(['admin', 'chef', 'testeur'])],
    ]);

    // Vérifie proprement si password existe
    $hasPassword = isset($data['password']) && !empty($data['password']);

    $plainPassword = $hasPassword
        ? $data['password']
        : $this->generatePassword();

    $user = User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($plainPassword),
    ]);

    $user->syncRoles([$data['role']]);

    return response()->json([
        'user' => $user->load('roles:name'),
        'generated_password' => $hasPassword ? null : $plainPassword,
    ], 201);
}

    public function show(User $user)
    {
        return response()->json($user->load('roles:name'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required'],
            'email' => ['sometimes', 'required', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'min:6'],
            'role' => ['sometimes', Rule::in(['admin', 'chef', 'testeur'])],
        ]);

        if (isset($data['name'])) $user->name = $data['name'];
        if (isset($data['email'])) $user->email = $data['email'];
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($user->load('roles:name'));
    }

    public function destroy(User $user)
    {
        if (Auth::id() === $user->id) {
            return response()->json(['message' => 'You cannot delete yourself'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    private function generatePassword()
    {
        return substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 10);
    }
}