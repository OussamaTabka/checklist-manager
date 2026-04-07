<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Project::class => ProjectPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
            $email = urlencode($user->getEmailForPasswordReset());

            return "{$frontendUrl}/reset-password?token={$token}&email={$email}";
        });

        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($email . '|' . $request->ip());
        });

        RateLimiter::for('password-reset-link', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(3)->by($email . '|' . $request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(6)->by($email . '|' . $request->ip());
        });

        RateLimiter::for('invitation-send', function (Request $request) {
            $userParam = $request->route('user');
            $userId = is_object($userParam) && method_exists($userParam, 'getKey')
                ? (string) $userParam->getKey()
                : (string) $userParam;

            return Limit::perMinute(10)->by($userId . '|' . $request->ip());
        });

        RateLimiter::for('invitation-validate', function (Request $request) {
            $selector = (string) $request->query('selector', 'none');

            return Limit::perMinute(30)->by($selector . '|' . $request->ip());
        });

        RateLimiter::for('invitation-accept', function (Request $request) {
            $selector = (string) $request->input('selector', 'none');

            return Limit::perMinute(8)->by($selector . '|' . $request->ip());
        });
    }
}
