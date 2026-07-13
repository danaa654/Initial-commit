<?php

namespace App\Listeners;

use App\Services\ActiveSessionService;
use Illuminate\Auth\Events\Login;

/**
 * Creates the ActiveSession row the instant a user logs in, so they
 * show up on Active Users immediately rather than waiting for the
 * next page's TrackActiveSession middleware pass.
 *
 * Register in app/Providers/EventServiceProvider.php:
 *
 *   protected $listen = [
 *       \Illuminate\Auth\Events\Login::class => [
 *           \App\Listeners\LogUserLogin::class,
 *       ],
 *   ];
 *
 * (Laravel 11+ auto-discovers listeners under app/Listeners by
 * convention — explicit registration above still works either way
 * and is the safest bet if auto-discovery is off.)
 */
class LogUserLogin
{
    public function handle(Login $event): void
    {
        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        try {
            ActiveSessionService::startSession($request, $event->user);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}