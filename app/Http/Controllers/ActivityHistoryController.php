<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\ActivityHistory;
use App\Services\ActivityHistoryService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;

/**
 * Activity History — the scheduling Timeline. Read-only, same shape
 * as AuditLogController: no store()/update()/destroy(), rows are
 * written exclusively by ActivityHistoryService::record() called from
 * wherever the milestone actually happens.
 *
 * Admin/Registrar only, per spec — Dean, Assistant Dean, and OIC
 * cannot reach this page at all (same restriction tier as Audit Logs,
 * NOT the broader Master Grid/Block Schedule tier).
 */
class ActivityHistoryController extends Controller implements HasMiddleware
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
     * Grouped-by-term Timeline. Unlike Audit Logs' flat table, this
     * loads every matching row for a bounded window (paginated by
     * Academic Term, not by row) and hands the frontend an array of
     * {term, activities[]} groups, newest term first, newest activity
     * first within each group — see the "grouped by term" example in
     * the spec.
     */
    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'academic_term_id' => $request->input('academic_term_id'),
            'module' => $request->input('module'),
            'event' => $request->input('event'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $activities = ActivityHistory::query()
            ->with('academicTerm:id,academic_year,semester')
            ->search($filters['search'])
            ->forTerm($filters['academic_term_id'] ? (int) $filters['academic_term_id'] : null)
            ->forModule($filters['module'])
            ->forEvent($filters['event'])
            ->betweenDates($filters['date_from'], $filters['date_to'])
            ->latest('created_at')
            // Timeline pages read the whole grouped story, not one
            // page of a flat table — 200 is generous headroom for a
            // single term's worth of milestones while still capping
            // an unbounded, unfiltered query.
            ->limit(200)
            ->get();

        $grouped = $activities
            ->groupBy(fn ($activity) => $activity->academic_term_id ?? 0)
            ->map(function ($group) {
                $term = $group->first()->academicTerm;

                return [
                    'academic_term' => $term ? [
                        'id' => $term->id,
                        'display_name' => $term->display_name,
                    ] : null,
                    'activities' => $group->values(),
                ];
            })
            // Newest term first — matches the AcademicTerm ordering
            // used everywhere else (orderByDesc academic_year, then
            // semester), derived here from the group's own newest row
            // rather than a second query.
            ->sortByDesc(fn ($group) => optional($group['activities']->first())->created_at)
            ->values();

        return Inertia::render('ActivityHistory/Index', [

            'groups' => $grouped,

            // Built as a plain array (not the raw Eloquent collection)
            // because semester_label/display_name are PHP accessors,
            // not real columns — Eloquent's default JSON serialization
            // won't include an accessor unless it's explicitly appended
            // on the model, so the frontend would otherwise see
            // `undefined` for both. Mapping here guarantees they're
            // present without needing to touch AcademicTerm's $appends.
            'academicTerms' => AcademicTerm::orderByDesc('academic_year')
                ->orderBy('semester')
                ->get(['id', 'academic_year', 'semester'])
                ->map(fn (AcademicTerm $term) => [
                    'id' => $term->id,
                    'academic_year' => $term->academic_year,
                    'semester_label' => $term->semester_label,
                    'display_name' => $term->display_name,
                ])
                ->values(),

            'moduleOptions' => ActivityHistory::query()
                ->select('module')->distinct()->orderBy('module')->pluck('module'),

            'eventOptions' => ActivityHistory::query()
                ->select('event')->distinct()->orderBy('event')->pluck('event'),

            'filters' => array_merge($filters, [
                'academic_term_id' => $filters['academic_term_id'] ? (int) $filters['academic_term_id'] : null,
            ]),

        ]);
    }
}