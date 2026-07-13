<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Services\SchedulingWorkspaceService;
use App\Services\TermFinalizationService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

/**
 * Settings > Scheduling Workspace > College Finalization.
 *
 * Deliberately its own controller rather than folded into
 * SettingsController — Finalize/Unfinalize has its own guard clauses
 * (Active-term-only, per-college readiness) that are unrelated to the
 * Planning Term concern SettingsController owns, and keeping them
 * separate means neither file has to know about the other's rules.
 *
 * Both actions are Admin/Registrar only (route middleware below is
 * the primary enforcement; this controller's own middleware() is
 * defense in depth, matching AcademicTermController's pattern) and
 * both always operate on the CURRENT Active Academic Term — never a
 * term id taken from the request — so there is no way for stale
 * frontend state to finalize the wrong term.
 */
class TermFinalizationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly TermFinalizationService $finalization,
        private readonly SchedulingWorkspaceService $workspace,
    ) {
    }

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

    public function finalize(Department $department)
    {
        $term = $this->activeTermOrFail();

        try {
            $this->finalization->finalize($department, $term, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$department->name} has been finalized for {$term->display_name}.");
    }

    public function unfinalize(Department $department)
    {
        $term = $this->activeTermOrFail();

        try {
            $this->finalization->unfinalize($department, $term, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$department->name} is editable again for {$term->display_name}.");
    }

    private function activeTermOrFail(): AcademicTerm
    {
        $active = $this->workspace->getActiveTerm();

        abort_if(! $active, 422, 'There is no Active Academic Term to finalize against.');

        return $active;
    }
}