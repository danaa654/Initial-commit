<?php

namespace App\Providers;

use App\Listeners\LogUserLogin;
use App\Listeners\LogUserLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
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
        Vite::prefetch(concurrency: 3);

        /*
        |--------------------------------------------------------------------------
        | Password Strength — App-Wide Default
        |--------------------------------------------------------------------------
        |
        | Password::defaults() is what every password rule in the app
        | already calls — My Account's Update Password (PasswordController),
        | the "forgot password" reset flow (NewPasswordController), account
        | creation (RegisteredUserController, currently unreachable — see
        | its own docblock), and Admin-created/edited accounts
        | (UserController). Customizing the default HERE, once, means
        | every one of those forms picks up the same rule automatically —
        | no risk of one place enforcing a stronger password than another,
        | the exact inconsistency that used to exist between UserController
        | (min:6) and everywhere else (min:8) before this was unified.
        |
        | min(8) — already Laravel's own default, kept explicit here since
        | this closure now overrides it.
        | mixedCase() — requires at least one UPPERCASE and one lowercase
        | letter (what the person actually asked for — "should have Capslock
        | letter").
        | numbers() — requires at least one digit.
        |
        | Deliberately NOT calling ->symbols() or ->uncompromised() (which
        | checks a password against known-breached-password lists over the
        | network) — either would meaningfully raise the bar past what was
        | asked for, and ->uncompromised() would make every password change
        | depend on an external API being reachable.
        */

        Password::defaults(function () {
            return Password::min(8)->mixedCase()->numbers();
        });

        /*
        |--------------------------------------------------------------------------
        | Active Sessions tracking
        |--------------------------------------------------------------------------
        |
        | This project doesn't have an EventServiceProvider (Laravel 11
        | minimal skeleton), so listeners are registered here instead.
        |
        |   - Login  -> creates the ActiveSession row immediately, so a
        |     user shows up on Active Users the instant they sign in.
        |   - Logout -> deletes the ActiveSession row immediately, so a
        |     user disappears from Active Users the instant they sign
        |     out, instead of waiting for the 10-minute idle timeout to
        |     catch it.
        |
        | See ActiveSessionService for the actual read/write logic —
        | these listeners just call into it.
        |
        */

        Event::listen(Login::class, LogUserLogin::class);
        Event::listen(Logout::class, LogUserLogout::class);
    }
}