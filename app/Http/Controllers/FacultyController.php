<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Department;
use App\Models\AcademicTerm;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Services\AuditLogService;

class FacultyController extends Controller implements HasMiddleware
{

    /**
     * Controller Middleware
     *
     * Three tiers, unlike Subjects' two — Faculty is department-owned
     * data (each row belongs to one Department, or none for General
     * Education), so Edit gets its own tier with a per-row department
     * check inside edit()/update() rather than being lumped in with
     * Create/Delete:
     *
     *   - Read (index)             -> Admin, Registrar, Dean,
     *     Assistant Dean, OIC — everyone can see the full roster
     *     (needed for Faculty Loading elsewhere), even departments
     *     they can't edit.
     *   - Add/Delete (create/
     *     store/destroy)           -> Admin, Registrar only. Adding is
     *     effectively an HR/employee-record action, and deleting
     *     cascades into faculty_subjects/teaching assignments/Schedule
     *     — both stay centralized, same reasoning as Subjects.
     *   - Edit (edit/update)       -> Admin, Registrar, Dean, Assistant
     *     Dean, OIC pass this ROLE check, but canEditFaculty() below
     *     additionally restricts Dean/Assistant Dean/OIC to their own
     *     Department (or a General Education faculty member with no
     *     department at all) — a CCS Dean can edit COC's or CTE's
     *     faculty, only their own.
     */
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

            }, only: ['index']),

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

            }, only: ['create', 'store', 'destroy', 'deletePreview']),

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

            }, only: ['edit', 'update']),

        ];
    }

    /**
     * Whether $user is allowed to edit $faculty:
     *
     *   - Admin/Registrar can always edit anyone.
     *   - Dean/Assistant Dean/OIC can edit a General Education faculty
     *     member (department_id is null — not tied to any college),
     *     OR a faculty member in their OWN department. They can never
     *     edit another department's faculty (a CCS Dean editing a CTE
     *     faculty member, for instance).
     *
     * NOTE: this assumes the authenticated User has a department_id
     * (mirroring the same field on Faculty) identifying which
     * Department they're the Dean/Assistant Dean/OIC of. If your User
     * model exposes this differently (e.g. $user->department->id, or a
     * separate departmentsManaged() relation for someone overseeing
     * more than one department), swap the comparison below accordingly
     * — the role/GenEd logic stays the same either way.
     */
    private function canEditFaculty($user, Faculty $faculty): bool
    {
        if ($user->hasAnyRole(['Admin', 'Registrar'])) {
            return true;
        }

        if (! $user->hasAnyRole(['Dean', 'Assistant Dean', 'OIC'])) {
            return false;
        }

        if (is_null($faculty->department_id)) {
            return true;
        }

        return $faculty->department_id === $user->department_id;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        $faculties = Faculty::with('department')

            // Powers the destroy() double-confirmation prompt — see
            // that method and Faculty::schedules().
            ->withExists(['schedules as has_schedule'])

            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()

            // can_edit is computed here (not left to the frontend) so
            // the SAME rule in canEditFaculty() governs both what the
            // Edit button shows for and what update() actually allows
            // — the frontend check is only ever a convenience, never
            // the enforcement.
            ->map(function (Faculty $faculty) use ($user) {
                $faculty->can_edit = $this->canEditFaculty($user, $faculty);

                return $faculty;
            });

        return Inertia::render('Faculty/Index', [

            'faculties' => $faculties,

            // Add/Delete are Admin/Registrar only — see middleware().
            // Sent once for the page rather than per-row since it
            // doesn't vary faculty-to-faculty the way can_edit does.
            'canManageFaculty' => $user->hasAnyRole(['Admin', 'Registrar']),

        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Faculty/Create', [
            'departments' => Department::where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Store a newly created resource.
     */
    public function store(Request $request)
    {
        // Faculty Scope drives whether a department is applicable.
        // General Education faculty are never tied to a department,
        // so we normalize department_id to null before validating —
        // regardless of what the form happens to submit.
        $request->merge([
            'department_id' => $request->input('faculty_scope') === 'general'
                ? null
                : $request->input('department_id'),
        ]);

        $validated = $request->validate([

            // Personal Information
            'first_name' => [
                'required',
                'string',
                'max:255',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'last_name' => [
                'required',
                'string',
                'max:255',
            ],

            'suffix' => [
                'nullable',
                'string',
                'max:20',
            ],

            'gender' => [
                'nullable',
                'in:Male,Female',
            ],

            'contact_number' => [
                'nullable',
                'string',
                'max:20',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
                'unique:faculties,email',
            ],

            // Faculty Scope
            'faculty_scope' => [
                'required',
                'in:general,departmental,cross_department',
            ],

            // Department — required for Departmental and Cross
            // Department scope, must be null for General Education.
            'department_id' => [
                Rule::requiredIf(fn () => $request->input('faculty_scope') !== 'general'),
                Rule::prohibitedIf(fn () => $request->input('faculty_scope') === 'general'),
                'nullable',
                'exists:departments,id',
            ],

            // Employment
            'employment_type' => [
                'required',
                'in:Full-Time,Part-Time',
            ],

            // Teaching Load
            'max_units' => [
                'required',
                'integer',
                'min:1',
                'max:24',
            ],

            // Status
            'status' => [
                'required',
                'boolean',
            ],

        ]);

        $faculty = Faculty::create($validated);

        AuditLogService::log(
            action: 'created',
            module: 'Faculty',
            model: $faculty,
            description: "Created faculty member {$faculty->full_name}",
            newValues: [
                'name' => $faculty->full_name,
                'faculty_scope' => $validated['faculty_scope'],
                'department_id' => $validated['department_id'],
                'employment_type' => $validated['employment_type'],
                'max_units' => $validated['max_units'],
            ],
        );

        return redirect()
            ->route('faculty.index')
            ->with('success', 'Faculty member added successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Faculty $faculty)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Faculty $faculty)
    {
        abort_unless(
            $this->canEditFaculty(auth()->user(), $faculty),
            403,
            'You can only edit faculty in your own department (or General Education faculty).'
        );

        return Inertia::render('Faculty/Edit', [

            'faculty' => $faculty,

            'departments' => Department::where('active', true)
                ->orderBy('name')
                ->get(),

        ]);
    }

    /**
     * Update the specified resource.
     */
    public function update(Request $request, Faculty $faculty)
    {
        abort_unless(
            $this->canEditFaculty(auth()->user(), $faculty),
            403,
            'You can only edit faculty in your own department (or General Education faculty).'
        );

        // Same normalization as store() — General Education always
        // clears the department, no matter what the client sent.
        $request->merge([
            'department_id' => $request->input('faculty_scope') === 'general'
                ? null
                : $request->input('department_id'),
        ]);

        $validated = $request->validate([

            // Personal Information
            'first_name' => [
                'required',
                'string',
                'max:255',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'last_name' => [
                'required',
                'string',
                'max:255',
            ],

            'suffix' => [
                'nullable',
                'string',
                'max:20',
            ],

            'gender' => [
                'nullable',
                'in:Male,Female',
            ],

            'contact_number' => [
                'nullable',
                'string',
                'max:20',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('faculties', 'email')->ignore($faculty->id),
            ],

            // Faculty Scope
            'faculty_scope' => [
                'required',
                'in:general,departmental,cross_department',
            ],

            // Department — required for Departmental and Cross
            // Department scope, must be null for General Education.
            //
            // A scoped Dean/Assistant Dean/OIC (see canEditFaculty())
            // already can't edit faculty outside their own department,
            // but without this extra rule they could still reassign a
            // faculty member INTO a different department via this
            // field. Only Admin/Registrar can move a faculty member
            // to a department other than the editor's own.
            'department_id' => [
                Rule::requiredIf(fn () => $request->input('faculty_scope') !== 'general'),
                Rule::prohibitedIf(fn () => $request->input('faculty_scope') === 'general'),
                'nullable',
                'exists:departments,id',
                function ($attribute, $value, $fail) use ($request) {
                    $user = $request->user();

                    if ($user->hasAnyRole(['Admin', 'Registrar'])) {
                        return;
                    }

                    if (! is_null($value) && (int) $value !== (int) $user->department_id) {
                        $fail('You can only assign faculty to your own department.');
                    }
                },
            ],

            // Employment
            'employment_type' => [
                'required',
                'in:Full-Time,Part-Time',
            ],

            // Teaching Load
            'max_units' => [
                'required',
                'integer',
                'min:1',
                'max:24',
            ],

            // Status
            'status' => [
                'required',
                'boolean',
            ],

        ]);

        $oldValues = [
            'name' => $faculty->full_name,
            'faculty_scope' => $faculty->faculty_scope,
            'department_id' => $faculty->department_id,
            'employment_type' => $faculty->employment_type,
            'max_units' => $faculty->max_units,
            'status' => $faculty->status,
        ];

        $faculty->update($validated);

        AuditLogService::log(
            action: 'updated',
            module: 'Faculty',
            model: $faculty,
            description: "Updated faculty member {$faculty->full_name}",
            oldValues: $oldValues,
            newValues: [
                'name' => $faculty->full_name,
                'faculty_scope' => $validated['faculty_scope'],
                'department_id' => $validated['department_id'],
                'employment_type' => $validated['employment_type'],
                'max_units' => $validated['max_units'],
                'status' => $validated['status'],
            ],
        );

        return redirect()
            ->route('faculty.index')
            ->with('warning', 'Faculty member updated successfully.');
    }

    /**
     * What this faculty member is currently assigned to — the data
     * source for the Delete confirmation modal on Faculty/Index.vue.
     * Admin/Registrar sees this BEFORE the delete actually happens, so
     * "this faculty already has scheduled classes" isn't just a bare
     * warning — they can see exactly which subjects/sections/times
     * they're about to orphan and decide with real information.
     *
     * Returned as plain JSON (not an Inertia render), same pattern as
     * manageSubjects() — the modal fetches this via axios when Delete
     * is clicked, before any confirm() prompt or DELETE request fires.
     */
    public function deletePreview(Faculty $faculty)
    {
        $schedules = $faculty->schedules()
            ->with([
                'academicTerm:id,academic_year,semester',
                'room:id,room_code',
                'subjectOffering:id,edp_code,subject_id,section_id',
                'subjectOffering.subject:id,subject_code,descriptive_title',
                'subjectOffering.section:id,section_code',
            ])
            ->orderBy('day')
            ->orderBy('start_minutes')
            ->get()
            ->map(fn (Schedule $schedule) => [
                'id' => $schedule->id,
                'academic_term' => $schedule->academicTerm
                    ? "{$schedule->academicTerm->semester_label} · {$schedule->academicTerm->academic_year}"
                    : null,
                'subject_code' => $schedule->subjectOffering?->subject?->subject_code,
                'subject_title' => $schedule->subjectOffering?->subject?->descriptive_title,
                'section_code' => $schedule->subjectOffering?->section?->section_code,
                'room_code' => $schedule->room?->room_code,
                'day' => $schedule->day,
                'start_minutes' => $schedule->start_minutes,
                'end_minutes' => $schedule->end_minutes,
            ]);

        return response()->json([
            'faculty' => [
                'id' => $faculty->id,
                'full_name' => $faculty->full_name,
            ],
            'schedules' => $schedules,
        ]);
    }

    /**
     * Remove the specified resource.
     *
     * If this faculty member already has one or more persisted
     * Schedule rows (an actual Master Grid timetable block, not just a
     * Teaching Assignment/preference), deleting is still allowed —
     * Admin/Registrar may have a real reason to (faculty left
     * mid-semester, data cleanup, etc.) — but it requires an explicit
     * `confirmed=1` on the request. Index.vue enforces this with a
     * second, more strongly-worded confirm() dialog naming the
     * schedule conflict before it ever sends that flag; this check is
     * the actual enforcement, not the frontend prompt. What happens to
     * the affected Schedule rows themselves (cascade delete vs. FK
     * restrict) depends on the schedules.faculty_id foreign key rule
     * in your migration — worth confirming that's the behavior you
     * want before relying on this.
     */
    public function destroy(Request $request, Faculty $faculty)
    {
        // Widened beyond schedules() alone — a Teaching Assignment can
        // exist (Faculty Loading) even before a Master Grid Schedule
        // row is committed (see MasterGridController::syncTeachingAssignment(),
        // which is the reverse case: a Schedule can exist without a
        // Teaching Assignment). Either one means this faculty member
        // is genuinely "in use", so both should trigger the same
        // double-confirm warning, not just schedules().
        $hasSchedule = $faculty->schedules()->exists();
        $hasTeachingAssignment = $faculty->teachingAssignments()->exists();

        if (($hasSchedule || $hasTeachingAssignment) && ! $request->boolean('confirmed')) {
            return back()->with(
                'warning',
                "{$faculty->full_name} already has scheduled classes or teaching assignments. Confirm again to delete anyway."
            );
        }

        $facultyName = $faculty->full_name;

        // Even after confirmation, a plain delete() can still throw a
        // raw FK violation from tables this method never checks
        // (faculty_subjects, faculty_load_overloads,
        // faculty_subject_offering, faculty_load_activities) — the
        // confirm above only accounts for schedules/teaching
        // assignments. Wrapping in try/catch turns that into a
        // friendly redirect instead of a 500, same pattern as every
        // other destroy() in this codebase.
        try {
            $faculty->delete();
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                "Unable to delete {$facultyName}. They still have related records (subject assignments, overload requests, or activity history) that must be removed first."
            );
        }

        AuditLogService::log(
            action: 'deleted',
            module: 'Faculty',
            description: "Deleted faculty member {$facultyName}",
            oldValues: ['name' => $facultyName],
            recordName: $facultyName,
        );

        return redirect()
            ->route('faculty.index')
            ->with('deleted', 'Faculty member deleted successfully.');
    }

    /**
     * Manage Subjects data for a single Faculty member — returned as
     * plain JSON, not an Inertia page render. Direct mirror of
     * RoomController::manageSubjects(): this is purely the data source
     * for a "Manage Subjects" MODAL on Faculty/Index.vue. The Index
     * page fetches it via axios when a faculty's "Manage Subjects"
     * button is clicked, opens the modal client-side, and never
     * navigates away from Index — filters and scroll position stay
     * exactly as they were.
     *
     * Unlike Room, there is no "Room Type" to pre-filter offerings by —
     * every active-term Subject Offering is a candidate, annotated with
     * is_recommended (Faculty Scope eligibility, informational only —
     * see isEligible()) so the UI can visually group/sort, without
     * blocking a deliberate off-scope preference the same way Room's
     * "unrecommended" preferences are still allowed.
     */
    public function manageSubjects(Faculty $faculty)
    {
        $activeTerm = AcademicTerm::where('active', true)->first();

        $offerings = collect();

        if ($activeTerm) {

            $preferredIds = $faculty->preferredSubjectOfferings()
                ->where('subject_offerings.academic_term_id', $activeTerm->id)
                ->pluck('subject_offerings.id');

            $baseOfferings = SubjectOffering::with([
                    'subject:id,subject_code,descriptive_title,is_major,units',
                    'program:id,code',
                    'section:id,section_code,curriculum_id',
                    'section.curriculum:id,program_id',
                    'section.curriculum.program:id,department_id',
                ])
                ->where('academic_term_id', $activeTerm->id)
                ->orderBy('edp_code')
                ->get();

            $claimedByOtherFaculty = DB::table('faculty_subject_offering')
                ->join('faculties', 'faculties.id', '=', 'faculty_subject_offering.faculty_id')
                ->whereIn('faculty_subject_offering.subject_offering_id', $baseOfferings->pluck('id'))
                ->where('faculty_subject_offering.faculty_id', '!=', $faculty->id)
                ->get(['faculty_subject_offering.subject_offering_id', 'faculties.first_name', 'faculties.last_name'])
                ->mapWithKeys(fn ($row) => [
                    $row->subject_offering_id => trim("{$row->first_name} {$row->last_name}"),
                ]);

            $offerings = $baseOfferings
                ->map(function (SubjectOffering $offering) use ($faculty, $preferredIds, $claimedByOtherFaculty) {
                    return [
                        'id' => $offering->id,
                        'edp_code' => $offering->edp_code,
                        'subject_code' => $offering->subject?->subject_code,
                        'subject_title' => $offering->subject?->descriptive_title,
                        'program_code' => $offering->program?->code,
                        'year_level' => $offering->year_level,
                        'section_code' => $offering->section?->section_code,
                        'units' => $offering->units,
                        'classification' => $offering->classification,
                        'is_preferred' => $preferredIds->contains($offering->id),
                        'is_recommended' => $this->isEligible($faculty, $offering),
                        'claimed_by_faculty_name' => $claimedByOtherFaculty->get($offering->id),
                    ];
                })
                ->values();
        }

        return response()->json([

            'faculty' => [
                'id' => $faculty->id,
                'full_name' => $faculty->full_name,
                'faculty_scope' => $faculty->faculty_scope,
                'department_id' => $faculty->department_id,
            ],

            'active_academic_term' => $activeTerm ? [
                'id' => $activeTerm->id,
                'display_name' => $activeTerm->display_name,
            ] : null,

            'offerings' => $offerings,

        ]);
    }

    /**
     * Replace this faculty member's Preferred Subject Offerings for the
     * ACTIVE Academic Term only. Direct mirror of
     * RoomController::syncPreferredSubjects() — see that method for the
     * full reasoning; the same rules apply here:
     *
     *   - Only the active term's preference rows are ever touched.
     *   - Every incoming ID is re-validated server-side against the
     *     active term. The eligibility smart-filter (is_recommended) is
     *     a UI convenience only and is NOT re-enforced here — a
     *     scheduler may deliberately record an "unrecommended"
     *     preference, and that's allowed. This is a preference, not an
     *     assignment; TeachingAssignmentService's real eligibility
     *     rules still govern the actual Faculty Loading assignment
     *     separately.
     *   - A Subject Offering can only be preferred by ONE faculty
     *     member at a time (room_subject_offering-style unique index on
     *     subject_offering_id). Selecting an offering here that another
     *     faculty member currently claims TRANSFERS it to this faculty
     *     member rather than erroring.
     */
    public function syncPreferredSubjects(Request $request, Faculty $faculty)
    {
        $validated = $request->validate([
            'subject_offering_ids' => ['present', 'array'],
            'subject_offering_ids.*' => ['integer', 'exists:subject_offerings,id'],
        ]);

        $activeTerm = AcademicTerm::where('active', true)->first();

        abort_unless($activeTerm, 422, 'There is no active Academic Term to manage preferences for.');

        $activeTermOfferingIds = SubjectOffering::where('academic_term_id', $activeTerm->id)->pluck('id');

        $selectedIds = collect($validated['subject_offering_ids'])
            ->intersect($activeTermOfferingIds)
            ->values();

        // Only ever touch this term's rows — detach everything this
        // faculty member currently prefers for the active term, then
        // reattach the (re-validated) submitted selection.
        $faculty->preferredSubjectOfferings()->detach($activeTermOfferingIds);

        // Transfer semantics — see docblock above.
        DB::table('faculty_subject_offering')
            ->whereIn('subject_offering_id', $selectedIds)
            ->delete();

        $faculty->preferredSubjectOfferings()->attach($selectedIds);

        $preferredUnits = (int) $faculty->preferredSubjectOfferings()
            ->where('subject_offerings.academic_term_id', $activeTerm->id)
            ->join('subjects', 'subjects.id', '=', 'subject_offerings.subject_id')
            ->sum('subjects.units');

        $preferredCount = $faculty->preferredSubjectOfferings()
            ->where('subject_offerings.academic_term_id', $activeTerm->id)
            ->count();

        return response()->json([
            'message' => 'Preferred subjects updated successfully.',
            'faculty_id' => $faculty->id,
            'preferred_units' => $preferredUnits,
            'preferred_count' => $preferredCount,
        ]);
    }

    /**
     * Whether a Subject Offering fits this Faculty member's Scope —
     * informational only (see manageSubjects() docblock). Mirrors the
     * same three-scope business rule already previewed client-side in
     * TeachingAssignments/Index.vue's checkEligibility(), and enforced
     * authoritatively by TeachingAssignmentService for real Faculty
     * Loading assignments:
     *
     *   - General Education: Minor subjects only.
     *   - Departmental: Major subjects only, within their own
     *     department.
     *   - Cross Department: Major subjects must stay within their own
     *     department; Minor subjects are unrestricted.
     *
     * Requires $offering->section.curriculum.program to already be
     * eager-loaded by the caller to avoid an N+1 query per offering.
     */
    private function isEligible(Faculty $faculty, SubjectOffering $offering): bool
    {
        if (! $faculty->status) {
            return false;
        }

        $isMajor = (bool) $offering->subject?->is_major;
        $subjectDepartmentId = $offering->section?->curriculum?->program?->department_id;

        if ($faculty->faculty_scope === 'general' && $isMajor) {
            return false;
        }

        if ($faculty->faculty_scope === 'departmental' && ! $isMajor) {
            return false;
        }

        if ($faculty->faculty_scope === 'departmental' && $subjectDepartmentId !== $faculty->department_id) {
            return false;
        }

        if ($faculty->faculty_scope === 'cross_department' && $isMajor && $subjectDepartmentId !== $faculty->department_id) {
            return false;
        }

        return true;
    }
}