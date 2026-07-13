<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;

/**
 * Audit Logs — the security/accountability record for the whole
 * system. Read-only: there is deliberately no store()/update()/
 * destroy() here. Rows are written exclusively by
 * AuditLogService::log(), called from wherever an action happens
 * elsewhere in the app — never through this controller.
 *
 * Admin/Registrar only — Dean, Assistant Dean, and OIC cannot reach
 * this page at all (unlike Faculty Loading/Master Grid/etc., which
 * they can view in a scoped, read-only way). Audit Logs carry
 * system-wide visibility across every department, so there is no
 * scoped version of this page for them to see instead.
 */
class AuditLogController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasAnyRole(['Admin', 'Registrar']),
                    403,
                    'Unauthorized.'
                );

                return $next($request);

            }),
        ];
    }

    /**
     * Paginated, filtered listing — newest first. Every filter is
     * optional and combinable; see AuditLog's scope* methods for how
     * each one is applied.
     */
    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'user_id' => $request->input('user_id'),
            'role' => $request->input('role'),
            'module' => $request->input('module'),
            'action' => $request->input('action'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $logs = AuditLog::query()
            ->search($filters['search'])
            ->forUser($filters['user_id'] ? (int) $filters['user_id'] : null)
            ->forRole($filters['role'])
            ->forModule($filters['module'])
            ->forAction($filters['action'])
            ->betweenDates($filters['date_from'], $filters['date_to'])
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('AuditLogs/Index', [

            'logs' => $logs,

            'filters' => array_merge($filters, [
                'user_id' => $filters['user_id'] ? (int) $filters['user_id'] : null,
            ]),

            // Populated from real distinct rows already in the table
            // rather than the full static AuditLogService::MODULES/
            // ACTIONS lists — the filter dropdown should only ever
            // offer values that actually have at least one log entry,
            // so it never shows an option that returns zero results.
            'moduleOptions' => AuditLog::query()
                ->select('module')
                ->distinct()
                ->orderBy('module')
                ->pluck('module'),

            'actionOptions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),

            'roleOptions' => AuditLog::query()
                ->whereNotNull('role')
                ->select('role')
                ->distinct()
                ->orderBy('role')
                ->pluck('role'),

            // For the "User" filter dropdown — every user who has at
            // least one log row, not the full users table, so the
            // dropdown doesn't grow unbounded with accounts that have
            // never done anything auditable.
            'userOptions' => User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),

        ]);
    }

    /**
     * Row detail — the side panel/modal content. A dedicated JSON
     * endpoint rather than embedding full old_values/new_values in
     * every row of index()'s paginated payload, since most rows are
     * viewed as a list and never opened; this keeps the list payload
     * lean and only loads the (potentially large) diff JSON for the
     * one row actually being inspected.
     */
    public function show(AuditLog $auditLog)
    {
        return response()->json([
            'log' => $auditLog->only([
                'id', 'user_name', 'role', 'action', 'module',
                'record_type', 'record_id', 'record_name',
                'description', 'old_values', 'new_values',
                'ip_address', 'user_agent', 'browser_label', 'created_at',
            ]),
        ]);
    }
}