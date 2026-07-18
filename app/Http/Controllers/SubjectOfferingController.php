<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkUpdateWeeklyHoursRequest;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\IrregularSubjectFulfillment;
use App\Models\Program;
use App\Models\Room;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\SubjectOffering;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\SubjectOfferingsGenerated;
use App\Services\EdpCodeService;
use App\Services\SchedulingWorkspaceService;
use App\Services\RoomCapacityService;
use App\Services\SubjectOfferingGeneratorService;
use App\Services\TeachingAssignmentService;
use App\Services\AuditLogService;
use App\Services\ActivityHistoryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SubjectOfferingController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SubjectOfferingGeneratorService $generator,
        private readonly SchedulingWorkspaceService $workspace,
        private readonly TeachingAssignmentService $teachingAssignmentService,
        private readonly RoomCapacityService $capacity
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
     * Same rule as BlockScheduleController::managerDepartmentId() /
     * TeachingAssignmentController::managerDepartmentId() — copied
     * verbatim rather than shared, on purpose, so these modules can
     * never silently drift apart from each other. Admin, Registrar,
     * and Assistant Dean see every department (Assistant Dean is a
     * cross-department operational role in this system, unlike
     * Dean/OIC); a Dean or OIC only ever sees/acts on their OWN
     * department's Subject Offerings.
     */
    private function managerDepartmentId($user): ?int
    {
        if ($user->hasAnyRole(['Admin', 'Registrar', 'Assistant Dean'])) {
            return null;
        }

        return $user->department_id;
    }

    /**
     * Whether $user may assign Faculty / set a Preferred Room / delete
     * $subjectOffering. Admin/Registrar/Assistant Dean always can.
     * Dean/OIC only for an offering whose Program belongs to their own
     * Department — re-checked here (not just relied on via the
     * already-scoped index() query) so a direct API call can't smuggle
     * in another department's offering id.
     */
    private function canManageOffering($user, SubjectOffering $subjectOffering): bool
    {
        $departmentId = $this->managerDepartmentId($user);

        if ($departmentId === null) {
            return true;
        }

        $subjectOffering->loadMissing('program');

        return $subjectOffering->program?->department_id === $departmentId;
    }

    private function assertManagesOffering(SubjectOffering $subjectOffering): void
    {
        abort_unless(
            $this->canManageOffering(auth()->user(), $subjectOffering),
            403,
            'You do not have permission to manage this Subject Offering — it belongs to another department.'
        );
    }

    /**
     * Shared filter logic used by both index() (paginated, Inertia)
     * and print() (unpaginated, Blade). Keeping this in one place
     * means the Print button always reflects exactly what's on
     * screen — same academic_term_id/program_id/specialization_id/
     * year_level/section_id/search, applied the same way.
     *
     * NOTE: deliberately does NOT apply the `status` filter — status
     * is derived in PHP after the query runs (see index()), and the
     * print view has no use for it anyway since it never shows
     * Faculty/Room/Status columns.
     */
    private function filteredOfferingsQuery(Request $request)
    {
        $academicTermId = $request->input('academic_term_id')
            ?: $this->workspace->getTermForUser(auth()->user())?->id;

        $programId = $request->input('program_id');
        $specializationId = $request->input('specialization_id');
        $yearLevel = $request->input('year_level');
        $sectionId = $request->input('section_id');
        $search = trim((string) $request->input('search', ''));

        $departmentId = $this->managerDepartmentId(auth()->user());

        return SubjectOffering::with([
                'section:id,section_code,is_irregular',
                'subject:id,subject_code,descriptive_title',
                'program:id,code,department_id',
                'academicTerm:id,status,class_end_date',
                'teachingAssignment.faculty:id,first_name,middle_name,last_name,suffix,department_id,faculty_scope',
                // Added alongside the fix in SubjectOffering.php:
                // overall_status/room_status now read these two
                // relations in-memory when eager-loaded, instead of
                // firing a raw query per offering. index() below calls
                // ->get()->filter(...) against overall_status whenever
                // a status filter is applied — that used to mean one
                // to three extra queries PER OFFERING on top of the
                // full unpaginated fetch already required to filter on
                // a derived value.
                'schedule',
                'preferredByRooms:id,room_code',
            ])
            ->when($academicTermId, fn ($q) => $q->where('academic_term_id', $academicTermId))
            ->when($departmentId, fn ($q) => $q->whereHas(
                'program',
                fn ($p) => $p->where('department_id', $departmentId)
            ))
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($specializationId, fn ($q) => $q->whereHas(
                'curriculum',
                fn ($c) => $c->where('specialization_id', $specializationId)
            ))
            ->when($yearLevel, fn ($q) => $q->where('year_level', $yearLevel))
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId))
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . strtolower($search) . '%';

                $query->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(edp_code) LIKE ?', [$term])
                        ->orWhereHas('section', fn ($q) => $q->whereRaw('LOWER(section_code) LIKE ?', [$term]))
                        ->orWhereHas('subject', function ($q) use ($term) {
                            $q->whereRaw('LOWER(subject_code) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(descriptive_title) LIKE ?', [$term]);
                        });
                });
            });
    }

    public function index(Request $request)
    {
        $academicTermId = $request->input('academic_term_id')
            ?: $this->workspace->getTermForUser(auth()->user())?->id;

        $programId = $request->input('program_id');
        $specializationId = $request->input('specialization_id');
        $yearLevel = $request->input('year_level');
        $sectionId = $request->input('section_id');
        $status = $request->input('status');
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;

        $departmentId = $this->managerDepartmentId(auth()->user());

        $query = $this->filteredOfferingsQuery($request)
            ->orderBy('year_level')
            ->orderBy('edp_code');

        if (in_array($status, SubjectOffering::STATUSES, true)) {
            $matching = $query->get()->filter(fn ($offering) => $offering->overall_status === $status)->values();

            $offerings = new \Illuminate\Pagination\LengthAwarePaginator(
                $matching->forPage($page, $perPage)->values(),
                $matching->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $offerings = $query->paginate($perPage)->withQueryString();
        }

        // Reused Subjects don't get their own row in subject_offerings
        // for this Section (see storeIrregular()'s IrregularSubjectFulfillment
        // branch) — their EDP Code and section_id still belong to
        // whichever Regular Section originally offered it. Without
        // this, filtering the list by an Irregular Section that only
        // reused existing offerings shows an empty table even though
        // Subjects were successfully attached. Only computed when a
        // single Section is selected, since it's meaningless for the
        // unfiltered/broad view.
        $fulfilledOfferings = [];

        if ($sectionId) {
            $fulfilledOfferings = IrregularSubjectFulfillment::where('section_id', $sectionId)
                ->whereHas('subjectOffering', fn ($q) => $q->when(
                    $academicTermId,
                    fn ($q) => $q->where('academic_term_id', $academicTermId)
                ))
                ->with([
                    'subjectOffering.subject:id,subject_code,descriptive_title',
                    'subjectOffering.program:id,code',
                    'subjectOffering.section:id,section_code',
                ])
                ->get()
                ->map(fn (IrregularSubjectFulfillment $fulfillment) => [
                    'id' => $fulfillment->id,
                    'edp_code' => $fulfillment->subjectOffering?->edp_code,
                    'program' => $fulfillment->subjectOffering?->program,
                    'year_level' => $fulfillment->subjectOffering?->year_level,
                    'subject' => $fulfillment->subjectOffering?->subject,
                    'units' => $fulfillment->subjectOffering?->units,
                    'reused_from_section' => $fulfillment->subjectOffering?->section?->section_code,
                ])
                ->values();
        }

        return Inertia::render('SubjectOfferings/Index', [

            'offerings' => $offerings,

            'fulfilledOfferings' => $fulfilledOfferings,

            'academicTerms' => AcademicTerm::orderByDesc('academic_year')->orderBy('semester')->get(),

            'programs' => Program::where('active', true)
                ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),

            'specializations' => Specialization::where('active', true)
                ->orderBy('name')
                ->get(['id', 'program_id', 'code', 'name']),

            'sections' => Section::with('curriculum:id,program_id,specialization_id')
                ->where('status', 'Active')
                ->orderBy('section_code')
                ->get(['id', 'section_code', 'section_name', 'curriculum_id', 'year_level', 'is_irregular'])
                ->map(fn ($section) => [
                    'id' => $section->id,
                    'section_code' => $section->section_code,
                    'section_name' => $section->section_name,
                    'year_level' => $section->year_level,
                    'is_irregular' => $section->is_irregular,
                    'program_id' => $section->curriculum?->program_id,
                    'specialization_id' => $section->curriculum?->specialization_id,
                ])
                ->values(),

            'statuses' => SubjectOffering::STATUSES,

            // Lightweight lists for the inline Faculty / Preferred Room
            // dropdowns on this page — id + display label only, not the
            // full Faculty/Room resource, since that's all the <select>
            // needs. Unfiltered by department/scope: assignFaculty()
            // below still re-validates eligibility server-side via
            // TeachingAssignmentService, so showing every active faculty
            // here is a UI convenience, not the actual authorization
            // boundary.
            //
            // current_load/effective_max_units are ADDITIONALLY exposed
            // (not just scope/department) purely so the dropdown can
            // grey out and label an already-full faculty member before
            // the user picks them — assertWithinMaxUnits() on the
            // server remains the actual enforcement, this is only a UI
            // hint computed the same way (sum of units across active
            // Teaching Assignments in THIS academic term).
            'faculties' => (function () use ($academicTermId) {
                $currentLoads = TeachingAssignment::query()
                    ->join('subject_offerings', 'teaching_assignments.subject_offering_id', '=', 'subject_offerings.id')
                    ->join('subjects', 'subject_offerings.subject_id', '=', 'subjects.id')
                    ->where('teaching_assignments.active', true)
                    ->where('subject_offerings.academic_term_id', $academicTermId)
                    ->groupBy('teaching_assignments.faculty_id')
                    ->select('teaching_assignments.faculty_id', DB::raw('SUM(subjects.units) as total_units'))
                    ->pluck('total_units', 'faculty_id');

                return Faculty::where('status', true)
                    ->with('loadOverloads')
                    ->orderBy('last_name')
                    ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'department_id', 'faculty_scope', 'max_units'])
                    ->map(function (Faculty $f) use ($currentLoads) {
                        $currentLoad = (int) ($currentLoads[$f->id] ?? 0);
                        $cap = $f->effective_max_units;

                        return [
                            'id' => $f->id,
                            'full_name' => $f->full_name,
                            'department_id' => $f->department_id,
                            'faculty_scope' => $f->faculty_scope,
                            'current_load' => $currentLoad,
                            'effective_max_units' => $cap,
                            'remaining_units' => max(0, $cap - $currentLoad),
                        ];
                    })
                    ->values();
            })(),

            // room_group_codes (e.g. ['General'], ['BSIT'], ['BSCRIM'])
            // is what actually governs which rooms a given offering may
            // use — mirrors Room::scopeAvailableFor(): a room is
            // eligible if it's General OR specifically assigned to the
            // offering's program. Eager-load roomGroups so the
            // room_group_codes accessor doesn't fire one query per room.
            'rooms' => Room::with('roomGroups')
                ->orderBy('room_code')
                ->get(['id', 'room_code', 'room_type'])
                ->map(fn (Room $r) => [
                    'id' => $r->id,
                    'room_code' => $r->room_code,
                    'room_type' => $r->room_type,
                    'room_group_codes' => $r->room_group_codes,
                ])
                ->values(),

            'filters' => [
                'academic_term_id' => $academicTermId ? (int) $academicTermId : null,
                'program_id' => $programId ? (int) $programId : null,
                'specialization_id' => $specializationId ? (int) $specializationId : null,
                'year_level' => $yearLevel ? (int) $yearLevel : null,
                'section_id' => $sectionId ? (int) $sectionId : null,
                'status' => in_array($status, SubjectOffering::STATUSES, true) ? $status : null,
                'search' => $search,
            ],

            'can' => [
                // All five roles can now perform every action below —
                // Admin/Registrar/Assistant Dean act on every
                // department; a Dean/OIC only ever sees their own
                // department's offerings in the first place (see the
                // whereHas('program', ...) scope in
                // filteredOfferingsQuery() above), and every mutating
                // action (destroy/assignFaculty/setPreferredRoom/
                // bulkUpdateWeeklyHours) re-checks canManageOffering()
                // server-side regardless of what this flag says.
                'delete' => auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
                'bulkUpdateWeeklyHours' => auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
                'assignFaculty' => auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
                'setPreferredRoom' => auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
            ],

        ]);
    }

    /**
     * Printable Class List — a partial-list handout for posting
     * before enrollment: "which Subjects is BSIT 1-A taking this
     * term," grouped by Section, with no Faculty/Room/Time/Status
     * columns since none of that exists yet at this stage.
     *
     * Reuses index()'s exact filters (minus `status`, which doesn't
     * apply here) so the printed list always matches whatever the
     * Registrar/Dean currently has on screen. Deliberately NOT
     * paginated — a posted class list needs every matching row, not
     * page 1 of 20.
     *
     * Returns a plain Blade view (not Inertia) so it opens cleanly in
     * its own tab and the browser's native "Print > Save as PDF"
     * works without any extra PDF library.
     */
    public function print(Request $request)
    {
        $academicTermId = $request->input('academic_term_id')
            ?: $this->workspace->getTermForUser(auth()->user())?->id;

        $academicTerm = $academicTermId ? AcademicTerm::find($academicTermId) : null;

        $offerings = $this->filteredOfferingsQuery($request)
            ->orderBy('year_level')
            ->get();

        // Group by Section so the printout reads "BSIT 1-A" as a
        // header with its Subjects listed underneath, rather than one
        // long flat table repeating the Section on every row.
        $sections = $offerings
            ->groupBy(fn ($offering) => $offering->section_id)
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'section_code' => $first->section?->section_code ?? 'Unassigned Section',
                    'year_level' => $first->year_level,
                    'program_code' => $first->program?->code,
                    'offerings' => $group->sortBy(fn ($o) => $o->subject?->subject_code)->values(),
                ];
            })
            ->sortBy(['year_level', 'section_code'])
            ->values();

        return view('subject-offerings.print', [
            'academicTerm' => $academicTerm,
            'sections' => $sections,
            'generatedAt' => now(),
        ]);
    }

    /**
     * Notifies the curriculum's own college — Admin, Registrar,
     * Assistant Dean, and that college's Dean/OIC, minus whoever
     * performed the generation (see resolveStakeholders() below) —
     * that new Subject Offerings just landed for them. Skipped
     * entirely if the curriculum's program has no department_id
     * (there's no single Dean/OIC to address it to) or if there's no
     * one to notify after excluding the actor.
     */
    private function notifyDepartmentOfGeneration(
        Curriculum $curriculum,
        AcademicTerm $academicTerm,
        User $performedBy,
        int $createdCount
    ): void {
        $departmentId = $curriculum->program?->department_id;

        if (! $departmentId) {
            return;
        }

        $department = Department::find($departmentId);

        if (! $department) {
            return;
        }

        $recipients = $this->resolveStakeholders($department, $performedBy);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new SubjectOfferingsGenerated(
            $department,
            $academicTerm,
            $curriculum,
            $performedBy,
            $createdCount
        ));
    }

    /**
     * Admin + Registrar + Assistant Dean (global tiers) merged with
     * one college's Dean/OIC (department-scoped tier), deduplicated,
     * with whoever performed the action removed from the list. Same
     * recipient rule as TermFinalizationService::resolveStakeholders()
     * and MasterGridController::resolveStakeholders() — duplicated
     * here rather than shared, since none of these three currently
     * have one common owning service for cross-module notification
     * recipients. If this rule needs to change, update all three
     * places.
     */
    private function resolveStakeholders(Department $department, User $performedBy): \Illuminate\Support\Collection
    {
        $global = User::role(['Admin', 'Registrar', 'Assistant Dean'])->get();

        $departmentScoped = User::role(['Dean', 'OIC'])
            ->where('department_id', $department->id)
            ->get();

        return $global->merge($departmentScoped)
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $performedBy->id)
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Irregular Sections — manual Subject picker
    |--------------------------------------------------------------------------
    |
    | Irregular Sections (Section::is_irregular) never go through
    | SubjectOfferingGeneratorService::generate() — that flow assumes
    | every Section in a batch takes exactly the Curriculum Items that
    | match its own year_level, which is precisely the assumption an
    | Irregular Section breaks (its students are taking a mix of
    | Subjects from different year levels/curricula). These two actions
    | are the Irregular equivalent: irregularSubjects() lists what can
    | still be picked, storeIrregular() creates one Subject Offering
    | per picked Curriculum Item — each one going through
    | EdpCodeService::generate() for its EDP Code, same as every other
    | Subject Offering in the system.
    */

    /**
     * Constrains a Curriculum query to "the same track as this
     * Section" — same Program AND same Specialization (both sides
     * null-safe, so Programs with no Specialization at all still match
     * correctly instead of every specialization_id IS NULL row being
     * excluded by a naive ->where('specialization_id', null) chain).
     *
     * Shared by irregularSubjects() (the picker's list) and
     * storeIrregular() (its server-side re-validation) so the two can
     * never drift apart — what the picker shows is exactly what
     * storeIrregular() will accept.
     */
    private function scopeToSectionTrack($query, Section $section)
    {
        $specializationId = $section->curriculum->specialization_id;

        return $query->where('program_id', $section->curriculum->program_id)
            ->where('active', true)
            ->when(
                $specializationId,
                fn ($q) => $q->where('specialization_id', $specializationId),
                fn ($q) => $q->whereNull('specialization_id')
            );
    }

    /**
     * For a set of Subject IDs, find any Subject Offering already
     * generated for a REGULAR Section (is_irregular = false) in the
     * given Academic Term — one per Subject ID at most, so an
     * Irregular Section's need for that Subject can be fulfilled by
     * re-using that Regular Section's existing EDP Code instead of
     * minting a new one (see storeIrregular() below).
     *
     * Not scoped to the Irregular Section's own Program/Specialization
     * track — a retaken Subject is the same real class regardless of
     * which Program's curriculum originally listed it (this mirrors
     * the capstone's own example: a 4th Year BSIT student retaking a
     * 3rd Year BSIT Subject reuses that Regular Section's EDP Code
     * without any Program mismatch to worry about; the Subject ID
     * itself is the shared identity).
     *
     * Returns a [subject_id => SubjectOffering] map. When a Subject
     * happens to be offered by more than one Regular Section this
     * Term, the lowest Section ID wins — arbitrary but stable, so the
     * picker and storeIrregular() never disagree with each other.
     *
     * @param  array<int>  $subjectIds
     * @return array<int, SubjectOffering>
     */
    private function existingRegularOfferingsFor(array $subjectIds, AcademicTerm $academicTerm): array
    {
        if (empty($subjectIds)) {
            return [];
        }

        return SubjectOffering::where('academic_term_id', $academicTerm->id)
            ->whereIn('subject_id', $subjectIds)
            ->whereHas('section', fn ($query) => $query->where('is_irregular', false))
            ->with('section:id,section_code')
            ->orderBy('section_id')
            ->get()
            ->keyBy('subject_id')
            ->all();
    }

    /**
     * Subjects available to hand-pick for an Irregular Section — every
     * active Subject-type Curriculum Item, across every active
     * Curriculum that matches the Section's own Program AND
     * Specialization (any Year Level), for the current Working
     * Academic Term's semester. Excludes anything already offered to
     * this Section in that Term so the picker never re-offers a class
     * that's already been generated.
     *
     * Scoping to Specialization (not just Program) matters for
     * Programs like BSCRIM: without it, a BSIT.../BSCRIM-Fingerprint
     * Section's picker would also surface every other BSCRIM
     * specialization's own copy of shared Subjects (e.g. General
     * Education items every specialization's Curriculum repeats), each
     * looking like a duplicate of the same Subject Code. Programs with
     * no Specialization at all (specialization_id is null on both
     * sides) match normally via the null-safe comparison below.
     *
     * Returned as JSON (not an Inertia page) — this is fetched
     * on-demand from the Section Edit form when the Irregular Section
     * toggle is on, not a full page navigation.
     */
    public function irregularSubjects(Section $section): JsonResponse
    {
        abort_unless(auth()->user()->can('generate', SubjectOffering::class), 403, 'Unauthorized.');
        abort_unless($section->is_irregular, 422, 'This Section is not marked Irregular.');

        $section->loadMissing('curriculum.program', 'curriculum.specialization');

        $academicTerm = $this->workspace->getTermForUser(auth()->user());

        if (! $academicTerm) {
            return response()->json([
                'academic_term' => null,
                'subjects' => [],
                'error' => 'No Working Academic Term is set yet.',
            ]);
        }

        $ownOfferings = SubjectOffering::where('academic_term_id', $academicTerm->id)
            ->where('section_id', $section->id)
            ->pluck('subject_id');

        // Subjects whose need has already been fulfilled by an
        // existing Regular Section's Subject Offering (see
        // storeIrregular()) — these also shouldn't be re-offered in
        // the picker below.
        $fulfillments = IrregularSubjectFulfillment::where('section_id', $section->id)
            ->with(['subjectOffering.subject', 'subjectOffering.section'])
            ->get();

        $alreadyOfferedSubjectIds = $ownOfferings
            ->merge($fulfillments->pluck('subjectOffering.subject_id'))
            ->unique();

        $attached = SubjectOffering::where('academic_term_id', $academicTerm->id)
            ->where('section_id', $section->id)
            ->with('subject')
            ->orderBy('edp_code')
            ->get()
            ->map(fn (SubjectOffering $offering) => [
                'id' => $offering->id,
                'edp_code' => $offering->edp_code,
                'subject_code' => $offering->subject?->subject_code,
                'descriptive_title' => $offering->subject?->descriptive_title,
                'year_level' => $offering->year_level,
            ])
            ->values();

        // Subjects covered via reuse rather than a Subject Offering of
        // this Section's own — same shape as $attached, plus which
        // Regular Section's EDP Code is actually being shared.
        $fulfilled = $fulfillments
            ->map(fn (IrregularSubjectFulfillment $fulfillment) => [
                'id' => $fulfillment->id,
                'edp_code' => $fulfillment->subjectOffering?->edp_code,
                'subject_code' => $fulfillment->subjectOffering?->subject?->subject_code,
                'descriptive_title' => $fulfillment->subjectOffering?->subject?->descriptive_title,
                'year_level' => $fulfillment->subjectOffering?->year_level,
                'fulfilled_by_section' => $fulfillment->subjectOffering?->section?->section_code,
            ])
            ->values();

        $items = CurriculumItem::query()
            ->subjects()
            ->where('active', true)
            ->where('semester', $academicTerm->semester)
            ->whereNotIn('subject_id', $alreadyOfferedSubjectIds)
            ->whereHas('curriculum', fn ($query) => $this->scopeToSectionTrack($query, $section))
            ->whereHas('subject', function ($query) {
                $query->where('active', true);
            })
            ->with(['subject', 'curriculum.specialization'])
            ->get();

        $existingRegularOfferings = $this->existingRegularOfferingsFor(
            $items->pluck('subject_id')->unique()->all(),
            $academicTerm
        );

        $items = $items
            ->map(function ($item) use ($existingRegularOfferings) {
                $existing = $existingRegularOfferings[$item->subject_id] ?? null;

                return [
                    'curriculum_item_id' => $item->id,
                    'subject_id' => $item->subject_id,
                    'subject_code' => $item->subject?->subject_code,
                    'descriptive_title' => $item->subject?->descriptive_title,
                    'units' => $item->subject?->units,
                    'year_level' => $item->year_level,
                    'curriculum' => $item->curriculum?->display_name,
                    // When set, submitting this Curriculum Item will
                    // (by default) NOT mint a new EDP Code — it will
                    // reuse this existing Regular Section's Subject
                    // Offering instead. See storeIrregular().
                    'existing_offering' => $existing ? [
                        'edp_code' => $existing->edp_code,
                        'section_code' => $existing->section?->section_code,
                    ] : null,
                ];
            })
            ->sortBy(['subject_code', 'year_level'])
            ->values();

        return response()->json([
            'academic_term' => $academicTerm->display_name,
            'subjects' => $items,
            'attached' => $attached,
            'fulfilled' => $fulfilled,
        ]);
    }

    /**
     * Attach a hand-picked set of Subjects to an Irregular Section.
     *
     * For each picked Curriculum Item, one of two things happens:
     *
     *   - A Regular Section already has a Subject Offering for that
     *     same Subject this Term, and the caller did NOT force a new
     *     one -> no new Subject Offering/EDP Code is created. Instead
     *     an IrregularSubjectFulfillment row records that this
     *     Irregular Section's need is covered by that existing
     *     Regular Section's EDP Code (see existingRegularOfferingsFor()
     *     and the migration's docblock for why a second row can't
     *     just share the same edp_code string).
     *
     *   - No Regular Section covers it yet, OR the caller explicitly
     *     asked to open an additional/open Section for it anyway
     *     (force_new_curriculum_item_ids) -> a new Subject Offering is
     *     generated through EdpCodeService::generate(), exactly as
     *     before.
     *
     * Additive only, same as SubjectOfferingGeneratorService::generate():
     * re-submitting a Curriculum Item that's already been offered OR
     * already fulfilled for this Section in this Term is silently
     * skipped rather than duplicated.
     */
    public function storeIrregular(Request $request, Section $section)
    {
        abort_unless(auth()->user()->can('generate', SubjectOffering::class), 403, 'Unauthorized.');
        abort_unless($section->is_irregular, 422, 'This Section is not marked Irregular.');

        $validated = $request->validate([
            'curriculum_item_ids' => ['required', 'array', 'min:1'],
            'curriculum_item_ids.*' => ['integer', 'exists:curriculum_items,id'],
            // Subset of curriculum_item_ids the caller wants to open a
            // brand-new/additional Section for, bypassing reuse even
            // when a Regular Section already covers that Subject —
            // the "department explicitly requests an Open/Irregular
            // Section" branch of the workflow.
            'force_new_curriculum_item_ids' => ['sometimes', 'array'],
            'force_new_curriculum_item_ids.*' => ['integer'],
        ]);

        $forceNewIds = collect($validated['force_new_curriculum_item_ids'] ?? [])->flip();

        $section->loadMissing('curriculum.program', 'curriculum.specialization');

        $academicTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($academicTerm, 422, 'No Working Academic Term is set.');

        $this->workspace->assertWritable($academicTerm);

        // Re-scoped by Program + Specialization here too, not just
        // "does this ID exist" (the validation rule above) — a request
        // could otherwise smuggle in a Curriculum Item ID that belongs
        // to a completely different Program/Specialization than this
        // Section. Silently ignoring any such IDs (rather than
        // erroring) keeps this consistent with the picker's own list,
        // which never shows them in the first place.
        $items = CurriculumItem::query()
            ->subjects()
            ->whereIn('id', $validated['curriculum_item_ids'])
            ->whereHas('curriculum', fn ($query) => $this->scopeToSectionTrack($query, $section))
            ->with(['subject', 'curriculum.program', 'curriculum.specialization'])
            ->get();

        $existingRegularOfferings = $this->existingRegularOfferingsFor(
            $items->pluck('subject_id')->unique()->all(),
            $academicTerm
        );

        $created = 0;
        $reused = 0;
        $skippedExisting = 0;
        $createdCodes = [];
        $reusedCodes = [];

        DB::transaction(function () use (
            $items,
            $section,
            $academicTerm,
            $existingRegularOfferings,
            $forceNewIds,
            &$created,
            &$reused,
            &$skippedExisting,
            &$createdCodes,
            &$reusedCodes
        ) {
            foreach ($items as $item) {

                if (! $item->subject) {
                    continue;
                }

                $alreadyOffered = SubjectOffering::where('academic_term_id', $academicTerm->id)
                    ->where('section_id', $section->id)
                    ->where('subject_id', $item->subject_id)
                    ->exists();

                $alreadyFulfilled = IrregularSubjectFulfillment::where('section_id', $section->id)
                    ->whereHas('subjectOffering', fn ($q) => $q->where('subject_id', $item->subject_id))
                    ->exists();

                if ($alreadyOffered || $alreadyFulfilled) {
                    $skippedExisting++;
                    continue;
                }

                $existingOffering = $existingRegularOfferings[$item->subject_id] ?? null;
                $forceNew = $forceNewIds->has($item->id);

                if ($existingOffering && ! $forceNew) {
                    IrregularSubjectFulfillment::create([
                        'section_id' => $section->id,
                        'subject_offering_id' => $existingOffering->id,
                        'curriculum_item_id' => $item->id,
                        'created_by' => auth()->id(),
                    ]);

                    $reused++;
                    $reusedCodes[] = $existingOffering->edp_code;

                    continue;
                }

                $edpCode = EdpCodeService::generate(
                    $section,
                    $item->subject,
                    $item,
                    $academicTerm
                );

                SubjectOffering::create([
                    'academic_term_id' => $academicTerm->id,
                    'curriculum_id' => $item->curriculum_id,
                    'curriculum_item_id' => $item->id,
                    'program_id' => $item->curriculum->program_id,
                    'subject_id' => $item->subject_id,
                    'section_id' => $section->id,
                    'year_level' => $item->year_level,
                    'semester' => $item->semester,
                    'units' => $item->subject->units,
                    'hours' => $item->subject->total_hours,
                    'classification' => $item->subject->is_major
                        ? SubjectOffering::CLASSIFICATION_MAJOR
                        : SubjectOffering::CLASSIFICATION_MINOR,
                    'room_type' => $item->subject->required_room_type,
                    'edp_code' => $edpCode,
                    'status' => SubjectOffering::STATUS_GENERATED,
                    'created_by' => auth()->id(),
                ]);

                $created++;
                $createdCodes[] = $edpCode;
            }
        });

        if ($created === 0 && $reused === 0) {
            return redirect()
                ->route('sections.edit', $section)
                ->with('warning', "No new Subjects were attached — {$skippedExisting} Subject(s) were already offered or fulfilled for {$section->section_code} this Term.");
        }

        $messageParts = [];

        if ($created > 0) {
            $messageParts[] = "{$created} new Subject Offering(s) generated";
        }

        if ($reused > 0) {
            $messageParts[] = "{$reused} Subject(s) covered by reusing an existing Regular Section's EDP Code (no new EDP Code created)";
        }

        $message = implode('; ', $messageParts) . " for {$section->section_code}.";

        if ($skippedExisting > 0) {
            $message .= " {$skippedExisting} already existed and were left untouched.";
        }

        AuditLogService::log(
            action: 'generated',
            module: 'Subject Offering',
            model: $section,
            description: "Manually attached Subjects to Irregular Section {$section->section_code} ({$created} new, {$reused} reused)",
            newValues: [
                'section_code' => $section->section_code,
                'academic_term' => $academicTerm->display_name,
                'edp_codes' => $createdCodes,
                'reused_edp_codes' => $reusedCodes,
            ],
            recordName: $section->section_code,
        );

        if ($created > 0) {
            $this->notifyDepartmentOfGeneration($section->curriculum, $academicTerm, auth()->user(), $created);
        }

        return redirect()
            ->route('sections.edit', $section)
            ->with('success', $message);
    }

    public function destroy(SubjectOffering $subjectOffering)
    {
        abort_unless(
            auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
            403,
            'You do not have permission to delete Subject Offerings.'
        );

        $this->assertManagesOffering($subjectOffering);

        $this->workspace->assertWritable($subjectOffering->academicTerm);

        if ($subjectOffering->teachingAssignment()->exists()) {
            return back()->with('error', "{$subjectOffering->edp_code} already has a Faculty assignment and cannot be deleted.");
        }

        $edpCode = $subjectOffering->edp_code;
        $subjectOffering->delete();

        AuditLogService::log(
            action: 'deleted',
            module: 'Subject Offering',
            description: "Deleted subject offering {$edpCode}",
            oldValues: ['edp_code' => $edpCode],
            recordName: $edpCode,
        );

        return back()->with('success', "{$edpCode} deleted.");
    }

    /**
     * Bulk Update Weekly Hours — lets a Registrar/Admin override the
     * per-Term weekly hours for a hand-picked set of Subject
     * Offerings (e.g. "schedule Programming 1 at 4 hrs/week instead
     * of the curriculum's 5 for this Section, this Term only"),
     * without ever touching the Subject master, the Curriculum, or
     * the Prospectus — see SubjectOffering::getHoursAttribute() for
     * the read side that makes this override visible to Session
     * Settings / the Greedy Scheduler / the Master Grid.
     *
     * A JSON endpoint (not an Inertia redirect) on purpose, same
     * convention as MasterGridController's axios-driven actions —
     * the Index page reloads just the `offerings` prop afterward so
     * filters/sorting/pagination are preserved instead of a full
     * navigation resetting them.
     */
    public function bulkUpdateWeeklyHours(BulkUpdateWeeklyHoursRequest $request): JsonResponse
    {
        abort_unless(
            auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
            403,
            'Only Admin, Registrar, Dean, Assistant Dean, and OIC can perform Bulk Update Weekly Hours.'
        );

        $validated = $request->validated();

        $offerings = SubjectOffering::with([
                'academicTerm',
                'subject:id,subject_code,descriptive_title',
                'section:id,section_code',
                'program:id,code,department_id',
            ])
            ->whereIn('id', $validated['subject_offering_ids'])
            ->get();

        abort_if($offerings->isEmpty(), 404, 'No matching Subject Offerings were found.');

        // A scoped Dean/OIC's selection can never span outside their
        // own department — the Index page's query already keeps other
        // departments' rows off their screen entirely, so reaching
        // this would mean a stale selection or a direct API call, not
        // a normal click-through.
        $offerings->each(fn (SubjectOffering $offering) => $this->assertManagesOffering($offering));

        // A mixed selection spanning more than one Academic Term must
        // never partially apply — assertWritable() throws on the
        // first Archived/locked term it finds, before any row is
        // touched, same guard store()/destroy() already use above.
        $offerings->pluck('academicTerm')->filter()->unique('id')->each(
            fn (AcademicTerm $term) => $this->workspace->assertWritable($term)
        );

        $newHours = (int) $validated['hours'];

        $changes = [];

        DB::transaction(function () use ($offerings, $newHours, &$changes) {
            foreach ($offerings as $offering) {
                $changes[] = [
                    'edp_code' => $offering->edp_code,
                    // getRawOriginal bypasses the fallback accessor
                    // (SubjectOffering::getHoursAttribute) so the log
                    // always reflects this row's actual previous
                    // value, not a value borrowed from the Subject
                    // master.
                    'from' => $offering->getRawOriginal('hours'),
                    'to' => $newHours,
                ];

                // ONLY the `hours` column on THIS row. subject_id,
                // curriculum_id, curriculum_item_id, and every other
                // field are left untouched — this is the one write
                // path for the feature, and it never reaches the
                // Subject master, Curriculum, or Prospectus.
                $offering->update(['hours' => $newHours]);
            }
        });

        $term = $offerings->first()->academicTerm;

        $fromValues = collect($changes)->pluck('from')->unique()->filter(fn ($v) => $v !== null)->values();
        $fromLabel = $fromValues->isEmpty()
            ? '—'
            : ($fromValues->count() === 1 ? (string) $fromValues->first() : $fromValues->join(', '));

        $label = $term?->display_name ?? 'Multiple Terms';

        AuditLogService::log(
            action: 'updated',
            module: 'Subject Offering',
            model: $term,
            description: "Bulk updated Weekly Hours for {$offerings->count()} Subject Offering(s)",
            oldValues: ['weekly_hours' => $fromLabel],
            newValues: [
                'weekly_hours' => $newHours,
                'affected_subject_offerings' => $offerings->count(),
                'edp_codes' => $offerings->pluck('edp_code')->values(),
            ],
            recordName: $label,
        );

        // Activity History milestone. NOTE: ActivityHistoryService
        // needs a small addition to support this — see
        // recordBulkWeeklyHoursUpdated() below, mirroring the shape
        // of the existing recordSubjectOfferingsGenerated() /
        // recordScheduleManuallyAdjusted() wrapper methods.
        if ($term) {
            ActivityHistoryService::recordBulkWeeklyHoursUpdated(
                $term,
                $offerings->count(),
                [
                    'program' => $offerings->first()->program?->code,
                    'year_level' => $offerings->first()->year_level,
                    'section' => $offerings->first()->section?->section_code,
                    'weekly_hours' => "{$fromLabel} → {$newHours}",
                ]
            );
        }

        return response()->json([
            'message' => "{$offerings->count()} Subject Offering(s) updated to {$newHours} weekly hours.",
            'updated_count' => $offerings->count(),
        ]);
    }

    /**
     * Inline Faculty assignment from the Subject Offerings table —
     * same underlying record (teaching_assignments) and same business
     * rules as the Faculty Loading page's "Assign Subject" modal
     * (TeachingAssignmentService::assertBusinessRules), just reachable
     * without leaving this page. Unlike TeachingAssignmentController::
     * store(), this ALSO handles reassignment: if the offering already
     * has a Teaching Assignment, the old one is deleted first so
     * picking a different faculty from the dropdown doesn't 422 on the
     * subject_offering_id unique constraint. Passing a null faculty_id
     * clears the assignment entirely (same effect as the Faculty
     * Loading page's "Remove" action).
     *
     * JSON endpoint, same convention as bulkUpdateWeeklyHours() above
     * — the page reloads just the `offerings` prop afterward so
     * filters/pagination survive.
     */
    public function assignFaculty(Request $request, SubjectOffering $subjectOffering): JsonResponse
    {
        abort_unless(
            auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
            403,
            'Only Admin, Registrar, Dean, Assistant Dean, and OIC can assign Faculty here.'
        );

        $this->assertManagesOffering($subjectOffering);

        $validated = $request->validate([
            'faculty_id' => ['nullable', 'exists:faculties,id'],
        ]);

        $this->workspace->assertWritable($subjectOffering->academicTerm);

        $subjectOffering->loadMissing('section');

        $existing = $subjectOffering->teachingAssignment;

        // Clearing — same effect as the destroy() action on the
        // Faculty Loading page, just triggered from here instead.
        if (! $validated['faculty_id']) {
            if ($existing) {
                $faculty = $existing->faculty;
                $existing->delete();

                AuditLogService::log(
                    action: 'unassigned',
                    module: 'Faculty Loading',
                    model: $faculty,
                    description: $faculty
                        ? "Removed {$faculty->full_name} from {$subjectOffering->section?->section_code} {$subjectOffering->edp_code}"
                        : 'Removed a faculty load assignment',
                    oldValues: ['faculty' => $faculty?->full_name, 'subject_offering' => $subjectOffering->edp_code],
                    recordName: "{$subjectOffering->section?->section_code} {$subjectOffering->edp_code}",
                );
            }

            return response()->json(['message' => "Faculty cleared for {$subjectOffering->edp_code}."]);
        }

        $payload = [
            'subject_offering_id' => $subjectOffering->id,
            'faculty_id' => $validated['faculty_id'],
        ];

        // assertBusinessRules() re-checks scope/department/active-term/
        // max-units exactly like the Faculty Loading modal does — the
        // dropdown here shows every active faculty (see index() above)
        // precisely because this check, not the dropdown's contents,
        // is the real authorization boundary.
        try {
            $this->teachingAssignmentService->assertBusinessRules($payload, $existing);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        // Delete any existing assignment first — teaching_assignments
        // has a unique constraint on subject_offering_id, so a plain
        // create() here would 422 on reassignment instead of swapping
        // the faculty.
        $existing?->delete();

        TeachingAssignment::create($payload);

        $faculty = Faculty::find($validated['faculty_id']);

        AuditLogService::log(
            action: 'assigned',
            module: 'Faculty Loading',
            model: $faculty,
            description: "Assigned {$faculty?->full_name} to {$subjectOffering->section?->section_code} {$subjectOffering->edp_code}",
            newValues: [
                'faculty' => $faculty?->full_name,
                'subject_offering' => $subjectOffering->edp_code,
                'section' => $subjectOffering->section?->section_code,
            ],
            recordName: "{$subjectOffering->section?->section_code} {$subjectOffering->edp_code}",
        );

        return response()->json(['message' => "{$faculty?->full_name} assigned to {$subjectOffering->edp_code}."]);
    }

    /**
     * Inline Preferred Room from the Subject Offerings table — writes
     * to the same room_subject_offering pivot as the Rooms "Manage
     * Subjects" page (Room::syncPreferredSubjects()), just from the
     * other direction: one Offering picking its one preferred Room,
     * instead of one Room picking many Offerings. This is deliberately
     * a PREFERENCE, not a real Room assignment — the actual day/time/
     * room commitment only ever happens in Master Grid, where the
     * Greedy Scheduler checks real conflicts. Passing a null room_id
     * clears the preference.
     *
     * Enforces the same RoomCapacityService weekly-hours check
     * syncPreferredSubjects() does, evaluated against the room's
     * resulting full preferred set for the term (see below) — a
     * single-offering update reaching the same cap Manage Subjects
     * already enforces from the other direction.
     */
    public function setPreferredRoom(Request $request, SubjectOffering $subjectOffering): JsonResponse
    {
        abort_unless(
            auth()->user()->hasAnyRole(['Admin', 'Registrar', 'Dean', 'Assistant Dean', 'OIC']),
            403,
            'Only Admin, Registrar, Dean, Assistant Dean, and OIC can set a Preferred Room here.'
        );

        $this->assertManagesOffering($subjectOffering);

        $validated = $request->validate([
            'room_id' => ['nullable', 'exists:rooms,id'],
        ]);

        $this->workspace->assertWritable($subjectOffering->academicTerm);

        // Same compatibility rule as Room::manageSubjects() /
        // syncPreferredSubjects() — a Lecture offering can only prefer
        // a Lecture room, a Laboratory offering only a Laboratory room.
        // Re-checked here (not just filtered out of the dropdown) so a
        // direct API call can't smuggle in a mismatched room.
        //
        // NOTE: unlike syncPreferredSubjects(), this does NOT run
        // RoomCapacityService's weekly-hours capacity check — that
        // service evaluates a room's FULL preferred set at once, which
        // doesn't fit a single-offering inline update. If capacity
        // needs to be enforced here too, wire in RoomCapacityService
        // the same way Room::syncPreferredSubjects() does.
        if ($validated['room_id']) {
            $room = Room::findOrFail($validated['room_id']);

            if ($room->room_type !== $subjectOffering->room_type) {
                return response()->json([
                    'message' => "{$room->room_code} is a {$room->room_type} room — {$subjectOffering->edp_code} requires {$subjectOffering->room_type}.",
                ], 422);
            }

            // Same weekly-capacity rule Room::syncPreferredSubjects()
            // enforces from the other direction — checked against the
            // room's resulting FULL preferred set for this term (every
            // offering it already prefers, plus this one), not just
            // this single offering, since RoomCapacityService evaluates
            // total weekly hours across the whole set.
            $resultingIds = $room->preferredSubjectOfferings()
                ->where('academic_term_id', $subjectOffering->academic_term_id)
                ->pluck('subject_offerings.id')
                ->push($subjectOffering->id)
                ->unique()
                ->values();

            $capacityCheck = $this->capacity->checkCapacity($room, $subjectOffering->academicTerm, $resultingIds);

            if ($capacityCheck['exceeds']) {
                return response()->json(['message' => $capacityCheck['message']], 422);
            }
        }

        // sync() with a single ID (or an empty array to clear) — an
        // Offering has at most one preferred Room, same "single
        // preference" shape getPreferredRoomAttribute() already
        // assumes when it reads ->first() off this same pivot.
        $subjectOffering->preferredByRooms()->sync(
            $validated['room_id'] ? [$validated['room_id']] : []
        );

        $room = $validated['room_id'] ? Room::find($validated['room_id']) : null;

        AuditLogService::log(
            action: 'updated',
            module: 'Subject Offering',
            model: $subjectOffering,
            description: $room
                ? "Set Preferred Room {$room->room_code} for {$subjectOffering->edp_code}"
                : "Cleared Preferred Room for {$subjectOffering->edp_code}",
            newValues: ['preferred_room' => $room?->room_code],
            recordName: $subjectOffering->edp_code,
        );

        return response()->json([
            'message' => $room
                ? "Preferred Room set to {$room->room_code} for {$subjectOffering->edp_code}."
                : "Preferred Room cleared for {$subjectOffering->edp_code}.",
        ]);
    }
}