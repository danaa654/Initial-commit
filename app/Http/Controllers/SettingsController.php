<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePlanningAcademicTermRequest;
use App\Models\AcademicTerm;
use App\Services\SchedulingWorkspaceService;
use App\Services\SubjectOfferingGeneratorService;
use App\Services\ActivityHistoryService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;

/**
 * Settings > Scheduling Workspace.
 *
 * Lets Admin/Registrar point every scheduling module (Subject
 * Offerings, Faculty Loading, Teaching Assignments, Room Management,
 * Master Grid, etc.) at a Planning Academic Term that can be months
 * ahead of whatever term is currently Active — see
 * SchedulingWorkspaceService for the full Active-vs-Planning
 * distinction.
 *
 * Dean, Assistant Dean, and OIC can view this page (so they always
 * know which term the scheduling modules are currently pointed at)
 * but the update endpoint is restricted to Admin/Registrar, both by
 * route middleware (role:Admin|Registrar in web.php) and again by
 * UpdatePlanningAcademicTermRequest::authorize() as defense in depth.
 *
 * updateSchedulingWorkspace() is deliberately the single write path
 * for the Planning/Working Term — both the full Settings page AND the
 * Topbar's quick "Switch Working Term" dropdown submit here (see the
 * 'settings.scheduling-workspace.update' and 'working-term.update'
 * routes in web.php, which are two names for the same route/method).
 * That keeps there being exactly one place that ever calls
 * SchedulingWorkspaceService::setPlanningTerm().
 */
class SettingsController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SchedulingWorkspaceService $workspace,
        private readonly \App\Services\TermFinalizationService $finalization,
        private readonly SubjectOfferingGeneratorService $generator,
    ) {
    }

    public static function middleware(): array
    {
        return [

            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasAnyRole([
                        'Admin',
                        'Registrar',
                        'Dean',
                        'Assistant Dean',
                        'OIC',
                    ]),
                    403,
                    'Unauthorized.'
                );

                return $next($request);

            }),

        ];
    }

    /**
     * Display the Scheduling Workspace settings section.
     */
    public function schedulingWorkspace()
    {
        return Inertia::render('Settings/SchedulingWorkspace', [

            'activeTerm' => $this->workspace->getActiveTerm(),

            'planningTerm' => $this->workspace->getPlanningTerm(),

            // Every term is a valid pick for Planning — including past
            // and Archived ones is intentional, since a Registrar may
            // legitimately need to point scheduling back at a term
            // that hasn't been fully wound down yet. The dropdown
            // itself is free to sort/present these however is
            // clearest; no rows are filtered out here.
            'academicTerms' => AcademicTerm::orderByDesc('academic_year')
                ->orderBy('semester')
                ->get(),

            'can' => [
                'edit' => auth()->user()->hasAnyRole(['Admin', 'Registrar']),
            ],

            // College Finalization Status table — always computed
            // against the ACTIVE term (Rule 1: Planning status is
            // irrelevant to finalization), never the Planning term
            // above. Empty array when there's no Active term yet, in
            // which case the Vue side disables every Finalize button.
            'departmentFinalizations' => $this->workspace->getActiveTerm()
                ? $this->finalization->getFinalizationStatus($this->workspace->getActiveTerm()->id)
                : [],

        ]);
    }

    /**
     * Update the Planning/Working Academic Term. Restricted to
     * Admin/Registrar via the 'role:Admin|Registrar' route middleware
     * in web.php — this method itself is reached by two route names
     * (see class docblock above).
     *
     * Deliberately does nothing except call setPlanningTerm() — this
     * must never touch the `active` column, move data between terms,
     * or duplicate/delete any schedule. It only changes which term
     * the scheduling modules currently read/write against.
     */
    public function updateSchedulingWorkspace(UpdatePlanningAcademicTermRequest $request)
    {
        $term = AcademicTerm::findOrFail($request->validated('academic_term_id'));

        $this->workspace->setPlanningTerm($term);

        ActivityHistoryService::recordWorkingTermChanged($term);

        // Catch-up generation: any Regular, Active Section that
        // doesn't have offerings yet for this Term gets them now —
        // covers Sections created while no Working Term was set, and
        // Sections that already existed before auto-generation was
        // added. See SubjectOfferingGeneratorService::
        // generateForAllRegularSections() for the full reasoning.
        // Skipped for an Archived Term (read-only) — same rule
        // SchedulingWorkspaceService::assertWritable() enforces
        // everywhere else offerings get written.
        $message = 'Working Academic Term is now ' . $term->display_name . '.';

        if ($term->status !== 'Archived') {
            $summary = $this->generator->generateForAllRegularSections($term, $request->user());

            if ($summary['created'] > 0) {
                $message .= " {$summary['created']} Subject Offering(s) were generated for Sections that didn't have them yet.";
            }
        }

        // The Topbar switcher does a preserveScroll/preserveState PUT
        // from wherever the user currently is (any page in the app),
        // not necessarily the Settings page — redirecting back() keeps
        // them exactly where they were instead of yanking them to
        // Settings every time they use the quick switcher.
        return back()->with('success', $message);
    }
}