<?php

namespace App\Providers;

use App\Listeners\LogUserLogin;
use App\Listeners\LogUserLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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