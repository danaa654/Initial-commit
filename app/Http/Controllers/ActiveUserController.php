<?php

namespace App\Http\Controllers;

use App\Models\ActiveSession;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/**
 * System Monitor > Active Users — read-only, real-time-ish view of
 * who is currently logged into Classly. First feature of the System
 * Monitor module (see Sidebar.vue's "monitoring" group).
 *
 * System Administrators ONLY — stricter than Audit Logs/Activity
 * History (which are Admin + Registrar). Registrar, Dean, Assistant
 * Dean, and OIC must not reach this page at all.
 *
 * Deliberately has no store()/update()/destroy() — ActiveSession rows
 * are written exclusively by ActiveSessionService, called from
 * TrackActiveSession middleware and the Login/Logout listeners, never
 * from this controller. See that service's docblock for the full
 * write path.
 */
class ActiveUserController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasRole('Admin'),
                    403,
                    'Unauthorized.'
                );

                return $next($request);

            }),
        ];
    }

    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'role' => $request->input('role'),
            'department_id' => $request->input('department_id'),
            'status' => $request->input('status'),
        ];

        // scopeLive() excludes anything past the 10-minute stale
        // threshold, so "Automatically removed... after 10 minutes of
        // inactivity" holds true for the page even a few seconds
        // before PruneStaleActiveSessions physically deletes the row.
        $sessions = ActiveSession::query()
            ->live()
            ->forStatus($filters['status'])
            ->with(['user' => function ($query) {
                $query->with(['roles', 'department']);
            }])
            ->when($filters['role'], function ($query, $role) {
                $query->whereHas('user.roles', fn ($q) => $q->where('name', $role));
            })
            ->when($filters['department_id'], function ($query, $departmentId) {
                $query->whereHas('user', fn ($q) => $q->where('department_id', $departmentId));
            })
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $like = '%'.strtolower($filters['search']).'%';

                $query->whereHas('user', function ($q) use ($like) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                });
            })
            ->orderByDesc('last_activity_at')
            ->get();

        $activeUsers = $sessions->map(fn (ActiveSession $session) => [
            'id' => $session->id,
            'user' => [
                'id' => $session->user->id,
                'name' => $session->user->name,
                'initials' => collect(explode(' ', $session->user->name))
                    ->map(fn ($part) => strtoupper($part[0] ?? ''))
                    ->take(2)
                    ->implode(''),
            ],
            'role' => $session->user->getRoleNames()->first(),
            'department' => $session->user->hasAnyRole(['Admin', 'Registrar', 'Assistant Dean'])
                ? 'All Departments'
                : optional($session->user->department)->abbreviation,
            'login_at' => $session->login_at,
            'last_activity_at' => $session->last_activity_at,
            'current_page' => $session->current_page,
            'browser' => $session->browser,
            'operating_system' => $session->operating_system,
            'ip_address' => $session->ip_address,
            'status' => $session->status,
            'status_label' => $session->status_label,
        ])->values();

        // Summary cards — computed off the full live (unfiltered by
        // search/role/department, but still stale-excluded) set, so
        // the counters stay stable reference points while someone
        // narrows the list below with filters.
        $liveSessions = ActiveSession::query()
            ->live()
            ->with('user.roles')
            ->get();

        $onlineCount = $liveSessions->filter(fn ($s) => $s->status === 'online')->count();
        $idleCount = $liveSessions->filter(fn ($s) => $s->status === 'idle')->count();

        $roleOnlineCount = function (string $role) use ($liveSessions) {
            return $liveSessions
                ->filter(fn ($s) => $s->status === 'online' && $s->user->hasRole($role))
                ->count();
        };

        $summary = [
            'online' => $onlineCount,
            'idle' => $idleCount,
            'total' => $liveSessions->count(),
            'admin_online' => $roleOnlineCount('Admin'),
            'registrar_online' => $roleOnlineCount('Registrar'),
            // Dean and OIC are combined per the spec's summary card
            // list ("Dean/OIC Online").
            'dean_oic_online' => $liveSessions
                ->filter(fn ($s) => $s->status === 'online' && $s->user->hasAnyRole(['Dean', 'OIC']))
                ->count(),
            'assistant_dean_online' => $roleOnlineCount('Assistant Dean'),
        ];

        return Inertia::render('ActiveUsers/Index', [

            'activeUsers' => $activeUsers,

            'summary' => $summary,

            'filters' => array_merge($filters, [
                'department_id' => $filters['department_id'] ? (int) $filters['department_id'] : null,
            ]),

            'roleOptions' => Role::orderBy('name')->pluck('name'),

            'departmentOptions' => Department::where('active', true)
                ->orderBy('abbreviation')
                ->get(['id', 'abbreviation']),

        ]);
    }
}