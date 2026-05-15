<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        $request->session()->regenerate();

        $user = $request->user() ?? Auth::user();

        if (($user->account_status ?? 'active') !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => "Votre compte n'est pas encore actif. Veuillez initialiser votre mot de passe depuis le lien recu par email.",
            ], 403);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->user()?->tokens()->delete();

        return response()->json(['message' => 'Deconnexion reussie.']);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
            'roles' => $request->user()->getRoleNames(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifie.'], 401);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'profile_photo' => ['nullable', 'image', 'max:3072'],
            'remove_profile_photo' => ['nullable', 'boolean'],
        ]);

        $user->name = $data['name'];

        if (($data['remove_profile_photo'] ?? false) && $user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $user->profile_photo_path = $request->file('profile_photo')->store('profile-photos', 'public');
        }

        $user->save();

        return response()->json([
            'user' => $user->fresh(),
            'roles' => $user->getRoleNames(),
            'message' => 'Profil mis a jour avec succes.',
        ]);
    }

    public function getToken(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifie.'], 401);
        }

        $existingToken = $user->tokens()->where('name', 'api-token')->first();

        if ($existingToken) {
            return response()->json([
                'token' => $existingToken->plainTextToken ?? '',
                'message' => 'Jeton existant reutilise.',
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'message' => 'Nouveau jeton cree.',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink([
            'email' => $validated['email'],
        ]);

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Une demande recente existe deja. Veuillez patienter avant de recommencer.',
            ], 429);
        }

        return response()->json([
            'message' => "Si cette adresse email existe, un lien d'initialisation du mot de passe a ete envoye.",
        ]);
    }

    public function resetPassword(Request $request)
    {
        return $this->completePasswordReset($request);
    }

    public function setPassword(Request $request)
    {
        return $this->completePasswordReset($request);
    }

    private function completePasswordReset(Request $request)
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $credentials,
            function (User $user, string $password) {
                $updates = [
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ];

                if (($user->account_status ?? 'active') === 'pending') {
                    $updates['account_status'] = 'active';
                    $updates['activated_at'] = now();

                    if (!$user->email_verified_at) {
                        $updates['email_verified_at'] = now();
                    }
                }

                $user->forceFill($updates)->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Mot de passe initialise avec succes.',
            ]);
        }

        return response()->json([
            'message' => 'Lien invalide ou expire.',
        ], 422);
    }
}
