<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\IrregularSubjectFulfillment;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\TeachingAssignment;
use App\Services\SchedulingWorkspaceService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;

/**
 * Block Schedule — a read-only, drill-down report of what's already
 * been committed on the Master Grid, organized the way a Registrar
 * actually hands it out. Two independent folders live under this
 * module:
 *
 *   - Section Schedule: College -> Block (Section) -> weekly schedule
 *     (index()/sections()/show() below — unchanged).
 *   - Faculty Schedule: College -> Faculty -> weekly schedule
 *     (facultyIndex()/facultyList()/facultyShow() below), reading off
 *     TeachingAssignment (the Faculty Loading record) rather than
 *     Section, since a faculty member's weekly load is naturally
 *     organized around "who", not "which block".
 *
 * This is deliberately NOT another editing surface — Master Grid
 * already owns generating/editing/saving `schedules` rows, and
 * Teaching Assignments already owns assigning faculty to offerings.
 * This controller only reads.
 *
 * Same role group as Master Grid / Teaching Assignments — anyone who
 * can see the scheduling workspace has a reason to see a clean printed
 * view of it.
 */
class BlockScheduleController extends Controller implements HasMiddleware
{
    /**
     * Canonical Mon->Sat ordering for the `day` column, which is
     * stored as a plain lowercase string (no inherent sort order of
     * its own) — used so a 2x/3x subject's meeting days always print
     * "monday, wednesday" rather than whatever order the rows
     * happened to come back from the database in.
     */
    private const DAY_ORDER = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * Collapses a Subject Offering's/Teaching Assignment's full set of
     * committed Schedule rows (one per meeting day — see Schedule.php)
     * into the single day/time/room shape every Block Schedule /
     * Faculty Schedule row displays. Every meeting day shares the same
     * start/end/room (Edit Schedule only ever exposes one Start/Room
     * field for a whole 2x/3x group — see EditScheduleModal.vue), so
     * it's safe to read those off the first row and only fan out the
     * `day` value itself across all of them.
     *
     * Returns the same "Unscheduled"-friendly null shape as before
     * when there are no Schedule rows yet.
     */
    private function summarizeSchedule($schedules): array
    {
        $schedules = collect($schedules);

        if ($schedules->isEmpty()) {
            return [
                'days' => [],
                'start_minutes' => null,
                'end_minutes' => null,
                'room_code' => null,
            ];
        }

        $first = $schedules->first();

        return [
            'days' => $schedules
                ->pluck('day')
                ->unique()
                ->sortBy(fn ($day) => array_search($day, self::DAY_ORDER))
                ->values()
                ->all(),
            'start_minutes' => $first->start_minutes,
            'end_minutes' => $first->end_minutes,
            'room_code' => $first->room?->room_code,
        ];
    }

    public function __construct(
        private readonly SchedulingWorkspaceService $workspace
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
     * Level 0 — the landing page for the whole module: two top-level
     * folders, "Section Schedule" (index()/sections()/show() below)
     * and "Faculty Schedule" (facultyIndex()/facultyList()/
     * facultyShow() below).
     *
     * Deliberately does zero data-fetching beyond the Working Term
     * label; counts live one level down where they're actually
     * meaningful (per-Department), not here.
     */
    public function landing()
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        return Inertia::render('BlockSchedule/Landing', [
            'academicTerm' => $term,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Section Schedule (College -> Block -> weekly schedule)
    |--------------------------------------------------------------------------
    */

    /**
     * Level 1 — one folder per Department, showing how many distinct
     * Programs ("majors") it offers and how many Blocks (Sections)
     * currently have at least one Subject Offering this term.
     *
     * Dean/Assistant Dean/OIC are scoped to their own department (plus
     * General Education, which belongs to none) — same rule as
     * TeachingAssignmentController::managerDepartmentId(), copied
     * verbatim rather than shared so the two modules can never
     * silently drift apart.
     */
    public function index()
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $departmentId = $this->managerDepartmentId(auth()->user());

        $departments = Department::where('active', true)
            ->when($departmentId, fn ($q) => $q->where('id', $departmentId))
            ->with(['programs' => fn ($q) => $q->where('active', true)])
            ->orderBy('name')
            ->get()
            ->map(function ($department) use ($term) {
                $blockCount = $term
                    ? SubjectOffering::where('academic_term_id', $term->id)
                        ->whereHas(
                            'program',
                            fn ($q) => $q->where('department_id', $department->id)
                        )
                        ->distinct('section_id')
                        ->count('section_id')
                    : 0;

                return [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'major_count' => $department->programs->count(),
                    'block_count' => $blockCount,
                ];
            })
            ->values();

        return Inertia::render('BlockSchedule/Index', [
            'departments' => $departments,
            'academicTerm' => $term,
        ]);
    }

    /**
     * Level 2 — every Block (Section) under a Department that has at
     * least one Subject Offering this term, so the Registrar can pick
     * which one to open.
     */
    public function sections(Department $department)
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $this->assertManagesDepartment($department);

        $sections = $term
            ? Section::whereHas('curriculum.program', fn ($q) => $q->where('department_id', $department->id))
                ->whereHas('subjectOfferings', fn ($q) => $q->where('academic_term_id', $term->id))
                ->withCount([
                    'subjectOfferings as offering_count' => fn ($q) => $q->where('academic_term_id', $term->id),

                    // How many of this block's offerings already have
                    // at least one committed Master Grid Schedule row
                    // (day/time/room) — see SubjectOffering::schedule().
                    // This is what schedule_status below is derived
                    // from: comparing this against offering_count tells
                    // us whether the block is fully scheduled, partly
                    // scheduled, or untouched.
                    'subjectOfferings as scheduled_count' => fn ($q) => $q
                        ->where('academic_term_id', $term->id)
                        ->whereHas('schedule'),
                ])
                ->orderBy('year_level')
                ->orderBy('section_code')
                ->get(['id', 'section_code', 'section_name', 'year_level', 'curriculum_id'])

                // Derived, not stored — a block's schedule_status is
                // always a live reflection of offering_count vs
                // scheduled_count, so it can never drift out of sync
                // with the underlying Schedule rows the way a cached
                // status column could.
                ->map(function (Section $section) {
                    $section->schedule_status = match (true) {
                        $section->scheduled_count === 0 => 'red',
                        $section->scheduled_count < $section->offering_count => 'orange',
                        default => 'green',
                    };

                    return $section;
                })
            : collect();

        return Inertia::render('BlockSchedule/Sections', [
            'department' => $department->only(['id', 'code', 'name']),
            'sections' => $sections,
            'academicTerm' => $term,
        ]);
    }

    /**
     * Printable Class List for an ENTIRE department — every Block
     * (Section) that has Subject Offerings this term, each rendered
     * as its own table on the same document, so posting a college's
     * whole schedule is one print job instead of one per Block.
     *
     * Mirrors SubjectOfferingController::print()'s shape (a plain
     * Blade view, not Inertia, grouped-by-collection, unpaginated) for
     * the same reasons: it needs to open cleanly in its own tab and
     * let the browser's native "Print > Save as PDF" handle rendering
     * without a PDF library, and a printed handout needs every
     * matching row, not a page of 20.
     *
     * Reuses the exact same offering->schedules summarizing shape as
     * show() above (edp_code/subject_code/hours/day/room/faculty) so
     * the single-Block print preview a Registrar already knows and
     * this whole-department version never drift apart in what
     * columns they show.
     */
    public function printSections(Department $department)
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $this->assertManagesDepartment($department);

        $offerings = $term
            ? SubjectOffering::with([
                    'subject:id,subject_code,descriptive_title',
                    'section:id,section_code,section_name,year_level,is_irregular',
                    'teachingAssignment.faculty:id,first_name,last_name',
                    'teachingAssignment.schedules.room:id,room_code,building',
                ])
                ->where('academic_term_id', $term->id)
                ->whereHas(
                    'section.curriculum.program',
                    fn ($q) => $q->where('department_id', $department->id)
                )
                ->get()
            : collect();

        // Same reasoning as show() below: an Irregular Section's
        // reused Subjects (via IrregularSubjectFulfillment) live under
        // a different Section's section_id in subject_offerings, so
        // they need to be fetched and credited back to the Irregular
        // Section separately, keyed by fulfillment->section_id rather
        // than the Regular Section that actually owns the row.
        $fulfillmentsBySection = collect();

        if ($term) {
            $fulfillmentsBySection = IrregularSubjectFulfillment::whereHas(
                    'section.curriculum.program',
                    fn ($q) => $q->where('department_id', $department->id)
                )
                ->whereHas('subjectOffering', fn ($q) => $q->where('academic_term_id', $term->id))
                ->with([
                    'section:id,section_code,year_level,is_irregular',
                    'subjectOffering.subject:id,subject_code,descriptive_title',
                    'subjectOffering.teachingAssignment.faculty:id,first_name,last_name',
                    'subjectOffering.teachingAssignment.schedules.room:id,room_code,building',
                    'subjectOffering.section:id,section_code',
                ])
                ->get()
                ->groupBy('section_id');
        }

        // Grouped by Block so the printout reads "BSIT-1A" as its own
        // header with its Subjects listed underneath, one table per
        // Block, rather than one long flat table repeating the Block
        // on every row — same grouping shape as
        // SubjectOfferingController::print()'s Section grouping.
        $blocks = $offerings
            ->groupBy('section_id')
            ->map(function ($group) use (&$fulfillmentsBySection) {
                $first = $group->first();
                $section = $first->section;

                $ownRows = $group
                    ->sortBy('edp_code')
                    ->values()
                    ->map(function (SubjectOffering $offering) {
                        $summary = $this->summarizeSchedule($offering->teachingAssignment?->schedules ?? []);

                        return [
                            'edp_code' => $offering->edp_code,
                            'subject_code' => $offering->subject?->subject_code,
                            'descriptive_title' => $offering->subject?->descriptive_title,
                            'units' => $offering->units,
                            'faculty_name' => $offering->teachingAssignment?->faculty?->full_name,
                            'reused_from_section' => null,
                            ...$summary,
                        ];
                    });

                $reusedRows = ($fulfillmentsBySection->get($section?->id) ?? collect())
                    ->map(function (IrregularSubjectFulfillment $fulfillment) {
                        $offering = $fulfillment->subjectOffering;
                        $summary = $this->summarizeSchedule($offering?->teachingAssignment?->schedules ?? []);

                        return [
                            'edp_code' => $offering?->edp_code,
                            'subject_code' => $offering?->subject?->subject_code,
                            'descriptive_title' => $offering?->subject?->descriptive_title,
                            'units' => $offering?->units,
                            'faculty_name' => $offering?->teachingAssignment?->faculty?->full_name,
                            'reused_from_section' => $offering?->section?->section_code,
                            ...$summary,
                        ];
                    });

                // Once matched here, remove from the leftover map below
                // so a Section with SOME of its own offerings doesn't
                // also get double-added by the leftover pass.
                $fulfillmentsBySection->forget($section?->id);

                return [
                    'section_code' => $section?->section_code ?? 'Unassigned Block',
                    'year_level' => $section?->year_level,
                    'is_irregular' => (bool) $section?->is_irregular,
                    'offerings' => $ownRows->concat($reusedRows)->sortBy('edp_code')->values(),
                ];
            });

        // Irregular Sections whose Subjects were ENTIRELY covered by
        // reuse never appear in $offerings at all (no Subject Offering
        // of their own exists to group by), so they'd silently vanish
        // from the printout without this — one block per Section still
        // left in the map after the pass above.
        $leftoverBlocks = $fulfillmentsBySection->map(function ($fulfillments, $sectionId) {
            $section = $fulfillments->first()->section;

            return [
                'section_code' => $section?->section_code ?? 'Unassigned Block',
                'year_level' => $section?->year_level,
                'is_irregular' => (bool) $section?->is_irregular,
                'offerings' => $fulfillments
                    ->map(function (IrregularSubjectFulfillment $fulfillment) {
                        $offering = $fulfillment->subjectOffering;
                        $summary = $this->summarizeSchedule($offering?->teachingAssignment?->schedules ?? []);

                        return [
                            'edp_code' => $offering?->edp_code,
                            'subject_code' => $offering?->subject?->subject_code,
                            'descriptive_title' => $offering?->subject?->descriptive_title,
                            'units' => $offering?->units,
                            'faculty_name' => $offering?->teachingAssignment?->faculty?->full_name,
                            'reused_from_section' => $offering?->section?->section_code,
                            ...$summary,
                        ];
                    })
                    ->sortBy('edp_code')
                    ->values(),
            ];
        })->values();

        $blocks = $blocks
            ->values()
            ->concat($leftoverBlocks)
            ->sortBy(['year_level', 'section_code'])
            ->values();

        return view('block-schedule.print', [
            'department' => $department,
            'blocks' => $blocks,
            'academicTerm' => $term,
            'generatedAt' => now(),
        ]);
    }

    /**
     * Level 3 — the actual block schedule: one row per Subject
     * Offering in this Section for the Working Term, with Day/Time/
     * Room pulled from its committed Schedule (via
     * teachingAssignment->schedules — see TeachingAssignment::schedules())
     * and Faculty from its Teaching Assignment. A Subject Offering
     * that hasn't been scheduled yet on Master Grid simply shows
     * "Unscheduled" / "TBA" — this page never invents a placement.
     */
    public function show(Department $department, Section $section)
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $this->assertManagesDepartment($department);

        $offerings = $term
            ? SubjectOffering::with([
                    'subject:id,subject_code,descriptive_title',
                    'teachingAssignment.faculty:id,first_name,last_name',
                    'teachingAssignment.schedules.room:id,room_code,building',
                ])
                ->where('academic_term_id', $term->id)
                ->where('section_id', $section->id)
                ->get()
                ->map(function (SubjectOffering $offering) {
                    $summary = $this->summarizeSchedule($offering->teachingAssignment?->schedules ?? []);

                    return [
                        'id' => $offering->id,
                        'edp_code' => $offering->edp_code,
                        'subject_code' => $offering->subject?->subject_code,
                        'descriptive_title' => $offering->subject?->descriptive_title,
                        'units' => $offering->units,
                        'faculty_name' => $offering->teachingAssignment?->faculty?->full_name,
                        'reused_from_section' => null,
                        ...$summary,
                    ];
                })
            : collect();

        // Irregular Sections can also cover a Subject by reusing an
        // existing Regular Section's Subject Offering instead of
        // getting one of their own (see
        // SubjectOfferingController::storeIrregular()) — those never
        // show up in the query above since their section_id still
        // points at the Regular Section that actually owns them. This
        // Section's printed schedule needs to show them anyway (the
        // students really are in that class), just clearly marked with
        // which Section's schedule they're actually following.
        if ($term && $section->is_irregular) {
            $fulfilled = IrregularSubjectFulfillment::where('section_id', $section->id)
                ->whereHas('subjectOffering', fn ($q) => $q->where('academic_term_id', $term->id))
                ->with([
                    'subjectOffering.subject:id,subject_code,descriptive_title',
                    'subjectOffering.teachingAssignment.faculty:id,first_name,last_name',
                    'subjectOffering.teachingAssignment.schedules.room:id,room_code,building',
                    'subjectOffering.section:id,section_code',
                ])
                ->get()
                ->map(function (IrregularSubjectFulfillment $fulfillment) {
                    $offering = $fulfillment->subjectOffering;
                    $summary = $this->summarizeSchedule($offering?->teachingAssignment?->schedules ?? []);

                    return [
                        'id' => $offering?->id,
                        'edp_code' => $offering?->edp_code,
                        'subject_code' => $offering?->subject?->subject_code,
                        'descriptive_title' => $offering?->subject?->descriptive_title,
                        'units' => $offering?->units,
                        'faculty_name' => $offering?->teachingAssignment?->faculty?->full_name,
                        'reused_from_section' => $offering?->section?->section_code,
                        ...$summary,
                    ];
                });

            $offerings = $offerings->concat($fulfilled);
        }

        $offerings = $offerings->sortBy('edp_code')->values();

        return Inertia::render('BlockSchedule/Show', [
            'department' => $department->only(['id', 'code', 'name']),
            'section' => $section->only(['id', 'section_code', 'section_name', 'year_level', 'is_irregular']),
            'offerings' => $offerings,
            'academicTerm' => $term,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Faculty Schedule (College -> Faculty -> weekly schedule)
    |--------------------------------------------------------------------------
    |
    | Reads TeachingAssignment (Faculty Loading's core record — one
    | faculty member teaching one Subject Offering) rather than
    | Section. "Which department does this assignment belong to" is
    | resolved the same way BlockScheduleController already resolves
    | it for Section Schedule: through the Offering's
    | section -> curriculum -> program -> department chain (NOT
    | Faculty::department_id — a faculty member can carry assignments
    | across departments, e.g. a General Education instructor loaned
    | out to CCS, so the department that owns a given assignment is a
    | property of the Offering being taught, not of the Faculty row).
    |
    */

    /**
     * Level 1 (Faculty side) — one folder per Department, showing how
     * many distinct faculty members currently have at least one
     * Teaching Assignment against that department's offerings this
     * term.
     */
    public function facultyIndex()
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $departmentId = $this->managerDepartmentId(auth()->user());

        $departments = Department::where('active', true)
            ->when($departmentId, fn ($q) => $q->where('id', $departmentId))
            ->orderBy('name')
            ->get()
            ->map(function ($department) use ($term) {
                $facultyCount = $term
                    ? TeachingAssignment::forTerm($term->id)
                        ->whereHas(
                            'faculty',
                            fn ($q) => $q->where('department_id', $department->id)
                        )
                        ->distinct('faculty_id')
                        ->count('faculty_id')
                    : 0;

                return [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'faculty_count' => $facultyCount,
                    'is_general' => false,
                ];
            })
            ->values();

        // General Education faculty carry no department_id of their
        // own (they serve every college — see
        // TeachingAssignmentController::managerDepartmentId()'s
        // docblock), so they don't belong in any of the folders above.
        // Every manager, scoped or not, is allowed to see them (the
        // same rule that already lets a scoped Dean/OIC manage GenEd
        // faculty in Faculty Loading), so this folder is appended
        // unconditionally rather than gated behind managerDepartmentId().
        $departments->push([
            'id' => 'general',
            'code' => 'GEN',
            'name' => 'General Education',
            'faculty_count' => $term
                ? TeachingAssignment::forTerm($term->id)
                    ->whereHas('faculty', fn ($q) => $q->whereNull('department_id'))
                    ->distinct('faculty_id')
                    ->count('faculty_id')
                : 0,
            'is_general' => true,
        ]);

        return Inertia::render('BlockSchedule/FacultyDepartments', [
            'departments' => $departments,
            'academicTerm' => $term,
        ]);
    }

    /**
     * Level 2 (Faculty side) — every faculty member who *belongs* to
     * this Department (faculty.department_id, not the offering they
     * happen to be teaching) and has at least one Teaching Assignment
     * this term, with how many subjects they're currently assigned,
     * so the Registrar can pick whose weekly schedule to open.
     *
     * Scoped by the faculty member's own home department rather than
     * by the offering's department chain: a CCS folder should show
     * only CCS faculty, even if one of them is currently teaching a
     * borrowed COC section. Faculty with no home department (GenEd)
     * never appear here — see facultyGeneralList() for their folder.
     *
     * Grouped in PHP (rather than via a `Faculty::withCount()` on an
     * assumed `teachingAssignments()` relation) so this stays correct
     * regardless of whether Faculty ever grows that relation — the
     * TeachingAssignment side of the pair is the one this whole module
     * already depends on.
     */
    public function facultyList(Department $department)
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $this->assertManagesDepartment($department);

        $assignments = $term
            ? TeachingAssignment::with('faculty:id,first_name,last_name,department_id')
                ->forTerm($term->id)
                ->whereHas(
                    'faculty',
                    fn ($q) => $q->where('department_id', $department->id)
                )
                ->get()
            : collect();

        $facultyMembers = $assignments
            ->filter(fn (TeachingAssignment $ta) => $ta->faculty !== null)
            ->groupBy('faculty_id')
            ->map(function ($group) {
                $faculty = $group->first()->faculty;

                return [
                    'id' => $faculty->id,
                    'full_name' => $faculty->full_name,
                    'assignment_count' => $group->count(),
                ];
            })
            ->sortBy('full_name')
            ->values();

        return Inertia::render('BlockSchedule/FacultyList', [
            'department' => $department->only(['id', 'code', 'name']) + ['is_general' => false],
            'facultyMembers' => $facultyMembers,
            'academicTerm' => $term,
        ]);
    }

    /**
     * Level 2 (General Education) — every faculty member with no
     * department_id of their own who has at least one Teaching
     * Assignment this term, regardless of which college's offering
     * they're teaching it for. Unlike facultyList() above, this isn't
     * gated by assertManagesDepartment(): a scoped Dean/OIC is already
     * permitted to manage/view General Education faculty (see
     * facultyIndex()), so every authenticated manager sees the same
     * General Education roster.
     */
    public function facultyGeneralList()
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $assignments = $term
            ? TeachingAssignment::with('faculty:id,first_name,last_name,department_id')
                ->forTerm($term->id)
                ->whereHas('faculty', fn ($q) => $q->whereNull('department_id'))
                ->get()
            : collect();

        $facultyMembers = $assignments
            ->filter(fn (TeachingAssignment $ta) => $ta->faculty !== null)
            ->groupBy('faculty_id')
            ->map(function ($group) {
                $faculty = $group->first()->faculty;

                return [
                    'id' => $faculty->id,
                    'full_name' => $faculty->full_name,
                    'assignment_count' => $group->count(),
                ];
            })
            ->sortBy('full_name')
            ->values();

        return Inertia::render('BlockSchedule/FacultyList', [
            'department' => [
                'id' => 'general',
                'code' => 'GEN',
                'name' => 'General Education',
                'is_general' => true,
            ],
            'facultyMembers' => $facultyMembers,
            'academicTerm' => $term,
        ]);
    }

    /**
     * Printable Faculty Schedule for a WHOLE department — every
     * faculty member in it who has a Teaching Assignment this term,
     * each on their own page, so posting a college's whole faculty
     * load is one print job instead of one per faculty member. Mirrors
     * printSections()'s shape (plain Blade view, grouped, unpaginated)
     * for the same reasons — see that method's docblock.
     */
    public function printFacultyList(Department $department)
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $this->assertManagesDepartment($department);

        $facultyMembers = $term
            ? $this->facultyModelsWithAssignments($department->id, $term->id)
            : collect();

        return view('block-schedule.faculty-print', [
            'department' => $department->only(['id', 'code', 'name']),
            'facultySchedules' => $facultyMembers->map(fn (Faculty $faculty) => [
                'faculty' => $faculty,
                'rows' => $this->facultyScheduleRows($faculty, $term),
            ]),
            'academicTerm' => $term,
            'generatedAt' => now(),
        ]);
    }

    /**
     * The General Education counterpart to printFacultyList() above —
     * same "one faculty member per page" shape, scoped to
     * department_id-null faculty instead of a real Department. No
     * assertManagesDepartment() call, same reasoning as
     * facultyGeneralList()'s docblock.
     */
    public function printFacultyGeneral()
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $facultyMembers = $term
            ? $this->facultyModelsWithAssignments(null, $term->id)
            : collect();

        return view('block-schedule.faculty-print', [
            'department' => [
                'id' => 'general',
                'code' => 'GEN',
                'name' => 'General Education',
            ],
            'facultySchedules' => $facultyMembers->map(fn (Faculty $faculty) => [
                'faculty' => $faculty,
                'rows' => $this->facultyScheduleRows($faculty, $term),
            ]),
            'academicTerm' => $term,
            'generatedAt' => now(),
        ]);
    }

    /**
     * Every Faculty model with at least one Teaching Assignment this
     * term, scoped by department_id — pass null to get General
     * Education faculty (department_id IS NULL) instead of a real
     * department. Shared by facultyList()/facultyGeneralList()'s print
     * counterparts above; unlike those two Inertia methods (which only
     * need id/full_name/assignment_count for the folder cards), the
     * print views need the actual Faculty model to hand to
     * facultyScheduleRows().
     */
    private function facultyModelsWithAssignments(?int $departmentId, int $academicTermId)
    {
        $assignments = TeachingAssignment::with('faculty:id,first_name,last_name,department_id')
            ->forTerm($academicTermId)
            ->whereHas('faculty', fn ($q) => $departmentId
                ? $q->where('department_id', $departmentId)
                : $q->whereNull('department_id'))
            ->get();

        return $assignments
            ->filter(fn (TeachingAssignment $ta) => $ta->faculty !== null)
            ->groupBy('faculty_id')
            ->map(fn ($group) => $group->first()->faculty)
            ->sortBy('full_name')
            ->values();
    }

    /**
     * Level 3 (Faculty side) — the actual faculty weekly schedule: one
     * row per Teaching Assignment this faculty member holds this term,
     * with Subject from the Offering and Day/Time/Room/Block pulled
     * from the assignment's committed Schedule (via
     * TeachingAssignment::schedule()) — same "Unscheduled" / "TBA"
     * fallback as Section Schedule's show() for anything Generate
     * Schedule + Save Schedule hasn't reached yet.
     *
     * Deliberately shows every assignment this faculty member holds
     * this term (not just the ones under $department) — a faculty
     * member's own weekly schedule needs to be complete, including any
     * cross-department load, or a Dean would be looking at a
     * misleadingly partial week. $department here is kept only for
     * the RBAC check and the "back to" breadcrumb.
     */
    public function facultyShow(Department $department, Faculty $faculty)
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        $this->assertManagesDepartment($department);

        return Inertia::render('BlockSchedule/FacultyShow', [
            'department' => $department->only(['id', 'code', 'name']) + ['is_general' => false],
            'faculty' => $faculty->only(['id', 'first_name', 'last_name']) + ['full_name' => $faculty->full_name],
            'assignments' => $this->facultyScheduleRows($faculty, $term),
            'academicTerm' => $term,
        ]);
    }

    /**
     * Level 3 (General Education) — same weekly-schedule view as
     * facultyShow() above, for a faculty member with no department_id
     * of their own. No assertManagesDepartment() call here — see
     * facultyGeneralList()'s docblock for why.
     */
    public function facultyGeneralShow(Faculty $faculty)
    {
        $term = $this->workspace->getTermForUser(auth()->user());

        return Inertia::render('BlockSchedule/FacultyShow', [
            'department' => [
                'id' => 'general',
                'code' => 'GEN',
                'name' => 'General Education',
                'is_general' => true,
            ],
            'faculty' => $faculty->only(['id', 'first_name', 'last_name']) + ['full_name' => $faculty->full_name],
            'assignments' => $this->facultyScheduleRows($faculty, $term),
            'academicTerm' => $term,
        ]);
    }

    /**
     * Shared by facultyShow() and facultyGeneralShow(): every Teaching
     * Assignment this faculty member holds this term, with Subject/
     * Block from the Offering and Day/Time/Room from the assignment's
     * committed Schedule. Deliberately not scoped to any one
     * department — a faculty member's own weekly schedule needs to be
     * complete, including any cross-department load, or a Dean/GenEd
     * view would be showing a misleadingly partial week.
     */
    private function facultyScheduleRows(Faculty $faculty, $term)
    {
        if (! $term) {
            return collect();
        }

        return TeachingAssignment::with([
                'subjectOffering.subject:id,subject_code,descriptive_title',
                'subjectOffering.section:id,section_code,section_name',
                'schedules.room:id,room_code,building',
            ])
            ->where('faculty_id', $faculty->id)
            ->forTerm($term->id)
            ->get()
            ->map(function (TeachingAssignment $ta) {
                $offering = $ta->subjectOffering;
                $summary = $this->summarizeSchedule($ta->schedules);

                return [
                    'id' => $ta->id,
                    'edp_code' => $offering?->edp_code,
                    'subject_code' => $offering?->subject?->subject_code,
                    'descriptive_title' => $offering?->subject?->descriptive_title,
                    'section_code' => $offering?->section?->section_code,
                    'units' => $offering?->units,
                    ...$summary,
                ];
            })
            ->sortBy('edp_code')
            ->values();
    }

    /**
     * RBAC guard mirroring TeachingAssignmentController::assertManagesFaculty()
     * — a scoped Dean/OIC hitting another department's Block Schedule
     * URL directly still 403s, even though sections()/show() and
     * facultyList()/facultyShow() above already narrow what index()/
     * facultyIndex() link to.
     */
    private function assertManagesDepartment(Department $department): void
    {
        $departmentId = $this->managerDepartmentId(auth()->user());

        if ($departmentId === null) {
            return;
        }

        if ((int) $department->id !== $departmentId) {
            abort(403, 'You do not have permission to view this department\'s block schedules.');
        }
    }

    /**
     * Same rule as TeachingAssignmentController::managerDepartmentId()
     * — copied verbatim rather than shared, on purpose (see that
     * method's docblock).
     */
    private function managerDepartmentId($user): ?int
    {
        if ($user->hasAnyRole(['Admin', 'Registrar', 'Assistant Dean'])) {
            return null;
        }

        return $user->department_id;
    }
}