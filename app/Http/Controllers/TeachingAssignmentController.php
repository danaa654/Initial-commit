<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeachingAssignmentRequest;
use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyLoadActivity;
use App\Models\FacultyLoadOverload;
use App\Models\SubjectOffering;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\SchedulingWorkspaceService;
use App\Services\AuditLogService;
use App\Services\ActivityHistoryService;
use App\Services\TeachingAssignmentService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;

class TeachingAssignmentController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly TeachingAssignmentService $service,
        private readonly SchedulingWorkspaceService $workspace
    ) {
    }

    /**
     * Controller Middleware
     *
     * Faculty Loading follows the same permission tier as Faculty,
     * Subjects, and Rooms.
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

            }),

        ];
    }

    /**
     * Display the Faculty Loading workspace.
     *
     * A searchable faculty roster on the left, and — once a faculty
     * member is selected — their load summary, assigned Subject
     * Offerings, and an "Assign Subject" action on the right.
     *
     * Faculty are assigned directly to Subject Offerings, not to a
     * bare Subject — an Offering already carries its own program,
     * year level, section, and academic term, so there's no separate
     * "which section/curriculum item" step to resolve. Which
     * offerings are still unassigned is answered client-side by
     * cross-referencing subjectOfferings against teachingAssignments
     * (an offering is unassigned when no Teaching Assignment exists
     * for its subject_offering_id — the same column the
     * teaching_assignments unique index already protects).
     *
     * Eligibility to teach is decided purely by Faculty Scope +
     * Department + Subject Category (Major/Minor) — see
     * TeachingAssignmentService. There is no Faculty Subject
     * qualification list anywhere in this module anymore.
     *
     * RBAC: Dean and OIC are scoped to their own college — everything
     * handed down here (roster, assignments, offerings) is narrowed to
     * their department_id (plus General Education, which belongs to no
     * department and serves every college). Admin, Registrar, and
     * Assistant Dean oversee every department, so they see everything
     * unscoped — see managerDepartmentId().
     */
    public function index()
    {
        // Admin/Registrar manage Faculty Loading against the Planning
        // Academic Term (so they can staff up next semester's
        // offerings ahead of time); Dean/Assistant Dean/OIC only ever
        // see the Active Academic Term here, read-only — a future
        // draft schedule isn't official yet, so they shouldn't be
        // reviewing faculty loads that could still change. See
        // SchedulingWorkspaceService::getTermForUser().
        $planningTerm = $this->workspace->getTermForUser(auth()->user());

        $departmentId = $this->managerDepartmentId(auth()->user());

        return Inertia::render('TeachingAssignments/Index', [

            'planningTerm' => $planningTerm,

            // Every prop below is wrapped in a closure (fn () => ...)
            // rather than eagerly evaluated. This isn't just style —
            // it's what makes Inertia's partial reloads
            // (router.post/delete(..., { only: [...] })) actually skip
            // the query, not just trim it from the JSON payload. If a
            // prop is a plain array/value instead of a closure,
            // Laravel still has to evaluate it up front to build the
            // props array at all, so an `only` reload that excludes it
            // saves bandwidth but NOT the query time or memory — the
            // whole point of the optimization. See
            // Partials/Index.vue's handleAssign()/removeAssignment()/
            // handleUnassign(), which now request
            // only: ['teachingAssignments', 'subjectOfferings',
            // 'faculties', 'flash'] after an assign/unassign action —
            // 'departments', 'pendingOverloadRequests', and
            // 'recentActivity' never change from that action and are
            // now skipped entirely on those requests instead of being
            // silently re-queried and then thrown away.

            // 'loadOverloads' is eager-loaded here (not just fetched on
            // demand) so every faculty card/row can show
            // effective_max_units, approved_overload_units, etc.
            // without an N+1 query — see Faculty's accessors, which
            // prefer the already-loaded collection when present.
            'faculties' => fn () => Faculty::with(['department', 'loadOverloads'])
                ->when($departmentId, fn ($query) => $query->where(
                    fn ($inner) => $inner->whereNull('department_id')->orWhere('department_id', $departmentId)
                ))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()

                // can_edit gates the Edit button in the faculty detail
                // panel on this page — mirrors the same rule
                // FacultyController::update() enforces server-side, so
                // the button never promises an edit the backend would
                // then 403 on. canManageFaculty (Admin/Registrar only,
                // computed separately below) covers Add/Delete instead.
                ->map(function (Faculty $faculty) {
                    $faculty->can_edit = FacultyController::canEditFaculty(auth()->user(), $faculty);

                    return $faculty;
                }),

            'departments' => fn () => Department::where('active', true)
                ->when($departmentId, fn ($query) => $query->where('id', $departmentId))
                ->orderBy('name')
                ->get(),

            // Add/Delete Faculty are Admin/Registrar only (matches
            // FacultyController::middleware()) — gates the "+ Add
            // Faculty" button and the Delete action in the faculty
            // detail panel. Edit is gated per-faculty instead via
            // can_edit above, since Dean/Assistant Dean/OIC can edit
            // within their own department.
            'canManageFaculty' => auth()->user()->hasAnyRole(['Admin', 'Registrar']),

            // Every Faculty Loading assignment for the active term,
            // with everything the workspace needs to render the
            // "Assigned Subjects" table and compute each faculty
            // member's load — no per-faculty round trips. Scoped to
            // the same faculty set as the roster above, so a Dean
            // never sees assignment data for faculty they can't even
            // select.
            'teachingAssignments' => fn () => $planningTerm
                ? TeachingAssignment::with([
                        'subjectOffering.subject',
                        'subjectOffering.section.curriculum.program.department',
                        // Preferred Room, if any — surfaced here so the
                        // Assigned Subjects table can show "Room 303"
                        // (as a preference, not a final schedule) even
                        // though this table has no room_id of its own.
                        // See SubjectOffering::preferredByRooms().
                        'subjectOffering.preferredByRooms',
                        // Eager-loaded so SubjectOffering's
                        // faculty_status/room_status/overall_status
                        // accessors (all in $appends, so they run for
                        // EVERY offering whenever this prop is
                        // serialized) never fall back to a per-row
                        // query. Without these two, every one of this
                        // term's offerings fired up to 3 extra queries
                        // each on page load — see
                        // SubjectOffering::hasScheduleAssigned() and
                        // getFacultyStatusAttribute(). Distinct from
                        // TeachingAssignment::schedule() below (a
                        // hasOneThrough on THIS model) — that one does
                        // NOT populate subjectOffering->schedule, since
                        // they're different relations on different
                        // models even though they read the same table.
                        'subjectOffering.schedule',
                        'subjectOffering.teachingAssignment',
                        // computeOverallStatus() reads
                        // $this->academicTerm->status and
                        // ->class_end_date for every offering — the
                        // one relation still missing after the first
                        // fix, and still enough on its own to lazy-
                        // load once per offering (269+ extra queries)
                        // without it.
                        'subjectOffering.academicTerm',
                        // The actual committed Master Grid schedule
                        // block for this assignment's offering, if
                        // Generate Schedule + Save Schedule has already
                        // run for it — see TeachingAssignment::schedule()
                        // and MasterGridController::save(). When present,
                        // this takes priority over the room preference
                        // above: a preference is a wish, a schedule is a
                        // fact.
                        'schedule.room',
                        'faculty',
                    ])
                    ->forTerm($planningTerm->id)
                    ->when($departmentId, fn ($query) => $query->whereHas(
                        'faculty',
                        fn ($inner) => $inner->whereNull('department_id')->orWhere('department_id', $departmentId)
                    ))
                    ->get()
                : [],

            // Subject Offerings for the active term. The Assign
            // Subject modal filters these down to "not yet assigned"
            // client-side. Scoped to the manager's own department's
            // programs — a Dean of CTE has no reason to see CCS's
            // offerings in the Assign Subject list.
            'subjectOfferings' => fn () => $planningTerm
                ? SubjectOffering::with([
                        'subject',
                        'section.curriculum.program.department',
                        'curriculumItem',
                        'preferredByRooms',
                        // Same fix as 'teachingAssignments' above —
                        // without these two, the faculty_status/
                        // room_status/overall_status accessors
                        // (appended on every SubjectOffering) each fall
                        // back to a per-row query across all 269+
                        // offerings in this term. See
                        // SubjectOffering::hasScheduleAssigned().
                        'schedule',
                        'teachingAssignment',
                        // Same reason as above — computeOverallStatus()
                        // reads $this->academicTerm on every offering.
                        // This was the one relation still missing after
                        // the first eager-load fix, and on its own was
                        // enough to keep this prop firing ~269 lazy-
                        // loaded queries.
                        'academicTerm',
                    ])
                    ->where('academic_term_id', $planningTerm->id)
                    ->when($departmentId, fn ($query) => $query->whereHas(
                        'section.curriculum.program',
                        fn ($inner) => $inner->where('department_id', $departmentId)
                    ))
                    ->get()
                : [],

            // Faculty Load Overload requests still awaiting review —
            // populated ONLY for Admin/Registrar (mirrors
            // 'academicTermsForSwitcher' in HandleInertiaRequests: an
            // empty array for everyone else keeps the review panel
            // from rendering at all for Dean/Assistant Dean/OIC, who
            // can submit requests but never approve/decline them).
            'pendingOverloadRequests' => fn () => auth()->user()->hasAnyRole(['Admin', 'Registrar'])
                ? FacultyLoadOverload::with(['faculty', 'requestedBy'])
                    ->pending()
                    ->orderBy('created_at')
                    ->get()
                : [],

            // "Recent Activity" feed for the Faculty Loading overview
            // — shown only in the empty state before any faculty is
            // selected (see Index.vue's `v-if="!selectedFaculty"`
            // panel). Scoped to the same faculty set as the roster
            // above, so a Dean never sees activity for faculty they
            // can't even manage. Not filtered by academic term: a
            // scoped manager's history of who-assigned-what is useful
            // context regardless of which term is currently being
            // staffed, and the volume here is naturally low enough
            // that a flat "most recent 15" needs no further filtering.
            // See FacultyLoadActivity, and the logActivity() calls in
            // store()/destroy() below for what writes into it.
            'recentActivity' => fn () => FacultyLoadActivity::with(['faculty', 'subjectOffering.subject', 'overload', 'performedBy'])
                ->when($departmentId, fn ($query) => $query->whereHas(
                    'faculty',
                    fn ($inner) => $inner->whereNull('department_id')->orWhere('department_id', $departmentId)
                ))
                ->latest('created_at')
                ->limit(15)
                ->get(),

        ]);
    }

    /**
     * Assign a faculty member to a Subject Offering.
     */
    public function store(TeachingAssignmentRequest $request)
    {
        $validated = $request->validated();

        $faculty = Faculty::findOrFail($validated['faculty_id']);

        $this->assertManagesFaculty($faculty);

        // TeachingAssignmentService::assertAcademicTermIsActive() only
        // checks that the offering's term MATCHES the current Working
        // Term — it doesn't know about Archived. If Admin/Registrar has
        // deliberately switched the Working Term to an Archived
        // semester to review it, that match would otherwise succeed and
        // silently let them write to historical record. This is the
        // explicit stop for that case.
        $this->workspace->assertWritable($this->workspace->getTermForUser(auth()->user()));

        $this->service->assertBusinessRules($validated);

        // Fetched here (not just referenced by ID) so logActivity()
        // below has the subject's title/edp_code available for its
        // snapshot without a second round trip.
        $offering = SubjectOffering::with('subject')->find($validated['subject_offering_id']);

        TeachingAssignment::create($validated);

        $this->logActivity(FacultyLoadActivity::ACTION_ASSIGNED, $faculty, $offering);

        // Audit Log — matches the format in the Audit Log spec's own
        // example: "Assigned Regil Kent M. Seville to BSIT 1-A CC103".
        // $offering->section/subject were already eager-loaded above
        // when $offering was fetched with 'subject', but section isn't,
        // so it's loaded here on demand — this only runs once per
        // assignment, not per page load, so the extra query is cheap.
        $offering->loadMissing('section');

        AuditLogService::log(
            action: 'assigned',
            module: 'Faculty Loading',
            model: $faculty,
            description: "Assigned {$faculty->full_name} to {$offering->section?->section_code} {$offering->edp_code}",
            newValues: [
                'faculty' => $faculty->full_name,
                'subject_offering' => $offering->edp_code,
                'section' => $offering->section?->section_code,
            ],
            recordName: "{$offering->section?->section_code} {$offering->edp_code}",
        );

        // Activity History milestone — "Faculty Loading Completed"
        // fires exactly once per term, the first time every Subject
        // Offering in it has a Teaching Assignment. hasRecorded() is
        // checked first so this never re-fires on subsequent
        // assignments once the term is already fully loaded (e.g. a
        // faculty override afterward doesn't re-announce completion).
        $academicTerm = $offering->academicTerm;

        if ($academicTerm && ! ActivityHistoryService::hasRecorded('faculty_loading.completed', $academicTerm->id)) {
            $totalOfferings = SubjectOffering::where('academic_term_id', $academicTerm->id)->count();
            $assignedOfferings = SubjectOffering::where('academic_term_id', $academicTerm->id)
                ->whereHas('teachingAssignment')
                ->count();

            if ($totalOfferings > 0 && $assignedOfferings === $totalOfferings) {
                $facultyCount = TeachingAssignment::whereHas(
                    'subjectOffering',
                    fn ($q) => $q->where('academic_term_id', $academicTerm->id)
                )->distinct('faculty_id')->count('faculty_id');

                ActivityHistoryService::recordFacultyLoadingCompleted($academicTerm, $totalOfferings, $facultyCount);
            }
        }

        return redirect()
            ->route('teaching-assignments.index')
            ->with('success', 'Faculty load assigned successfully.');
    }

    /**
     * Remove a faculty member's assignment to a Subject Offering.
     */
    public function destroy(TeachingAssignment $teachingAssignment)
    {
        $this->assertManagesFaculty($teachingAssignment->faculty);

        $this->workspace->assertWritable($teachingAssignment->subjectOffering?->academicTerm);

        // Captured before delete() — once the row is gone,
        // $teachingAssignment->faculty/subjectOffering would still
        // resolve via the FK columns still in memory, but grabbing
        // them explicitly here keeps the intent obvious and safe
        // against any future change to those accessors.
        $faculty = $teachingAssignment->faculty;
        $offering = $teachingAssignment->subjectOffering;

        $teachingAssignment->delete();

        $this->logActivity(FacultyLoadActivity::ACTION_UNASSIGNED, $faculty, $offering);

        // Audit Log — old_values captures what existed before removal,
        // since there's nothing left in the database to read back
        // afterward (the row is already gone by this point).
        $offering?->loadMissing('section');

        AuditLogService::log(
            action: 'unassigned',
            module: 'Faculty Loading',
            model: $faculty,
            description: $faculty && $offering
                ? "Removed {$faculty->full_name} from {$offering->section?->section_code} {$offering->edp_code}"
                : 'Removed a faculty load assignment',
            oldValues: [
                'faculty' => $faculty?->full_name,
                'subject_offering' => $offering?->edp_code,
                'section' => $offering?->section?->section_code,
            ],
            recordName: $offering ? "{$offering->section?->section_code} {$offering->edp_code}" : null,
        );

        return back()->with('success', 'Assignment removed successfully.');
    }

    /**
     * Write one row to the Faculty Loading audit trail. Snapshot
     * columns are filled in alongside the live FKs so the Recent
     * Activity feed keeps reading correctly even if the faculty
     * member or subject offering referenced here is deleted later —
     * see FacultyLoadActivity and its migration.
     */
    private function logActivity(string $action, ?Faculty $faculty, ?SubjectOffering $offering): void
    {
        FacultyLoadActivity::create([
            'faculty_id' => $faculty?->id,
            'subject_offering_id' => $offering?->id,
            'performed_by' => auth()->id(),
            'action' => $action,
            'faculty_name_snapshot' => $faculty?->full_name,
            'subject_snapshot' => $offering?->subject?->descriptive_title,
            'edp_code_snapshot' => $offering?->edp_code,
            'created_at' => now(),
        ]);
    }

    /**
     * RBAC guard: can the currently logged-in manager touch this
     * particular faculty member's load at all?
     *
     * This is deliberately separate from TeachingAssignmentService's
     * eligibility rules — those decide whether a faculty member CAN
     * teach a given subject; this decides whether the person making the
     * request is even allowed to manage that faculty member in the
     * first place. That's a question about the authenticated user, not
     * about the Faculty/SubjectOffering pair, so it lives here in the
     * controller rather than in the service.
     *
     * Scoped managers (Dean, OIC) may manage:
     *   - faculty in their own department, or
     *   - General Education faculty (department_id is null — they
     *     carry no department of their own and serve every college).
     *
     * Unscoped managers (Admin, Registrar, Assistant Dean) may manage
     * any faculty member — see managerDepartmentId().
     *
     * A 403 here (rather than a soft validation error) is intentional:
     * this is a genuine permission violation, not something the user
     * can correct by picking a different value in the form.
     */
    private function assertManagesFaculty(Faculty $faculty): void
    {
        $departmentId = $this->managerDepartmentId(auth()->user());

        if ($departmentId === null) {
            return;
        }

        if ($faculty->department_id !== null && (int) $faculty->department_id !== $departmentId) {
            abort(403, 'You do not have permission to manage this faculty member\'s load.');
        }
    }

    /**
     * The department a manager is scoped to, or null if they oversee
     * every department. Admin, Registrar, and Assistant Dean always
     * carry a null department_id and are never scoped — mirrors the
     * exact same role list UserController::index() uses to decide
     * whether to show "All Departments" for a user.
     */
    private function managerDepartmentId(User $user): ?int
    {
        if ($user->hasAnyRole(['Admin', 'Registrar', 'Assistant Dean'])) {
            return null;
        }

        return $user->department_id;
    }
}