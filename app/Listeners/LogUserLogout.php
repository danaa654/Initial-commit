<?php

namespace App\Listeners;

use App\Services\ActiveSessionService;
use Illuminate\Auth\Events\Logout;

/**
 * Removes the ActiveSession row the instant a user logs out — they
 * disappear from Active Users immediately, not after the 10-minute
 * idle timeout. See ActiveSessionService::endSession().
 *
 * Register in app/Providers/EventServiceProvider.php:
 *
 *   protected $listen = [
 *       \Illuminate\Auth\Events\Logout::class => [
 *           \App\Listeners\LogUserLogout::class,
 *       ],
 *   ];
 */
class LogUserLogout
{
    public function handle(Logout $event): void
    {
        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        try {
            ActiveSessionService::endSession($request->session()->getId());
        } catch (\Throwable $e) {
            report($e);
        }
    }
}