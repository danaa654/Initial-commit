<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicTermRequest;
use App\Models\AcademicTerm;
use App\Services\SemesterTransitionService;
use App\Services\ActivityHistoryService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Throwable;

class AcademicTermController extends Controller implements HasMiddleware
{
    /**
     * Controller Middleware
     */
    public static function middleware(): array
    {
        return [

            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasAnyRole([
                        'Admin',
                        'Registrar',
                    ]),
                    403,
                    'Unauthorized.'
                );

                return $next($request);

            }),

        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('AcademicTerms/Index', [

            'academicTerms' => AcademicTerm::orderByDesc('academic_year')
                ->orderBy('semester')
                ->get(),

        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('AcademicTerms/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AcademicTermRequest $request)
    {
        $validated = $request->validatedForSave();

        try {

            DB::transaction(function () use ($validated) {

                if (! empty($validated['active'])) {
                    AcademicTerm::where('active', true)->update(['active' => false]);
                }

                AcademicTerm::create($validated);

            });

        } catch (Throwable $e) {

            report($e);

            return back()
                ->withInput()
                ->with('error', 'Failed to save Academic Term.');

        }

        // Fetched fresh (rather than reusing the in-transaction
        // instance) purely so ActivityHistoryService::record* below
        // has a real, persisted model with its id/display_name ready
        // — cheap, since this only runs once per Academic Term created.
        $created = AcademicTerm::where('academic_year', $validated['academic_year'])
            ->where('semester', $validated['semester'])
            ->latest('id')
            ->first();

        if ($created) {
            ActivityHistoryService::recordAcademicTermCreated($created);

            if (! empty($validated['active'])) {
                ActivityHistoryService::recordAcademicTermActivated($created);
            }
        }

        return redirect()
            ->route('academic-terms.index')
            ->with('success', 'Academic Term created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AcademicTerm $academicTerm)
    {
        return Inertia::render('AcademicTerms/Edit', [

            'academicTerm' => $academicTerm,

        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AcademicTermRequest $request, AcademicTerm $academicTerm)
    {
        // Archived Academic Terms are historical record — read-only,
        // never editable again once they reach this status.
        if ($academicTerm->status === 'Archived') {
            return redirect()
                ->route('academic-terms.index')
                ->with('warning', 'Archived Academic Terms are read-only and cannot be edited.');
        }

        $validated = $request->validatedForSave();
        $wasArchived = $academicTerm->status === 'Archived';
        $isBeingArchived = ! $wasArchived && $validated['status'] === 'Archived';
        $wasActive = $academicTerm->active;
        $isBeingActivated = ! $wasActive && ! empty($validated['active']);

        try {

            DB::transaction(function () use ($validated, $academicTerm) {

                if (! empty($validated['active'])) {
                    AcademicTerm::where('active', true)
                        ->where('id', '!=', $academicTerm->id)
                        ->update(['active' => false]);
                }

                $academicTerm->update($validated);

            });

        } catch (Throwable $e) {

            report($e);

            return back()
                ->withInput()
                ->with('error', 'Failed to save Academic Term.');

        }

        if ($isBeingArchived) {
            ActivityHistoryService::recordAcademicTermArchived($academicTerm);
        } elseif ($isBeingActivated) {
            ActivityHistoryService::recordAcademicTermActivated($academicTerm);
        }

        return redirect()
            ->route('academic-terms.index')
            ->with('success', $isBeingArchived
                ? 'Academic Term archived successfully.'
                : 'Academic Term updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicTerm $academicTerm)
    {
        // Rule 7: The active term can never be deleted — the rest of the
        // app (dashboards, scheduling, the header badge) assumes there's
        // always exactly one active term to point to.
        if ($academicTerm->active) {
            return redirect()
                ->route('academic-terms.index')
                ->with('warning', 'The active Academic Term cannot be deleted. Activate another Academic Term first.');
        }

        // Rule 10: Archived terms are permanent historical record —
        // they can never be deleted, even if they turned out to carry
        // no scheduling data at all. This is deliberately unconditional
        // (unlike Rule 9 below): an Archived term represents a semester
        // that actually happened, and erasing that record — even an
        // "empty" one — isn't something a Delete button should be able
        // to do. If a term was archived by mistake, it should be
        // restored to Draft/Published (a future "Restore" action),
        // never deleted outright.
        if ($academicTerm->status === 'Archived') {
            return redirect()
                ->route('academic-terms.index')
                ->with('warning', 'Archived Academic Terms are permanent historical record and cannot be deleted.');
        }

        // Rule 9: Terms already carrying real scheduling data must be
        // archived, not deleted, so that data is never orphaned.
        if ($academicTerm->hasSchedulingData()) {
            return redirect()
                ->route('academic-terms.index')
                ->with('warning', 'This Academic Term contains scheduling data and cannot be deleted. Archive it instead.');
        }

        $academicTerm->delete();

        return redirect()
            ->route('academic-terms.index')
            ->with('success', 'Academic Term deleted successfully.');
    }

    /**
     * The "Archive & Activate Next Term" action behind the Semester
     * Ended banner (see SemesterTransitionService, and
     * HandleInertiaRequests' 'semesterTransition' shared prop that
     * drives when the banner shows at all).
     *
     * Deliberately a single POST with no body — there is nothing for
     * the Admin/Registrar to configure here beyond "yes, close it out
     * now." Which term gets archived, and which (if any) gets
     * activated, is entirely derived server-side from the Active/
     * Planning terms at the moment this is called, so there is no way
     * to pass the wrong id by accident.
     *
     * Re-checks isActiveTermOverdue() itself rather than trusting the
     * banner was actually showing when this was clicked — the banner
     * is a convenience, not the source of truth for whether this
     * action is allowed to run.
     */
    public function closeActiveTerm(SemesterTransitionService $transitions)
    {
        if (! $transitions->isActiveTermOverdue()) {
            return back()->with('warning', 'The Active Academic Term has not reached its Class End date yet.');
        }

        $result = $transitions->closeAndActivate();

        ActivityHistoryService::recordAcademicTermArchived($result['archived']);

        if ($result['activated']) {
            ActivityHistoryService::recordAcademicTermActivated($result['activated']);
        }

        $message = "{$result['archived']->display_name} has been archived.";

        $message .= $result['activated']
            ? " {$result['activated']->display_name} is now the Active Academic Term."
            : ' No Planning Academic Term was ready to activate — activate one manually when it is.';

        return redirect()
            ->route('academic-terms.index')
            ->with('success', $message);
    }
}