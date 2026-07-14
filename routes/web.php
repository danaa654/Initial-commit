<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AcademicStructureController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\SpecializationController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\CurriculumItemController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectOfferingController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\AcademicTermController;
use App\Http\Controllers\TermFinalizationController;
use App\Http\Controllers\TeachingAssignmentController;
use App\Http\Controllers\FacultyLoadOverloadController;
use App\Http\Controllers\MasterGridController;
use App\Http\Controllers\BlockScheduleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ActivityHistoryController;
use App\Http\Controllers\ActiveUserController;
/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
})->name('welcome');

/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Settings > Scheduling Workspace
    |--------------------------------------------------------------------------
    |
    | Lets Admin/Registrar point every scheduling module at a Planning
    | Academic Term that can be months ahead of whatever term is
    | officially Active — see SchedulingWorkspaceService. Dean,
    | Assistant Dean, and OIC may view this page (so they always know
    | which term scheduling is currently pointed at) but only
    | Admin/Registrar may change it, hence the update route sits in
    | its own role:Admin|Registrar sub-group below.
    |
    */

    Route::middleware('role:Admin|Registrar|Dean|Assistant Dean|OIC')->group(function () {

        Route::get('settings/scheduling-workspace', [SettingsController::class, 'schedulingWorkspace'])
            ->name('settings.scheduling-workspace');

    });

    Route::middleware('role:Admin|Registrar')->group(function () {

        Route::put('settings/scheduling-workspace', [SettingsController::class, 'updateSchedulingWorkspace'])
            ->name('settings.scheduling-workspace.update');

        // Alias for the Topbar's quick "Switch Working Term" dropdown —
        // same controller method as above (see SettingsController's
        // class docblock), just reachable from anywhere in the app
        // without navigating to the Settings page first.
        Route::put('working-term', [SettingsController::class, 'updateSchedulingWorkspace'])
            ->name('working-term.update');

        // Settings > Scheduling Workspace > College Finalization —
        // only callable against the current Active term (see
        // TermFinalizationController); Dean/Assistant Dean/OIC can
        // still VIEW status via the schedulingWorkspace() route above
        // (that route sits in the broader role group), they just
        // can't hit these two.
        Route::post('settings/finalization/{department}/finalize', [TermFinalizationController::class, 'finalize'])
            ->name('settings.finalization.finalize');

        Route::post('settings/finalization/{department}/unfinalize', [TermFinalizationController::class, 'unfinalize'])
            ->name('settings.finalization.unfinalize');

    });

    /*
    |--------------------------------------------------------------------------
    | ADMIN ONLY
    |--------------------------------------------------------------------------
    | Only the Admin can create/manage user accounts.
    */

    Route::middleware('role:Admin')->group(function () {

        Route::resource('users', UserController::class);

        /*
        |--------------------------------------------------------------------------
        | System Monitor > Active Users
        |--------------------------------------------------------------------------
        |
        | System Administrators ONLY — stricter than Audit Logs/Activity
        | History (Admin + Registrar). Read-only: no store/update/destroy,
        | same shape as AuditLogController/ActivityHistoryController.
        | ActiveUserController's own HasMiddleware enforces this same
        | restriction again on the controller side, so a direct hit
        | still 403s even if this route grouping is ever rearranged
        | later.
        |
        */

        Route::get('active-users', [ActiveUserController::class, 'index'])
            ->name('active-users.index');

    });

    /*
    |--------------------------------------------------------------------------
    | ADMIN + REGISTRAR
    |--------------------------------------------------------------------------
    | Registrar has the same power as Admin except Users.
    */

    Route::middleware('role:Admin|Registrar')->group(function () {

        Route::get('academic-structure', [AcademicStructureController::class, 'index'])
            ->name('academic-structure.index');

        Route::resource('departments', DepartmentController::class);

        Route::resource('programs', ProgramController::class);

        Route::resource('specializations', SpecializationController::class);

        Route::resource('curriculums', CurriculumController::class);

        /*
        |--------------------------------------------------------------------------
        | Curriculum Items
        |--------------------------------------------------------------------------
        |
        | Standard CRUD for attaching items (Subject or OJT) into a
        | curriculum's prospectus. ->parameters() keeps the route
        | wildcard camelCase ({curriculumItem}) so it matches the
        | controller's $curriculumItem argument for implicit model
        | binding.
        |
        */

        Route::resource('curriculum-items', CurriculumItemController::class)
            ->except(['show'])
            ->parameters(['curriculum-items' => 'curriculumItem']);

        // Curriculum-scoped "Manage Items" workspace — grouped by year
        // level and semester for a single curriculum.
        Route::get('/curriculums/{curriculum}/items', [CurriculumItemController::class, 'manage'])
            ->name('curriculums.items.manage');

        // Printable Prospectus — every item in this curriculum, grouped
        // by Year Level + Semester, rendered as a plain Blade view for
        // the browser's print dialog. Must stay above resource routes
        // that could otherwise shadow it (not an issue here since this
        // is a nested {curriculum}/print path, not a bare wildcard).
        Route::get('/curriculums/{curriculum}/print', [CurriculumController::class, 'print'])
            ->name('curriculums.print');

        /*
        |--------------------------------------------------------------------------
        | Sections
        |--------------------------------------------------------------------------
        |
        | Section master data (e.g. BSIT-1A) — grouped by curriculum. No
        | scheduling logic lives here; this is used later by the
        | automatic scheduling engine.
        |
        */

        Route::resource('sections', SectionController::class);

        /*
        |--------------------------------------------------------------------------
        | Irregular Sections — manual Subject picker
        |--------------------------------------------------------------------------
        |
        | Admin/Registrar only, same tier as Subject Offerings
        | generate/store below (both additionally gated per-action by
        | SubjectOfferingPolicy::generate() in the controller). Lives
        | under SubjectOfferingController — not SectionController —
        | since these two actions create/list Subject Offerings, not
        | Sections themselves; see
        | SubjectOfferingController::irregularSubjects()/
        | storeIrregular().
        */

        Route::get('sections/{section}/irregular-subjects', [SubjectOfferingController::class, 'irregularSubjects'])
            ->name('sections.irregular-subjects');

        Route::post('sections/{section}/irregular-subjects', [SubjectOfferingController::class, 'storeIrregular'])
            ->name('sections.irregular-subjects.store');

        /*
        |--------------------------------------------------------------------------
        | Academic Terms
        |--------------------------------------------------------------------------
        |
        | One Academic Term = one scheduling period (e.g. AY 2026-2027,
        | 1st Semester). The registrar can prepare future terms while
        | the current term is still active — only one term may have
        | active = true at any time (enforced in the controller).
        |
        */

        Route::resource('academic-terms', AcademicTermController::class);

        // The "Archive & Activate Next Term" action behind the Semester
        // Ended banner — see SemesterTransitionService and
        // HandleInertiaRequests' 'semesterTransition' shared prop.
        Route::post('academic-terms/close-active', [AcademicTermController::class, 'closeActiveTerm'])
            ->name('academic-terms.close-active');

        /*
        |--------------------------------------------------------------------------
        | Subject Offerings — Generate / Delete (Admin + Registrar only)
        |--------------------------------------------------------------------------
        |
        | Viewing the list (index) is registered further down, in the
        | Admin|Registrar|Dean|Assistant Dean|OIC group — Dean/Assistant
        | Dean/OIC need to see what's been offered to make sense of
        | Faculty Loading, but they never generate or delete an
        | offering. Generating/deleting stay here, Admin + Registrar
        | only, and are additionally checked per-action in
        | SubjectOfferingController (SubjectOfferingPolicy::generate()
        | for create/store, an explicit role check for destroy) so a
        | direct hit still 403s even if this route grouping is ever
        | rearranged later.
        |
        | No resource route here on purpose — there is no update for a
        | single offering (Overall Status is fully derived, not
        | editable). Offerings are generated automatically when a
        | Section is created (see SectionController::store()) — there
        | is no manual "Generate" form anymore. Faculty assignment for
        | an offering happens in Faculty Loading (Teaching
        | Assignments), not here.
        */

        Route::delete('subject-offerings/{subjectOffering}', [SubjectOfferingController::class, 'destroy'])
            ->name('subject-offerings.destroy');

        // Bulk Update Weekly Hours — Admin + Registrar only, same tier
        // as store()/destroy() above. A JSON endpoint (not a full
        // Inertia navigation) so the Index page can reload just the
        // `offerings` prop afterward and keep filters/sorting/
        // pagination exactly as they were — see
        // SubjectOfferingController::bulkUpdateWeeklyHours().
        Route::post('subject-offerings/bulk-update-weekly-hours', [SubjectOfferingController::class, 'bulkUpdateWeeklyHours'])
            ->name('subject-offerings.bulk-update-weekly-hours');

        /*
        |--------------------------------------------------------------------------
        | Audit Logs
        |--------------------------------------------------------------------------
        |
        | System-wide security/accountability log — Admin/Registrar
        | only, same tier as everything else in this group. Read-only:
        | just index() (the filtered/paginated list) and show() (the
        | row detail panel's JSON) — there is no store/update/destroy,
        | since Audit Logs can never be edited or deleted through the
        | UI. Rows are written exclusively by AuditLogService::log(),
        | called from wherever an action actually happens elsewhere in
        | the app; nothing here ever creates a log entry directly.
        |
        */

        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->name('audit-logs.index');

        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])
            ->name('audit-logs.show');

        /*
        |--------------------------------------------------------------------------
        | Activity History
        |--------------------------------------------------------------------------
        |
        | The scheduling Timeline — NOT Audit Logs. Same Admin/Registrar-
        | only tier as Audit Logs above; Dean/Assistant Dean/OIC cannot
        | reach this page at all. Read-only: just index(). Rows are
        | written exclusively by ActivityHistoryService::record(),
        | called from wherever a scheduling milestone actually happens
        | elsewhere in the app — never through this controller.
        |
        */

        Route::get('activity-history', [ActivityHistoryController::class, 'index'])
            ->name('activity-history.index');

    });

    /*
    |--------------------------------------------------------------------------
    | ADMIN + REGISTRAR + DEAN + ASSISTANT DEAN + OIC
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:Admin|Registrar|Dean|Assistant Dean|OIC')->group(function () {

        // index/create/edit/show removed — the Faculty roster,
        // Add/Edit/Delete are now all handled on the Faculty Loading
        // page (teaching-assignments.index) via modals. store/update/
        // destroy stay here since that page's forms POST/PUT/DELETE
        // straight to these same endpoints.
        Route::resource('faculty', FacultyController::class)
            ->except(['index', 'create', 'edit', 'show']);

        /*
        |--------------------------------------------------------------------------
        | Faculty Preferences ("Manage Subjects")
        |--------------------------------------------------------------------------
        |
        | Per-faculty workspace for selecting which active-term Subject
        | Offerings a faculty member PREFERS to teach. This stores
        | preferences only (see faculty_subject_offering) — no
        | day/time/room is assigned here, and this is NOT the actual
        | Faculty Loading assignment (that's Teaching Assignments,
        | above). Direct mirror of Rooms' "Manage Subjects" below.
        |
        */

        Route::get('faculty/{faculty}/manage-subjects', [FacultyController::class, 'manageSubjects'])
            ->name('faculty.manage-subjects');

        Route::put('faculty/{faculty}/manage-subjects', [FacultyController::class, 'syncPreferredSubjects'])
            ->name('faculty.manage-subjects.update');

        /*
        |--------------------------------------------------------------------------
        | Faculty Delete — Schedule Preview
        |--------------------------------------------------------------------------
        |
        | Data source for the Delete confirmation modal on the Faculty
        | Loading page (TeachingAssignments/Index.vue) —
        | lets Admin/Registrar see exactly which scheduled classes a faculty
        | member currently has BEFORE deleting them. Sits in this same route
        | group (so Dean/Assistant Dean/OIC can still reach it without a 403
        | from routing), but FacultyController::middleware() narrows the
        | actual access down to Admin/Registrar only, same tier as destroy().
        |
        */

        Route::get('faculty/{faculty}/delete-preview', [FacultyController::class, 'deletePreview'])
            ->name('faculty.delete-preview');

        Route::resource('subjects', SubjectController::class);

        // Rooms — master list only (no schedules/availability here).
        Route::resource('rooms', RoomController::class);

        /*
        |--------------------------------------------------------------------------
        | Room Preferences ("Manage Subjects")
        |--------------------------------------------------------------------------
        |
        | Per-room workspace for selecting which active-term Subject
        | Offerings a room PREFERS to host. This stores preferences only
        | (see room_subject_offering) — no day/time/faculty is assigned
        | here. That belongs to the future Scheduling module.
        |
        */

        Route::get('rooms/{room}/manage-subjects', [RoomController::class, 'manageSubjects'])
            ->name('rooms.manage-subjects');

        Route::put('rooms/{room}/manage-subjects', [RoomController::class, 'syncPreferredSubjects'])
            ->name('rooms.manage-subjects.update');

        /*
        |--------------------------------------------------------------------------
        | Subject Offerings — View only
        |--------------------------------------------------------------------------
        |
        | Dean/Assistant Dean/OIC can see what classes exist for the
        | term (they need this to make sense of Faculty Loading below),
        | but never generate or delete an offering — those actions live
        | in the Admin|Registrar group above, out of reach here even by
        | a direct route hit.
        */

        Route::get('subject-offerings', [SubjectOfferingController::class, 'index'])
            ->name('subject-offerings.index');

        /*
        |--------------------------------------------------------------------------
        | Subject Offerings — Printable Class List
        |--------------------------------------------------------------------------
        |
        | Same viewers as the Index above — a partial class list for
        | posting before enrollment. No Faculty/Room/Time is shown or
        | required, so this is safe for the same role group that can
        | already see the Index.
        */

        Route::get('subject-offerings/print', [SubjectOfferingController::class, 'print'])
            ->name('subject-offerings.print');

        /*
        |--------------------------------------------------------------------------
        | Subject Offerings — Inline Faculty / Preferred Room
        |--------------------------------------------------------------------------
        |
        | Lets Admin/Registrar assign Faculty (a real Teaching
        | Assignment, same record as the Faculty Loading page) and set
        | a Preferred Room (the same room_subject_offering pivot the
        | Rooms "Manage Subjects" page writes to) directly from the
        | Subject Offerings table, without navigating away. Sits in
        | this route group (view-only tier) so Dean/Assistant Dean/OIC
        | never 403 just loading this page, but each method's own
        | abort_unless() keeps the actual write scoped to Admin/
        | Registrar, matching destroy()/bulkUpdateWeeklyHours() above.
        */

        Route::post('subject-offerings/{subjectOffering}/faculty', [SubjectOfferingController::class, 'assignFaculty'])
            ->name('subject-offerings.assign-faculty');

        Route::put('subject-offerings/{subjectOffering}/preferred-room', [SubjectOfferingController::class, 'setPreferredRoom'])
            ->name('subject-offerings.set-preferred-room');

        /*
        |--------------------------------------------------------------------------
        | Faculty Loading (Teaching Assignments)
        |--------------------------------------------------------------------------
        |
        | Assigns which faculty member teaches each Subject Offering
        | for the active academic term. This is NOT the final room/time
        | schedule — Subject Offerings stay unscheduled here; the
        | Greedy Scheduler (Master Grid) is what assigns room and time
        | slots, checking conflicts at that stage.
        |
        | Only index/store/destroy are registered — this module has no
        | standalone create/edit pages. Assigning happens via the
        | "Assign Subject" modal on the index page (POST straight to
        | store); removing a load is a destroy from the same page.
        | Faculty Subject "qualification" no longer exists anywhere in
        | this system — eligibility is decided purely by Faculty Scope
        | + Department + Subject Category (see
        | TeachingAssignmentService).
        |
        */

        Route::resource('teaching-assignments', TeachingAssignmentController::class)
            ->only(['index', 'store', 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Faculty Load Overload
        |--------------------------------------------------------------------------
        |
        | Request to raise ONE faculty member's effective teaching cap
        | above their normal max_units — for short-staffed departments
        | (e.g. CCS) that don't have enough faculty to keep everyone
        | under the standard 24-unit ceiling. Anyone who can manage
        | Faculty Loading can submit a request here; Admin/Registrar
        | requests auto-approve immediately, Dean/Assistant Dean/OIC
        | requests land as pending — see FacultyLoadOverloadService.
        | Approving/declining a pending request is Admin/Registrar
        | only, registered separately below alongside the other
        | Admin|Registrar-only scheduling actions.
        */

        Route::post('faculty-load-overloads', [FacultyLoadOverloadController::class, 'store'])
            ->name('faculty-load-overloads.store');

        // Dismiss/mark-read for the "your request was reviewed"
        // notification — any of this group's roles may dismiss their
        // OWN notification (see markNotificationRead()'s scoping).
        Route::post('faculty-load-overloads/notifications/{notification}/read', [FacultyLoadOverloadController::class, 'markNotificationRead'])
            ->name('faculty-load-overloads.notifications.read');

        Route::post('faculty-load-overloads/notifications/read-all', [FacultyLoadOverloadController::class, 'markAllNotificationsRead'])
            ->name('faculty-load-overloads.notifications.read-all');

        /*
        |--------------------------------------------------------------------------
        | Master Grid Scheduling Workspace
        |--------------------------------------------------------------------------
        |
        | Read-only visual workspace for the active Academic Term —
        | timetable + Subject/Room sidebars. index() is viewable by
        | everyone in this role group (Admin/Registrar/Dean/Assistant
        | Dean/OIC all have a stake in seeing the scheduling
        | workspace).
        |
        */

        Route::get('master-grid', [MasterGridController::class, 'index'])
            ->name('master-grid.index');

        /*
        |--------------------------------------------------------------------------
        | Master Grid — Generate Schedule (Greedy Scheduling Algorithm)
        |--------------------------------------------------------------------------
        |
        | Preview-only endpoint: runs GreedyScheduleService for one
        | Department + Program + [Specialization] + Year Level +
        | Section and returns a draft schedule as JSON. It does NOT
        | write to the database — see GreedyScheduleService's docblock.
        | Restricted to Admin/Registrar only, unlike index() above,
        | per spec ("When the Registrar or Admin clicks Generate
        | Schedule..."). MasterGridController::middleware() also
        | double-checks this same restriction on the controller side,
        | so a direct hit still 403s even if this route grouping is
        | ever rearranged later.
        |
        */

        Route::middleware('role:Admin|Registrar')->group(function () {

            // Faculty Load Overload — review queue. A Dean/Assistant
            // Dean/OIC hitting these directly still 403s here, even
            // though they can reach faculty-load-overloads.store above.
            Route::post('faculty-load-overloads/{facultyLoadOverload}/approve', [FacultyLoadOverloadController::class, 'approve'])
                ->name('faculty-load-overloads.approve');

            Route::post('faculty-load-overloads/{facultyLoadOverload}/decline', [FacultyLoadOverloadController::class, 'decline'])
                ->name('faculty-load-overloads.decline');

            // Generate Schedule — Step 2 (Session Settings). GET fetches
            // the section's Subject Offerings + eligible faculty/rooms
            // for the editing table; PUT persists meetings/week and any
            // preferred faculty/room overrides just before Generate runs.
            Route::get('master-grid/session-settings', [MasterGridController::class, 'sessionSettings'])
                ->name('master-grid.session-settings');

            Route::put('master-grid/session-settings', [MasterGridController::class, 'updateSessionSettings'])
                ->name('master-grid.session-settings.update');

            Route::post('master-grid/generate', [MasterGridController::class, 'generate'])
                ->name('master-grid.generate');

            // Interactive Schedule Review (Phase 2): validate-block runs
            // on every field change inside the Edit Schedule modal; save
            // re-validates the whole preview and, only if clean, inserts
            // every block into `schedules` in one transaction. Both act
            // purely on the in-memory preview the client already holds.
            Route::post('master-grid/validate-block', [MasterGridController::class, 'validateBlock'])
                ->name('master-grid.validate-block');

            Route::post('master-grid/save', [MasterGridController::class, 'save'])
                ->name('master-grid.save');

            // Removes an already-committed Schedule block (all meeting-
            // day rows for the subject offering) from the Master Grid,
            // sending it back to "unscheduled" — the Faculty Loading
            // assignment (teaching_assignments) is deliberately left
            // untouched, since removing a TIME/ROOM placement is not
            // the same decision as un-assigning the faculty member. See
            // MasterGridController::removeSchedule().
            Route::delete('master-grid/schedule', [MasterGridController::class, 'removeSchedule'])
                ->name('master-grid.remove-schedule');

        });

        /*
        |--------------------------------------------------------------------------
        | Block Schedule
        |--------------------------------------------------------------------------
        |
        | Level 0 landing (block-schedule.landing) splits into two
        | folders: Section Schedule (the original Department -> Block
        | -> weekly-schedule drill-down, unchanged below, just moved
        | under /block-schedule/sections) and Faculty Schedule
        | (block-schedule.faculty — scaffolded placeholder only, no
        | drill-down behind it yet). Same viewers as Master Grid —
        | Admin/Registrar/Dean/Assistant Dean/OIC all have a stake in
        | seeing it, and BlockScheduleController scopes Dean/OIC to
        | their own department the same way Faculty Loading and
        | Master Grid already do.
        |
        */

        Route::get('block-schedule', [BlockScheduleController::class, 'landing'])
            ->name('block-schedule.landing');

        Route::get('block-schedule/sections', [BlockScheduleController::class, 'index'])
            ->name('block-schedule.index');

        Route::get('block-schedule/sections/{department}', [BlockScheduleController::class, 'sections'])
            ->name('block-schedule.sections');

        // Printable Class List for the WHOLE department — every Block
        // in one document, grouped by Block, rather than one Block per
        // page. Must stay above the {section} wildcard below, same
        // reasoning as block-schedule/faculty/general further down:
        // otherwise Laravel tries (and fails) to route-model-bind
        // "print" as a Section ID. See
        // BlockScheduleController::printSections().
        Route::get('block-schedule/sections/{department}/print', [BlockScheduleController::class, 'printSections'])
            ->name('block-schedule.sections.print');

        Route::get('block-schedule/sections/{department}/{section}', [BlockScheduleController::class, 'show'])
            ->name('block-schedule.show');

        // Faculty Schedule — Department -> Faculty -> weekly schedule,
        // mirroring Section Schedule above but reading off
        // TeachingAssignment instead of Section. See
        // BlockScheduleController::facultyIndex()/facultyList()/
        // facultyShow().
        Route::get('block-schedule/faculty', [BlockScheduleController::class, 'facultyIndex'])
            ->name('block-schedule.faculty');

        // General Education (department_id = null) faculty get their
        // own folder rather than any real Department — these routes
        // MUST stay above the {department} wildcard below, or Laravel
        // will try (and fail) to route-model-bind "general" as a
        // Department ID. See BlockScheduleController::facultyGeneralList()/
        // facultyGeneralShow().
        Route::get('block-schedule/faculty/general', [BlockScheduleController::class, 'facultyGeneralList'])
            ->name('block-schedule.faculty.general');

        // Must stay above general/{faculty} below, same reasoning as
        // every other literal-vs-wildcard route in this file — see
        // BlockScheduleController::printFacultyGeneral().
        Route::get('block-schedule/faculty/general/print', [BlockScheduleController::class, 'printFacultyGeneral'])
            ->name('block-schedule.faculty.general.print');

        Route::get('block-schedule/faculty/general/{faculty}', [BlockScheduleController::class, 'facultyGeneralShow'])
            ->name('block-schedule.faculty.general.show');

        Route::get('block-schedule/faculty/{department}', [BlockScheduleController::class, 'facultyList'])
            ->name('block-schedule.faculty.list');

        // Must stay above {department}/{faculty} below — see
        // BlockScheduleController::printFacultyList().
        Route::get('block-schedule/faculty/{department}/print', [BlockScheduleController::class, 'printFacultyList'])
            ->name('block-schedule.faculty.list.print');

        Route::get('block-schedule/faculty/{department}/{faculty}', [BlockScheduleController::class, 'facultyShow'])
            ->name('block-schedule.faculty.show');

        // Future Modules
        // Route::resource('schedules', ScheduleController::class);

    });

});

/*
|--------------------------------------------------------------------------
| Profile
|--------------------------------------------------------------------------
|
| Every authenticated user (regardless of role) can manage their own
| account — these were missing from the route file, which caused Ziggy
| to throw on any page rendering AuthenticatedLayout.vue (it always
| calls route('profile.edit') and route('logout') in the nav dropdown).
|
*/

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';