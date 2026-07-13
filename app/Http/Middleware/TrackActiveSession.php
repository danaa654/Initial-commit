<?php

namespace App\Http\Middleware;

use App\Services\ActiveSessionService;
use Closure;
use Illuminate\Http\Request;

/**
 * Keeps ActiveSession rows fresh — this is the ONE place
 * last_activity_at / current_page / browser / operating_system / ip
 * get updated from, per request, for every authenticated user. No
 * controller should ever duplicate this logic; add
 * TrackActiveSession once to the 'auth' middleware group (or
 * app/Http/Kernel.php's global $middleware, gated on auth()->check())
 * and every page visit, click-triggered request, and form submission
 * updates activity automatically for free.
 *
 * Registration (Laravel 10/11 Kernel-based apps):
 *
 *   // app/Http/Kernel.php
 *   protected $middlewareGroups = [
 *       'web' => [
 *           ...
 *           \App\Http\Middleware\TrackActiveSession::class,
 *       ],
 *   ];
 *
 * Registration (Laravel 11+ bootstrap/app.php style):
 *
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->appendToGroup('web', \App\Http\Middleware\TrackActiveSession::class);
 *   })
 *
 * Deliberately placed in the 'web' group rather than only on
 * 'auth'-protected routes so it also runs on the handful of
 * auth-adjacent routes (e.g. logout) — it no-ops instantly via the
 * auth()->check() guard below for guests either way.
 */
class TrackActiveSession
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check()) {
            // Never let activity tracking break the real request it's
            // attached to — same reasoning as AuditLogService::log()'s
            // try/catch.
            try {
                ActiveSessionService::touchActivity($request, auth()->user());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $next($request);
    }
}