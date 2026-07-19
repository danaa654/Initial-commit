<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use App\Services\RoomCapacityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only data aggregator for the Master Grid Scheduling Workspace.
 *
 * IMPORTANT: This service does NOT generate, save, or mutate any
 * schedule. It only assembles what already exists (Academic Term,
 * Rooms, Subject Offerings, Faculty Loading, Programs) into the shape
 * the Vue workspace needs to render itself. The Greedy Scheduling
 * Algorithm itself lives in GreedyScheduleService — see
 * MasterGridController::generate() for the preview endpoint that
 * calls it.
 */
class MasterGridDataService
{
    public function __construct(
        private readonly RoomCapacityService $capacity
    ) {
    }

    /**
     * Everything the Master Grid page needs, keyed for direct use as
     * Inertia props.
     *
     * $term is whatever MasterGridController resolved via
     * SchedulingWorkspaceService::getTermForUser() — the Planning
     * Academic Term for Admin/Registrar, or the Active Academic Term
     * for Dean/Assistant Dean/OIC. This method itself has no opinion
     * on which term that should be; it only assembles data for
     * whichever term it's handed. The 'activeTerm' prop key is kept
     * as-is (rather than renamed) so the existing Vue workspace
     * doesn't need to change — it's simply "the term this workspace
     * is currently showing," not necessarily the literal Active term.
     *
     * $departmentId scopes Subject Offerings (and their Scheduled/
     * Completed/Archived counterparts) to a single department — null
     * for Admin/Registrar/Assistant Dean, who see every department;
     * the Dean/OIC's own department_id otherwise. Mirrors the exact
     * same "own department + General Education" rule
     * TeachingAssignmentController::index() already applies to
     * Faculty Loading — Master Grid had no such scoping at all before
     * this, so a CCS OIC could see every other college's Subject
     * Offerings in the sidebar. Rooms are intentionally left
     * unscoped: a Dean/OIC still needs to see every physical room on
     * campus (including shared/General ones) to actually schedule
     * into, the same way the Room Sidebar's "Allowed" column already
     * shows non-CCS programs on shared rooms without that being a
     * data leak — it's the room's own allowance list, not another
     * college's private data.
     */
    public function build(?AcademicTerm $term, ?int $departmentId = null): array
    {
        $activeTerm = $term;

        if (! $activeTerm) {
            return [
                'activeTerm' => null,
                'subjectOfferings' => [],
                'scheduledOfferings' => [],
                'rooms' => [],
                'departments' => [],
                'programs' => [],
                'specializations' => [],
                'faculties' => [],
                'savedSchedules' => [],
                'collegeColors' => $this->collegeColorMap(),
            ];
        }

        $programDepartmentMap = $this->programDepartmentMap();
        $preferredRoomByOffering = $this->preferredRoomByOffering($activeTerm->id);

        // "Scheduled" for this split means Scheduled/Completed/Archived
        // — same list presentOffering()'s 'is_scheduled' flag already
        // uses below, kept as one constant so the two can never drift
        // apart.
        $scheduledStatuses = [
            SubjectOffering::STATUS_SCHEDULED,
            SubjectOffering::STATUS_COMPLETED,
            SubjectOffering::STATUS_ARCHIVED,
        ];

        // Fetched ONCE and split in memory below, rather than two
        // separate SubjectOffering::with([...])->get() calls (the old
        // unscheduledOfferings()/scheduledOfferings() shape) — those
        // ran the exact same 5-relation eager-loaded query twice over
        // the same ~200+ rows just to throw half of each result away.
        // Also eager-loads `schedule` and `preferredByRooms` (on top
        // of the relations presentOffering() itself needs) so
        // SubjectOffering::getOverallStatusAttribute()/room_status
        // resolve purely from already-loaded relations instead of
        // firing a query per offering — see SubjectOffering.php.
        [$scheduledOfferings, $unscheduledOfferings] = $this
            ->offeringsForTerm($activeTerm->id, $departmentId)
            ->partition(fn (SubjectOffering $offering) => in_array($offering->overall_status, $scheduledStatuses, true));

        // Pre-fetched ONCE for every schedulable room, grouped by
        // room_id, instead of presentRoom() running its own
        // Schedule::where('room_id', ...)->get() per room inside the
        // ->map() below — that was one query per room (dozens of
        // rooms) where one grouped query does the same job.
        $schedulesByRoom = Schema::hasTable('schedules')
            ? Schedule::where('academic_term_id', $activeTerm->id)
                ->get(['room_id', 'start_minutes', 'end_minutes'])
                ->groupBy('room_id')
            : collect();

        return [

            'activeTerm' => $activeTerm,

            // Unscheduled Subject Offerings for the active term only —
            // "unscheduled" here means overall_status hasn't reached
            // Scheduled/Completed/Archived yet (see
            // SubjectOffering::getOverallStatusAttribute()). Once the
            // Greedy Scheduler's future Save step writes real schedule
            // rows, those offerings naturally drop out of this list on
            // their own, no extra flag needed.
            'subjectOfferings' => $unscheduledOfferings
                ->map(fn (SubjectOffering $offering) => $this->presentOffering(
                    $offering,
                    $programDepartmentMap,
                    $preferredRoomByOffering
                ))
                ->values(),

            // The flip side of subjectOfferings — every offering that
            // WAS excluded above for being Scheduled/Completed/Archived.
            // Not shown by default: the Subject Sidebar is a "drag-in"
            // tray of work still to be placed, and a Scheduled offering
            // has nothing left to drag. This exists purely so the
            // sidebar's optional "Show scheduled too" toggle has
            // something to reveal — e.g. after placing BSIT-4A, a
            // Registrar can flip the toggle to confirm it really is
            // done rather than having to jump over to Faculty Loading
            // to check. Shares presentOffering() so the card shape
            // (and therefore faculty_assigned, preferred_room_code,
            // etc.) is identical either way.
            'scheduledOfferings' => $scheduledOfferings
                ->map(fn (SubjectOffering $offering) => $this->presentOffering(
                    $offering,
                    $programDepartmentMap,
                    $preferredRoomByOffering
                ))
                ->values(),

            'rooms' => Room::schedulable()
                ->with('roomGroups')
                ->orderBy('room_code')
                ->get()
                ->map(fn (Room $room) => $this->presentRoom($room, $activeTerm, $programDepartmentMap, $schedulesByRoom))
                ->values(),

            // Scoped the same way offeringsForTerm() above already is:
            // Dean/OIC only ever see their own department here (they
            // can now actually Generate/drag-and-drop, not just view —
            // showing every college in this dropdown when 3 of them
            // are off-limits would just be a 403 waiting to happen
            // after they pick one). Admin/Registrar/Assistant Dean
            // pass $departmentId === null and see every department, as
            // before.
            'departments' => Department::where('active', true)
                ->when($departmentId, fn ($q) => $q->where('id', $departmentId))
                ->orderBy('name')
                ->get(['id', 'name', 'abbreviation']),

            'programs' => Program::with('department')
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Program $program) => [
                    'id' => $program->id,
                    'code' => $program->code,
                    'name' => $program->name,
                    'department_id' => $program->department_id,
                ])
                ->values(),

            'specializations' => Schema::hasTable('specializations')
                ? DB::table('specializations')
                    ->where('active', true)
                    ->orderBy('name')
                    ->get(['id', 'program_id', 'code', 'name'])
                : [],

            // For the Edit Schedule modal's Faculty picker (Phase 2 —
            // Interactive Schedule Review). Kept intentionally light —
            // just what the modal and the recommendation service need
            // to display/sort candidates; TeachingAssignmentService and
            // ScheduleRecommendationService remain the source of truth
            // for actual eligibility rules.
            'faculties' => Faculty::where('status', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'department_id', 'faculty_scope', 'max_units']),

            // Already-saved Schedule rows for the active term, if any —
            // lets the Master Grid render committed schedules (from a
            // previous Save Schedule run) even before a new Generate
            // is run in this session.
            'savedSchedules' => Schema::hasTable('schedules')
                ? Schedule::with(['subjectOffering.subject', 'subjectOffering.section', 'subjectOffering.program', 'subjectOffering.teachingAssignment', 'subjectOffering.preferredByRooms', 'faculty', 'room'])
                    ->forTerm($activeTerm->id)
                    ->get()
                    ->map(fn (Schedule $s) => [
                        'subject_offering_id' => $s->subject_offering_id,
                        'subject_code' => $s->subjectOffering?->subject?->subject_code,
                        'descriptive_title' => $s->subjectOffering?->subject?->descriptive_title,
                        'section_code' => $s->subjectOffering?->section?->section_code,
                        'section_id' => $s->subjectOffering?->section_id,
                        'is_irregular' => (bool) $s->subjectOffering?->section?->is_irregular,
                        'year_level' => $s->subjectOffering?->year_level,
                        'program_code' => $s->subjectOffering?->program?->code,
                        'units' => $s->subjectOffering?->units,
                        'hours' => $s->subjectOffering?->hours,
                        'meetings_per_week' => $s->subjectOffering?->meetings_per_week ?: SubjectOffering::DEFAULT_MEETINGS_PER_WEEK,
                        'room_type' => $s->subjectOffering?->room_type,
                        'classification' => $s->subjectOffering?->classification,
                        'faculty_id' => $s->faculty_id,
                        'faculty_name' => $s->faculty?->full_name,
                        // Whether the offering's CURRENT Faculty/Room
                        // preference (Subject Offerings) was set via
                        // Override Eligibility — see presentOffering()'s
                        // faculty_override/room_override for the same
                        // idea on the unscheduled side. Re-opening an
                        // already-placed block through EditScheduleModal
                        // seeds its checkboxes from this the same way.
                        'faculty_override' => (bool) ($s->subjectOffering?->teachingAssignment?->is_override ?? false),
                        'room_override' => (bool) ($s->subjectOffering?->preferredByRooms?->first()?->pivot?->is_override ?? false),
                        'room_id' => $s->room_id,
                        'room_code' => $s->room?->room_code,
                        'day' => $s->day,
                        'start_minutes' => $s->start_minutes,
                        'end_minutes' => $s->end_minutes,
                        'college_code' => $programDepartmentMap[$s->subjectOffering?->program?->code] ?? 'General',
                        'status' => 'saved',
                    ])
                    ->values()
                : [],

            // Centralized color-mapping source of truth — the frontend
            // (SubjectSidebar, RoomSidebar, Timetable events) all read
            // from this single object rather than each picking their
            // own colors. See resources/js/Utils/collegeColors.js for
            // the client-side mirror of this same map.
            'collegeColors' => $this->collegeColorMap(),

        ];
    }

    /**
     * Every Subject Offering for the active term (both unscheduled
     * and already-scheduled) in ONE query — replaces the old
     * unscheduledOfferings()/scheduledOfferings() pair, which ran this
     * exact same eager-loaded query twice and only differed in which
     * half of the results they kept. build() now fetches once here
     * and partitions the single collection in memory instead.
     *
     * Eager-loads `schedule` and `preferredByRooms` in addition to
     * what presentOffering() itself needs — those two let
     * SubjectOffering::getOverallStatusAttribute()/room_status read
     * already-loaded relations instead of firing a raw query per
     * offering (see SubjectOffering.php's hasScheduleAssigned()/
     * getRoomStatusAttribute()). Without them, every offering here
     * would cost up to 3 extra queries just to answer "is this
     * scheduled / does a room prefer this," on top of whatever this
     * query itself costs — the single biggest source of query bloat
     * on this page before this fix.
     *
     * $departmentId scopes to a single department (plus General
     * Education, whose Subject Offerings carry a program with no
     * department at all) when given — see build()'s doc comment for
     * why this exists and why Rooms don't get the same treatment.
     */
    private function offeringsForTerm(int $academicTermId, ?int $departmentId = null)
    {
        return SubjectOffering::with([
                'subject',
                'section',
                'program.department',
                'academicTerm',
                'teachingAssignment.faculty',
                'schedule',
                'preferredByRooms',
                // Needed so presentOffering() can expose specialization_id
                // without an extra query per offering — see the Generate
                // Schedule modal's Section dropdown, which must filter
                // Sections by Specialization (e.g. BSCRIM's FI/FB/LD/QD)
                // and previously had no way to do that at all.
                'curriculum',
            ])
            ->forTerm($academicTermId)
            ->when($departmentId, fn ($query) => $query->whereHas(
                'program',
                fn ($inner) => $inner->whereNull('department_id')->orWhere('department_id', $departmentId)
            ))
            ->get();
    }

    /**
     * Shapes a single Subject Offering into exactly the fields the
     * Subject Card needs (per the Master Grid spec): code, title,
     * program, year, section, hours, faculty assigned, preferred room,
     * preferred faculty, classification, room type.
     *
     * program_id / department_id / section_id are included alongside
     * their display codes (program_code, section_code) so the Generate
     * Schedule modal can filter its Department -> Program -> Year ->
     * Section cascade client-side from this same array, and so the
     * payload it POSTs to /master-grid/generate carries real IDs
     * instead of re-deriving them from display strings.
     */
    private function presentOffering(
        SubjectOffering $offering,
        array $programDepartmentMap,
        array $preferredRoomByOffering
    ): array {
        $collegeCode = $offering->program?->department?->abbreviation
            ?? $programDepartmentMap[$offering->program?->code] ?? null
            ?? 'General';

        // Read once and reused below for both keys — overall_status is
        // now cached per-instance on the model (see SubjectOffering's
        // $overallStatusCache) so this was never technically a second
        // query, but reading it into a local variable keeps that
        // guarantee explicit here rather than relying on the model's
        // internal caching to make two calls cheap.
        $overallStatus = $offering->overall_status;

        return [
            'id' => $offering->id,
            // Alias of 'id', under the same key name every schedule
            // block elsewhere in the Master Grid (scheduledEvents,
            // generatePreview blocks, EditScheduleModal's draft) uses.
            // Needed so a card dragged from this sidebar can be handed
            // straight to openEditModal() as a synthetic block without
            // Index.vue having to remember this one endpoint names the
            // id field differently from everywhere else.
            'subject_offering_id' => $offering->id,
            'academic_term_id' => $offering->academic_term_id,
            'edp_code' => $offering->edp_code,
            'subject_code' => $offering->subject?->subject_code,
            'descriptive_title' => $offering->subject?->descriptive_title,
            'program_id' => $offering->program_id,
            'program_code' => $offering->program?->code,
            'department_id' => $offering->program?->department_id,
            // Which Specialization this offering's Curriculum belongs to
            // (null for Programs with no majors, e.g. BSIT/BSHM/BSTM).
            // The Generate Schedule modal needs this to filter its
            // Section dropdown to only Sections under the Specialization
            // actually selected — without it, e.g. BSCRIM's four
            // Specializations (FI/FB/LD/QD) all shared one merged Section
            // list, making it easy to pick a Section that didn't match
            // the chosen Specialization at all.
            'specialization_id' => $offering->curriculum?->specialization_id,
            'year_level' => $offering->year_level,
            'section_id' => $offering->section_id,
            'section_code' => $offering->section?->section_code,
            'is_irregular' => (bool) $offering->section?->is_irregular,
            'hours' => $offering->hours,
            // Needed so a subject dragged straight onto the grid can
            // compute its own hours-per-meeting client-side (see
            // EditScheduleModal's durationMinutes) the exact same way
            // Session Settings and the Greedy Scheduler already do —
            // without this the drop flow would have no idea how many
            // times a week the class is supposed to meet.
            'meetings_per_week' => $offering->meetings_per_week ?: SubjectOffering::DEFAULT_MEETINGS_PER_WEEK,
            'units' => $offering->units,
            'classification' => $offering->classification,
            'room_type' => $offering->room_type,
            'faculty_assigned' => $offering->teachingAssignment?->faculty?->full_name,
            // Numeric id alongside the display name above — the name
            // alone is only good for reading, EditScheduleModal's
            // faculty dropdown (draft.faculty_id) needs the actual id
            // to pre-select the right option when a subject with an
            // existing Faculty Loading assignment is dragged straight
            // onto the grid.
            'faculty_id' => $offering->teachingAssignment?->faculty_id,
            // Whether the Faculty above was assigned through Subject
            // Offerings' "Override Eligibility" checkbox — lets
            // EditScheduleModal start its own Faculty Override
            // checkbox pre-checked instead of the person having to
            // re-declare an exception that was already authorized
            // once (see SubjectOfferingController::assignFaculty()).
            'faculty_override' => (bool) ($offering->teachingAssignment?->is_override ?? false),
            'preferred_room_code' => $preferredRoomByOffering[$offering->id]['room_code'] ?? null,
            // Same idea as faculty_override, for the Room field — set
            // when the Preferred Room above was picked through Subject
            // Offerings' Override Eligibility checkbox (see
            // SubjectOfferingController::setPreferredRoom()).
            'room_override' => $preferredRoomByOffering[$offering->id]['is_override'] ?? false,
            'overall_status' => $overallStatus,
            'is_scheduled' => in_array($overallStatus, [
                SubjectOffering::STATUS_SCHEDULED,
                SubjectOffering::STATUS_COMPLETED,
                SubjectOffering::STATUS_ARCHIVED,
            ], true),
            'college_code' => $collegeCode,
        ];
    }

    /**
     * Shapes a single Room into exactly the fields the Room Card needs:
     * name, building, floor, capacity, room type, programs allowed,
     * how many classes are ACTUALLY scheduled there, hours used, hours
     * remaining.
     *
     * IMPORTANT: Hours Used/Remaining/Scheduled Count are read from the
     * real `schedules` table (room_id + academic_term_id), i.e. what
     * the Greedy Scheduler + Save Schedule has actually committed — NOT
     * from Room::preferredSubjectOfferings(), which is only a
     * pre-scheduling preference (Manage Subjects) with no day/time at
     * all. Those are two different concepts: a preference says "this
     * room WOULD like to host this offering"; a schedule row says
     * "this offering IS meeting in this room, on this day, at this
     * time." The Master Grid workspace — including this Room
     * Sidebar — is about the latter, so it must always read the
     * latter. Falls back to 0 when the schedules table doesn't exist
     * yet (fresh install) rather than erroring.
     */
    private function presentRoom(Room $room, AcademicTerm $academicTerm, array $programDepartmentMap, Collection $schedulesByRoom): array
    {
        // Pulled from the map build() fetched ONCE for every room
        // (grouped by room_id) — replaces a per-room
        // Schedule::where('room_id', ...)->get() query that used to
        // run inside this method, once for every room in the ->map()
        // loop that calls presentRoom().
        $scheduledRows = $schedulesByRoom->get($room->id, collect());

        $scheduledMinutes = $scheduledRows->sum(
            fn ($row) => max(0, (int) $row->end_minutes - (int) $row->start_minutes)
        );

        $hoursUsed = (int) round($scheduledMinutes / 60);

        // Was a flat Room::WEEKLY_CAPACITY_HOURS (always 60) regardless
        // of which term was actually being viewed — Rooms/Index and the
        // Manage Subjects modal had already moved to the real,
        // term-derived number (School Hours minus Lunch Break, times
        // Working Days — see AcademicTerm::getWeeklyCapacityHoursAttribute()
        // and RoomCapacityService), so Master Grid's Room Sidebar was
        // quietly showing a different, wrong ceiling for every term
        // whose actual school hours didn't happen to add up to 60. This
        // is the same call RoomController now makes, so all three
        // surfaces agree on one number per term.
        $capacity = $this->capacity->weeklyCapacityHoursFor($academicTerm);

        return [
            'id' => $room->id,
            'room_code' => $room->room_code,
            'building' => $room->building,
            'floor' => $room->floor,
            'capacity' => $room->capacity,
            'room_type' => $room->room_type,
            'room_group_codes' => $room->room_group_codes,
            'scheduled_count' => $scheduledRows->count(),
            'hours_used' => $hoursUsed,
            'hours_remaining' => max(0, $capacity - $hoursUsed),
            'utilization_percent' => $capacity > 0 ? min(100, (int) round(($hoursUsed / $capacity) * 100)) : 0,
            'weekly_capacity_hours' => $capacity,
            'college_code' => $this->resolveCollegeForCodes($room->room_group_codes, $programDepartmentMap),
        ];
    }

    /**
     * program code (e.g. "BSIT") => owning department abbreviation
     * (e.g. "CCS"). Built once per request and threaded through both
     * Offering and Room presentation so college color-coding always
     * comes from one source.
     */
    private function programDepartmentMap(): array
    {
        return Program::with('department')
            ->get(['id', 'code', 'department_id'])
            ->filter(fn (Program $program) => $program->department)
            ->mapWithKeys(fn (Program $program) => [$program->code => $program->department->abbreviation])
            ->all();
    }

    /**
     * A room's room_group_codes are program codes (or the literal
     * "General"). Resolve them to a single college color bucket:
     *   - no codes, or only "General"          => "General" (gray)
     *   - every resolvable code is one college  => that college
     *   - codes span more than one college      => "Shared" (gray)
     */
    private function resolveCollegeForCodes(array $codes, array $programDepartmentMap): string
    {
        $colleges = collect($codes)
            ->reject(fn ($code) => $code === 'General')
            ->map(fn ($code) => $programDepartmentMap[$code] ?? null)
            ->filter()
            ->unique();

        if ($colleges->isEmpty()) {
            return 'General';
        }

        return $colleges->count() === 1 ? $colleges->first() : 'Shared';
    }

    /**
     * subject_offering_id => ['room_code' => ..., 'is_override' => bool],
     * sourced from the room_subject_offering pivot (Room::
     * preferredSubjectOfferings()). A room preference is a
     * many-to-many, but the Subject Card only has room for one
     * "Preferred Room" label — first match wins. is_override reflects
     * whether that preference was set through Subject Offerings'
     * "Override Eligibility" checkbox (see SubjectOfferingController::
     * setPreferredRoom()) — Master Grid reads it in presentOffering()
     * below so a Subject Offerings override doesn't have to be
     * re-declared from scratch when the same offering reaches
     * EditScheduleModal.
     */
    private function preferredRoomByOffering(int $academicTermId): array
    {
        if (! Schema::hasTable('room_subject_offering')) {
            return [];
        }

        return DB::table('room_subject_offering')
            ->join('rooms', 'rooms.id', '=', 'room_subject_offering.room_id')
            ->join('subject_offerings', 'subject_offerings.id', '=', 'room_subject_offering.subject_offering_id')
            ->where('subject_offerings.academic_term_id', $academicTermId)
            ->orderBy('room_subject_offering.id')
            ->get(['room_subject_offering.subject_offering_id', 'rooms.room_code', 'room_subject_offering.is_override'])
            ->groupBy('subject_offering_id')
            ->map(fn ($rows) => [
                'room_code' => $rows->first()->room_code,
                'is_override' => (bool) $rows->first()->is_override,
            ])
            ->all();
    }

    /**
     * Single source of truth for College => color. Do NOT scatter
     * per-component color choices — every Vue component that needs a
     * college color reads from this same map (mirrored client-side in
     * resources/js/Utils/collegeColors.js). Update BOTH places if this
     * ever changes.
     */
    private function collegeColorMap(): array
    {
        return [
            'CCS' => 'yellow',
            'CRIM' => 'purple',
            'CTE' => 'blue',
            'SHTM' => 'orange',
            'CBA' => 'green',
            'General' => 'gray',
            'Shared' => 'gray',
        ];
    }
}