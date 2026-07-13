<?php

namespace App\Services;

use App\Models\ActiveSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The single write path for every ActiveSession row — mirrors
 * AuditLogService's role for AuditLog. No controller, middleware, or
 * listener should ever touch ActiveSession directly; always go
 * through one of the three methods below so the parsing/labeling
 * logic (browser, OS, current page) only lives in one place.
 *
 *   - startSession()  — called once, from the Login event listener.
 *   - touchActivity()  — called on every authenticated request, from
 *                         the TrackActiveSession middleware.
 *   - endSession()     — called once, from the Logout event listener.
 *   - pruneStale()      — called on a schedule (see console Kernel),
 *                         physically removing sessions nobody ever
 *                         logged out of.
 */
class ActiveSessionService
{
    /**
     * Human-readable "Current Page" labels, keyed by route name
     * prefix — matches the "Current Page Examples" list in the spec.
     * Checked longest-prefix-first isn't necessary here since route
     * names in Classly don't collide across modules; a simple
     * str_starts_with scan is enough.
     */
    private const PAGE_LABELS = [
        'dashboard' => 'Dashboard',
        'teaching-assignments' => 'Faculty Loading',
        'faculty-load-overloads' => 'Faculty Loading',
        'subject-offerings' => 'Subject Offerings',
        'master-grid' => 'Master Grid',
        'academic-terms' => 'Academic Terms',
        'curriculums' => 'Curriculum',
        'curriculum-items' => 'Curriculum',
        'rooms' => 'Rooms',
        'faculty' => 'Faculty',
        'subjects' => 'Subjects',
        'sections' => 'Sections',
        'departments' => 'Colleges',
        'programs' => 'Programs',
        'specializations' => 'Specializations',
        'users' => 'Users',
        'settings' => 'Settings',
        'working-term' => 'Settings',
        'audit-logs' => 'Audit Logs',
        'activity-history' => 'Activity History',
        'block-schedule' => 'Block Schedule',
        'active-users' => 'Active Users',
        'profile' => 'My Account',
    ];

    /**
     * How close together two ActiveSession rows for the same user/
     * device have to be created to be treated as "the same login",
     * not two genuinely separate logins. Covers Laravel's
     * session()->regenerate() call during the login pipeline, which
     * changes the session_id mid-flow and would otherwise orphan the
     * pre-regeneration row (see the "absorb" step below).
     */
    private const SAME_LOGIN_WINDOW_SECONDS = 15;

    /**
     * First touch of a session — writes login_at, so it's never
     * clobbered by later touchActivity() calls within the same
     * session.
     *
     * Laravel regenerates the session ID as part of the login
     * pipeline (session fixation protection). If startSession() and
     * the very next TrackActiveSession-driven touchActivity() were
     * keyed purely on session_id, that ID change produces TWO rows
     * for one real login: an orphaned pre-regeneration row that never
     * gets touched again, plus a fresh post-regeneration row — the
     * "duplicate card" bug. To prevent that, before creating a new
     * row we look for an existing row for this same user, same IP +
     * browser + OS, created within SAME_LOGIN_WINDOW_SECONDS, and —
     * if found — reuse (absorb) that row under the new session_id
     * instead of inserting a second one. Genuinely separate logins (a
     * different device, or the same device more than a few seconds
     * later) still get their own row, so multi-device sessions keep
     * working correctly.
     */
    public static function startSession(Request $request, User $user): ActiveSession
    {
        $now = now();
        $sessionId = $request->session()->getId();
        $ip = $request->ip();
        $browser = self::parseBrowser($request->userAgent());
        $os = self::parseOperatingSystem($request->userAgent());

        $staleRow = ActiveSession::where('user_id', $user->id)
            ->where('session_id', '!=', $sessionId)
            ->where('ip_address', $ip)
            ->where('browser', $browser)
            ->where('operating_system', $os)
            ->where('login_at', '>=', $now->copy()->subSeconds(self::SAME_LOGIN_WINDOW_SECONDS))
            ->orderByDesc('login_at')
            ->first();

        $attributes = [
            'user_id' => $user->id,
            'login_at' => $now,
            'last_activity_at' => $now,
            'current_page' => self::resolvePageLabel($request),
            'browser' => $browser,
            'operating_system' => $os,
            'ip_address' => $ip,
        ];

        if ($staleRow) {
            $staleRow->update(['session_id' => $sessionId, ...$attributes]);

            return $staleRow;
        }

        return ActiveSession::updateOrCreate(
            ['session_id' => $sessionId],
            $attributes
        );
    }

    /**
     * Called by TrackActiveSession on every authenticated request.
     * Deliberately does NOT touch login_at — that's set once, by
     * startSession(). Falls back to creating the row (with login_at
     * = now) if one doesn't exist yet, which covers session-driver
     * edge cases (e.g. "remember me" auto-login) where the Login
     * event never fired for this particular session id.
     */
    public static function touchActivity(Request $request, User $user): void
    {
        $sessionId = $request->session()->getId();
        $now = now();
        $ip = $request->ip();
        $browser = self::parseBrowser($request->userAgent());
        $os = self::parseOperatingSystem($request->userAgent());

        $existing = ActiveSession::where('session_id', $sessionId)->first();

        // Safety net mirroring startSession()'s absorb logic: if this
        // exact session_id has no row yet (e.g. this request is the
        // first one to run after a mid-request session regeneration,
        // beating startSession() to it), reuse a just-created row for
        // the same user/device instead of inserting a duplicate.
        if (! $existing) {
            $existing = ActiveSession::where('user_id', $user->id)
                ->where('session_id', '!=', $sessionId)
                ->where('ip_address', $ip)
                ->where('browser', $browser)
                ->where('operating_system', $os)
                ->where('login_at', '>=', $now->copy()->subSeconds(self::SAME_LOGIN_WINDOW_SECONDS))
                ->orderByDesc('login_at')
                ->first();
        }

        $attributes = [
            'session_id' => $sessionId,
            'user_id' => $user->id,
            'login_at' => $existing?->login_at ?? $now,
            'last_activity_at' => $now,
            'current_page' => self::resolvePageLabel($request),
            'browser' => $browser,
            'operating_system' => $os,
            'ip_address' => $ip,
        ];

        if ($existing) {
            $existing->update($attributes);
        } else {
            ActiveSession::create($attributes);
        }
    }

    /**
     * Called from the Logout listener — the session disappears from
     * Active Users immediately, without waiting for the idle timeout.
     */
    public static function endSession(string $sessionId): void
    {
        ActiveSession::where('session_id', $sessionId)->delete();
    }

    /**
     * Idle cleanup — physically removes any session that has gone
     * past ActiveSession::STALE_AFTER_MINUTES without a fresh touch
     * (tab closed, connection lost, etc. with no proper logout).
     * Scheduled to run every minute — see console Kernel.
     */
    public static function pruneStale(): int
    {
        return ActiveSession::where(
            'last_activity_at',
            '<',
            now()->subMinutes(ActiveSession::STALE_AFTER_MINUTES)
        )->delete();
    }

    /**
     * Resolves the current route into a "Current Page" label. Falls
     * back to a title-cased guess from the route name's first
     * segment so a module added later without a PAGE_LABELS entry
     * still shows something reasonable instead of null.
     */
    private static function resolvePageLabel(Request $request): ?string
    {
        $routeName = $request->route()?->getName();

        if (! $routeName) {
            return null;
        }

        $prefix = explode('.', $routeName)[0];

        if (isset(self::PAGE_LABELS[$prefix])) {
            return self::PAGE_LABELS[$prefix];
        }

        return str($prefix)->replace('-', ' ')->title()->toString();
    }

    /**
     * Deliberately simple pattern matching — same spirit as
     * AuditLog::getBrowserLabelAttribute() — not a full UA-parsing
     * library, since this is only ever shown as a light descriptive
     * hint on a card, never relied on for a security decision.
     */
    private static function parseBrowser(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') && ! str_contains($ua, 'Chrome/') => 'Safari',
            $ua === '' => 'Unknown',
            default => 'Other',
        };
    }

    private static function parseOperatingSystem(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux') => 'Linux',
            $ua === '' => 'Unknown',
            default => 'Other',
        };
    }
}