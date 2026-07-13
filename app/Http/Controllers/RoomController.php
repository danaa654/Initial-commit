<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Room;
use App\Models\SubjectOffering;
use App\Models\SubjectRoomGroup;
use App\Services\RoomCapacityService;
use App\Services\SchedulingWorkspaceService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RoomController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SchedulingWorkspaceService $workspace,
        private readonly RoomCapacityService $capacity
    ) {
    }

    /**
     * Controller Middleware
     */
    public static function middleware(): array
    {
        return [

            // Baseline: everyone allowed to touch Rooms at all —
            // Dean/Assistant Dean/OIC need this for index() (viewing
            // the master list) and manageSubjects()/syncPreferredSubjects()
            // (setting THIS TERM's room preferences for their own
            // department's offerings).
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

            // Narrower: the Room's actual master-data record — code,
            // type, building, floor, capacity, available programs — is
            // Admin/Registrar-owned inventory, the same way Academic
            // Terms are (see AcademicTermController). Dean/Assistant
            // Dean/OIC can view rooms and manage preferences for them,
            // but must never create, edit, or delete the room record
            // itself.
            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasAnyRole([
                        'Admin',
                        'Registrar',
                    ]),
                    403,
                    'Unauthorized. Only Admin and Registrar can manage room records.'
                );

                return $next($request);

            }, only: ['create', 'store', 'edit', 'update', 'destroy']),

        ];
    }

    /**
     * Display all rooms.
     *
     * Rooms are still a small master list (no pagination), but now support
     * search + three filters: Room Type, Floor, and Available Programs.
     * The Programs filter matches the exact program selected — a room
     * flagged "General" only shows up under the General filter, not under
     * every specific program too (see Room::scopeForRoomGroup()).
     */
    public function index(Request $request)
    {
        // Admin/Registrar see Room utilization for the Planning
        // Academic Term (so they can lay out next semester's room
        // assignments ahead of time); Dean/Assistant Dean/OIC always
        // see the Active Academic Term — see
        // SchedulingWorkspaceService::getTermForUser(). Kept as
        // $activeTerm below (rather than renamed) since it's still
        // "the one term this page currently cares about," just no
        // longer unconditionally the literal Active term.
        $activeTerm = $this->workspace->getTermForUser(auth()->user());

        $query = Room::with('roomGroups')->orderBy('room_code');

        // Total Preferred Hours AND count for the ACTIVE Academic Term
        // only — two aggregate queries (not one query per room) so the
        // list view can show utilization + subject count without an
        // N+1. Aliased so they land on the model as $room->preferred_hours
        // / $room->preferred_count; null (no active term, or nothing
        // preferred yet) is treated as 0 on the frontend.
        if ($activeTerm) {
            $query->withSum(['preferredSubjectOfferings as preferred_hours' => function ($query) use ($activeTerm) {
                $query->where('subject_offerings.academic_term_id', $activeTerm->id);
            }], 'hours');

            $query->withCount(['preferredSubjectOfferings as preferred_count' => function ($query) use ($activeTerm) {
                $query->where('subject_offerings.academic_term_id', $activeTerm->id);
            }]);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('room_code', 'like', "%{$search}%")
                    ->orWhere('building', 'like', "%{$search}%");
            });
        }

        if ($roomType = $request->input('room_type')) {
            $query->where('room_type', $roomType);
        }

        if ($floor = $request->input('floor')) {
            $query->where('floor', $floor);
        }

        if ($roomGroup = $request->input('room_group')) {
            $query->forRoomGroup($roomGroup);
        }

        $rooms = $query->get();

        // Real, committed load from the `schedules` table — the same
        // thing Master Grid's Room Sidebar already shows (see
        // MasterGridDataService::presentRoom()). Preferred Hours above
        // is only the pre-scheduling wishlist (room_subject_offering);
        // this is what the Greedy Scheduler has actually
        // generated/saved. Computed as one grouped query (not
        // withSum/withCount) since summing a *duration*
        // (end_minutes - start_minutes) isn't a plain column sum.
        // Kept as separate fields — scheduled_hours/scheduled_count —
        // rather than folded into preferred_hours, so the two
        // concepts never get confused on the frontend either.
        if ($activeTerm && Schema::hasTable('schedules')) {
            $scheduleTotals = DB::table('schedules')
                ->where('academic_term_id', $activeTerm->id)
                ->selectRaw('room_id, COUNT(*) as scheduled_count, SUM(end_minutes - start_minutes) as scheduled_minutes')
                ->groupBy('room_id')
                ->get()
                ->keyBy('room_id');

            $rooms->each(function (Room $room) use ($scheduleTotals) {
                $totals = $scheduleTotals->get($room->id);
                $room->scheduled_count = $totals ? (int) $totals->scheduled_count : 0;
                $room->scheduled_hours = $totals ? (int) round($totals->scheduled_minutes / 60) : 0;
            });
        } else {
            $rooms->each(function (Room $room) {
                $room->scheduled_count = 0;
                $room->scheduled_hours = 0;
            });
        }

        return Inertia::render('Rooms/Index', [

            'rooms' => $rooms,

            'roomGroupOptions' => $this->roomGroupOptions(),

            // Distinct floors actually in use, rather than a hardcoded
            // list — keeps the filter accurate if a new floor/wing is
            // ever added without a code change here.
            'floorOptions' => Room::query()
                ->whereNotNull('floor')
                ->distinct()
                ->orderBy('floor')
                ->pluck('floor'),

            'filters' => $request->only(['search', 'room_type', 'floor', 'room_group']),

            'weeklyCapacityHours' => $this->capacity->weeklyCapacityHoursFor($activeTerm),

            // Drives whether Rooms/Index shows "+ New Room" and the
            // per-row Edit/Delete buttons at all — computed here rather
            // than the frontend guessing at auth.user.roles, so there's
            // exactly one place (this flag) that has to agree with the
            // middleware above restricting create/store/edit/update/
            // destroy to Admin/Registrar. Manage Subjects is
            // deliberately NOT gated by this — Dean/Assistant Dean/OIC
            // still need it to set their department's room preferences.
            'canManageRooms' => auth()->user()->hasAnyRole(['Admin', 'Registrar']),

        ]);
    }

    /**
     * Show create form.
     */
    public function create()
    {
        return Inertia::render('Rooms/Create', [

            'roomGroupOptions' => $this->roomGroupOptions(),

        ]);
    }

    /**
     * Store room.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules($request));

        /*
        |--------------------------------------------------------------------------
        | Room Code
        |--------------------------------------------------------------------------
        */

        $validated['room_code'] = strtoupper(
            $validated['room_code']
        );

        /*
        |--------------------------------------------------------------------------
        | Available Programs
        |--------------------------------------------------------------------------
        |
        | room_groups isn't a column on rooms — pull it out before create()
        | and sync it into the room_group_room pivot afterward instead.
        |
        */

        $roomGroups = $validated['room_groups'];
        unset($validated['room_groups']);

        $room = Room::create($validated);

        $this->syncRoomGroups($room, $roomGroups);

        AuditLogService::log(
            action: 'created',
            module: 'Rooms',
            model: $room,
            description: "Created room {$room->room_code}",
            newValues: [
                'room_code' => $room->room_code,
                'room_type' => $validated['room_type'],
                'capacity' => $validated['capacity'],
                'room_groups' => $roomGroups,
            ],
        );

        return redirect()
            ->route('rooms.index')
            ->with('success', 'Room created successfully.');
    }

    /**
     * Show edit form.
     */
    public function edit(Room $room)
    {
        $room->load('roomGroups');

        return Inertia::render('Rooms/Edit', [

            'room' => $room,

            'roomGroupOptions' => $this->roomGroupOptions(),

        ]);
    }

    /**
     * Update room.
     */
    public function update(Request $request, Room $room)
    {
        $validated = $request->validate($this->rules($request, $room));

        /*
        |--------------------------------------------------------------------------
        | Room Code
        |--------------------------------------------------------------------------
        */

        $validated['room_code'] = strtoupper(
            $validated['room_code']
        );

        $roomGroups = $validated['room_groups'];
        unset($validated['room_groups']);

        $oldValues = [
            'room_code' => $room->room_code,
            'room_type' => $room->room_type,
            'capacity' => $room->capacity,
        ];

        $room->update($validated);

        $this->syncRoomGroups($room, $roomGroups);

        AuditLogService::log(
            action: 'updated',
            module: 'Rooms',
            model: $room,
            description: "Updated room {$oldValues['room_code']}",
            oldValues: $oldValues,
            newValues: [
                'room_code' => $room->room_code,
                'room_type' => $validated['room_type'],
                'capacity' => $validated['capacity'],
            ],
        );

        return redirect()
            ->route('rooms.index')
            ->with('success', 'Room updated successfully.');
    }

    /**
     * Delete room.
     */
    public function destroy(Room $room)
    {
        // A Room with real, committed Schedule rows (Master Grid's Save
        // Schedule step — see Room::schedules(), the hasMany to the
        // `schedules` table) is actively in use: deleting it out from
        // under those classes would orphan every one of them. This is
        // the same "protect committed data, don't just let it vanish"
        // rule AcademicTermController already applies to Academic Terms
        // carrying scheduling data — archive/reassign first, don't
        // delete. Preferred Subject Offerings (the pre-scheduling
        // wishlist) do NOT block deletion by themselves; only an actual
        // Schedule row does.
        if ($room->schedules()->exists()) {
            return redirect()
                ->route('rooms.index')
                ->with('error', "{$room->room_code} has classes already scheduled via Master Grid and cannot be deleted. Reassign or delete those schedules first.");
        }

        $roomCode = $room->room_code;

        try {
            $room->delete();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('rooms.index')
                ->with('error', "Unable to delete {$roomCode}. It still has related records that must be removed first.");
        }

        AuditLogService::log(
            action: 'deleted',
            module: 'Rooms',
            description: "Deleted room {$roomCode}",
            oldValues: ['room_code' => $roomCode],
            recordName: $roomCode,
        );

        return redirect()
            ->route('rooms.index')
            ->with('success', 'Room deleted successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Manage Subjects (Room Preferences)
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: nothing below this point creates, edits, or reasons about
    | a schedule. A "preferred" Subject Offering is stored as a plain
    | Room <-> SubjectOffering pivot row (room_subject_offering) with no
    | day/time/faculty fields at all. This is purely the input the future
    | Greedy Scheduler will read — the actual scheduling decision happens
    | in a different module.
    |
    */

    /**
     * Manage Subjects data for a single Room — returned as plain JSON,
     * not an Inertia page render. This is now purely the data source
     * for the Manage Subjects MODAL on Rooms/Index.vue: the Index page
     * fetches it via axios when a room's "Manage Subjects" button is
     * clicked, opens the modal client-side, and never navigates away
     * from Index at all — filters, scroll position, and which room's
     * modal is open all stay exactly as they were, since Index.vue
     * itself never re-renders.
     *
     * Same underlying query/annotation logic as before (is_preferred,
     * is_recommended, claimed_by_room_code) — only the response shape
     * changed, from Inertia::render() to response()->json().
     */
    public function manageSubjects(Room $room)
    {
        $room->load('roomGroups');

        // Same role-aware resolution as index() — Admin/Registrar
        // manage preferences for the Planning term; Dean/Assistant
        // Dean/OIC see the Active term's preferences only.
        $activeTerm = $this->workspace->getTermForUser(auth()->user());

        $offerings = collect();
        $scheduledHours = 0;
        $scheduledCount = 0;

        if ($activeTerm) {

            $preferredIds = $room->preferredSubjectOfferings()
                ->where('subject_offerings.academic_term_id', $activeTerm->id)
                ->pluck('subject_offerings.id');

            $baseOfferings = SubjectOffering::with([
                    'subject:id,subject_code,descriptive_title',
                    'subject.roomGroups',
                    'program:id,code',
                    'section:id,section_code',
                    // Specialization (e.g. BSCRIM's FB/LD/QD/FI) lives on
                    // the Curriculum, not the Offering or Section directly —
                    // see Curriculum::specialization(). Only used for the
                    // filter dropdown below; most offerings (programs with
                    // no specializations) will simply resolve this to null.
                    'curriculum.specialization:id,program_id,code,name',
                ])
                ->where('academic_term_id', $activeTerm->id)
                ->where('room_type', $room->room_type)
                ->orderBy('edp_code')
                ->get();

            $claimedByOtherRoom = DB::table('room_subject_offering')
                ->join('rooms', 'rooms.id', '=', 'room_subject_offering.room_id')
                ->whereIn('room_subject_offering.subject_offering_id', $baseOfferings->pluck('id'))
                ->where('room_subject_offering.room_id', '!=', $room->id)
                ->pluck('rooms.room_code', 'room_subject_offering.subject_offering_id');

            /*
            |--------------------------------------------------------------------------
            | Actual Schedule Data (Master Grid)
            |--------------------------------------------------------------------------
            |
            | Everything above this point is still purely about
            | PREFERENCES. This block is the connection to what the
            | Greedy Scheduler + Save Schedule has actually committed
            | for these offerings, so a Dean/Registrar opening Manage
            | Subjects isn't looking at a stale "0/60 hrs" while
            | Master Grid already shows real classes meeting in this
            | room. Defensive Schema::hasTable() check, same pattern
            | as SubjectOffering::getRoomStatusAttribute(), in case
            | this runs before the schedules table has ever migrated.
            */

            $scheduledHereByOffering = collect();
            $scheduledElsewhereByOffering = collect();

            if (Schema::hasTable('schedules')) {

                $scheduleRows = DB::table('schedules')
                    ->join('rooms', 'rooms.id', '=', 'schedules.room_id')
                    ->whereIn('schedules.subject_offering_id', $baseOfferings->pluck('id'))
                    ->where('schedules.academic_term_id', $activeTerm->id)
                    ->get([
                        'schedules.subject_offering_id',
                        'schedules.room_id',
                        'rooms.room_code',
                        'schedules.day',
                        'schedules.start_minutes',
                        'schedules.end_minutes',
                    ]);

                $scheduledHereByOffering = $scheduleRows
                    ->where('room_id', $room->id)
                    ->keyBy('subject_offering_id');

                $scheduledElsewhereByOffering = $scheduleRows
                    ->where('room_id', '!=', $room->id)
                    ->keyBy('subject_offering_id');

                $scheduledCount = $scheduledHereByOffering->count();
                $scheduledMinutes = $scheduledHereByOffering->sum(
                    fn ($row) => max(0, (int) $row->end_minutes - (int) $row->start_minutes)
                );
                $scheduledHours = (int) round($scheduledMinutes / 60);
            }

            $offerings = $baseOfferings
                ->map(function (SubjectOffering $offering) use (
                    $room,
                    $preferredIds,
                    $claimedByOtherRoom,
                    $scheduledHereByOffering,
                    $scheduledElsewhereByOffering
                ) {
                    $scheduledHere = $scheduledHereByOffering->get($offering->id);
                    $scheduledElsewhere = $scheduledElsewhereByOffering->get($offering->id);

                    return [
                        'id' => $offering->id,
                        'edp_code' => $offering->edp_code,
                        'subject_code' => $offering->subject?->subject_code,
                        'subject_title' => $offering->subject?->descriptive_title,
                        'program_code' => $offering->program?->code,
                        'year_level' => $offering->year_level,
                        'section_code' => $offering->section?->section_code,

                        // Null for every program that has no
                        // Specializations defined (BSIT, BSED, BSHM,
                        // BSTM today) — the modal only renders the
                        // Specialization filter when at least one
                        // visible offering actually has one.
                        'specialization_code' => $offering->curriculum?->specialization?->code,
                        'specialization_name' => $offering->curriculum?->specialization?->name,
                        'units' => $offering->units,
                        'hours' => $offering->hours,
                        'classification' => $offering->classification,
                        'room_type' => $offering->room_type,
                        'is_preferred' => $preferredIds->contains($offering->id),
                        'is_recommended' => $this->isDepartmentCompatible($offering, $room),
                        'claimed_by_room_code' => $claimedByOtherRoom->get($offering->id),

                        // Real Master Grid state — not a preference.
                        // is_scheduled_here means a Schedule row for
                        // this offering already lives in THIS room;
                        // scheduled_elsewhere_room_code flags the case
                        // where the Scheduler put it in a different
                        // room than the one currently preferred here.
                        'is_scheduled_here' => (bool) $scheduledHere,
                        'scheduled_day' => $scheduledHere?->day,
                        'scheduled_start_minutes' => $scheduledHere?->start_minutes,
                        'scheduled_end_minutes' => $scheduledHere?->end_minutes,
                        'scheduled_elsewhere_room_code' => $scheduledElsewhere?->room_code,
                    ];
                })
                ->values();
        }

        return response()->json([

            'room' => [
                'id' => $room->id,
                'room_code' => $room->room_code,
                'room_type' => $room->room_type,
                'room_group_codes' => $room->room_group_codes,
            ],

            'active_academic_term' => $activeTerm ? [
                'id' => $activeTerm->id,
                'display_name' => $activeTerm->display_name,
                // Whether this is literally the Active term or a
                // Planning draft — lets the Manage Subjects modal
                // label itself correctly for Admin/Registrar working
                // ahead of time. Always "Active" for Dean/Assistant
                // Dean/OIC, since they never resolve to anything else.
                'scheduling_status' => (($active = $this->workspace->getActiveTerm()) && $active->id === $activeTerm->id)
                    ? 'Active'
                    : 'Planning',
            ] : null,

            'offerings' => $offerings,

            'weekly_capacity_hours' => $this->capacity->weeklyCapacityHoursFor($activeTerm),

            // Real, committed hours/count for this room (from
            // `schedules`), alongside the existing preference totals —
            // see the docblock above. The modal should show BOTH: how
            // much is preferred vs. how much is actually scheduled.
            'scheduled_hours' => $scheduledHours,
            'scheduled_count' => $scheduledCount,

        ]);
    }


    /**
     * Replace this room's Preferred Subject Offerings for the ACTIVE
     * Academic Term only. Preferences belonging to any other (past)
     * term are left completely untouched.
     *
     * Every incoming ID is re-validated server-side against the active
     * term + this room's Room Type — the department smart-filter is a
     * UI convenience only, so it is NOT re-enforced here; a user may
     * deliberately prefer an "unrecommended" Subject Offering (e.g. a
     * Shared room), and that's allowed. What's never allowed is
     * attaching an offering from the wrong term or the wrong Room Type.
     *
     * Returns plain JSON (fresh preferred_hours/preferred_count for
     * THIS room only) rather than redirecting — the modal reads this
     * response to update its own room's row in Rooms/Index.vue
     * in-memory, then closes itself. No Inertia navigation, no
     * page reload, nothing else on the page is touched.
     */
    public function syncPreferredSubjects(Request $request, Room $room)
    {
        $validated = $request->validate([
            'subject_offering_ids' => ['present', 'array'],
            'subject_offering_ids.*' => ['integer', 'exists:subject_offerings,id'],
        ]);

        // Admin/Registrar write against the Planning term (staffing
        // rooms ahead of time); Dean/Assistant Dean/OIC write against
        // whatever they're currently viewing — the Active term.
        $activeTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($activeTerm, 422, 'There is no Academic Term to manage preferences for. Configure one in Settings > Scheduling Workspace.');

        $this->workspace->assertWritable($activeTerm);

        $activeTermOfferingIds = SubjectOffering::where('academic_term_id', $activeTerm->id)
            ->where('room_type', $room->room_type)
            ->pluck('id');

        $selectedIds = collect($validated['subject_offering_ids'])
            ->intersect($activeTermOfferingIds)
            ->values();

        // Reject the whole save (nothing gets written) if this
        // selection would push the room past its term-derived weekly
        // capacity — see RoomCapacityService. Checked against the
        // re-validated $selectedIds, not the raw request input, so a
        // stale/foreign offering id can't be used to dodge the check.
        $capacityCheck = $this->capacity->checkCapacity($room, $activeTerm, $selectedIds);

        abort_if($capacityCheck['exceeds'], 422, $capacityCheck['message']);

        // Only ever touch this term's rows — detach everything this room
        // currently prefers for the active term, then reattach the
        // (re-validated) submitted selection.
        $room->preferredSubjectOfferings()->detach($activeTermOfferingIds);

        // A Subject Offering can only be preferred by ONE room at a time
        // (see the room_subject_offering unique index on
        // subject_offering_id). Checking an offering here that another
        // room currently claims TRANSFERS it to this room rather than
        // erroring — the same "last save wins" behavior the Manage
        // Subjects modal's "Currently in Room X" tag warns about before
        // the user ever clicks Save.
        //
        // NOTE: if this transfers an offering away from another room,
        // that other room's own preferred_hours/preferred_count in the
        // Index table go stale until the next full reload of this page
        // — this endpoint only recomputes and returns THIS room's
        // numbers, by design (see class docblock above).
        DB::table('room_subject_offering')
            ->whereIn('subject_offering_id', $selectedIds)
            ->delete();

        $room->preferredSubjectOfferings()->attach($selectedIds);

        $preferredHours = (int) $room->preferredSubjectOfferings()
            ->where('subject_offerings.academic_term_id', $activeTerm->id)
            ->sum('hours');

        $preferredCount = $room->preferredSubjectOfferings()
            ->where('subject_offerings.academic_term_id', $activeTerm->id)
            ->count();

        return response()->json([
            'message' => 'Preferred subjects updated successfully.',
            'room_id' => $room->id,
            'preferred_hours' => $preferredHours,
            'preferred_count' => $preferredCount,
        ]);
    }

    /**
     * Whether a Subject Offering fits this room's Department/Program
     * assignment (Room Type has already been filtered out before this
     * is called). Mirrors the PAP business rule:
     *
     *   - General ("Shared") rooms: every offering of the right Room
     *     Type is compatible, regardless of program.
     *   - Program-specific rooms (e.g. BSIT): Major offerings must match
     *     one of the room's assigned programs; Minor offerings are only
     *     compatible when the underlying Subject is itself flagged
     *     "General" (i.e. a General Education subject open to every
     *     program), via Subject::isApplicableToRoomGroup().
     *
     * Requires $offering->subject->roomGroups to already be eager-loaded
     * by the caller to avoid an N+1 query per offering.
     */
    private function isDepartmentCompatible(SubjectOffering $offering, Room $room): bool
    {
        $roomGroups = $room->room_group_codes;

        if (in_array('General', $roomGroups, true)) {
            return true;
        }

        if ($offering->classification === SubjectOffering::CLASSIFICATION_MAJOR) {
            return in_array($offering->program?->code, $roomGroups, true);
        }

        return (bool) $offering->subject?->isApplicableToRoomGroup('General');
    }

    /**
     * Replace a room's assigned programs with the given list.
     *
     * Delete-and-recreate rather than a diff — the list is small (at most
     * six checkbox pills) so there's no meaningful cost to doing it the
     * simple way.
     */
    private function syncRoomGroups(Room $room, array $roomGroups): void
    {
        $room->roomGroups()->delete();

        foreach ($roomGroups as $roomGroup) {
            $room->roomGroups()->create([
                'room_group' => $roomGroup,
            ]);
        }
    }

    /**
     * The set of programs a room can be assigned to. "General" means
     * available to every department; the rest name a specific department a
     * room is Shared or Exclusive to.
     *
     * Sourced live from the Programs table (via SubjectRoomGroup::options()
     * — the same pivot-value helper Subject already uses) instead of a
     * hardcoded list, so a newly added College/Program is immediately
     * selectable here too, with zero code changes.
     */
    private function roomGroupOptions(): array
    {
        return SubjectRoomGroup::options();
    }

    /**
     * Shared validation rules for store() and update().
     *
     * @param  \Illuminate\Http\Request  $request  The current request —
     *         needed so the room_groups rule can look at the sibling
     *         room_type value.
     * @param  \App\Models\Room|null  $room  The room being updated, null
     *         when creating (used for the unique/ignore rule).
     */
    private function rules(Request $request, ?Room $room = null): array
    {
        return [

            'room_code' => [
                'required',
                'string',
                'max:20',
                $room
                    ? Rule::unique('rooms', 'room_code')->ignore($room->id)
                    : Rule::unique('rooms', 'room_code'),
            ],

            /*
            |--------------------------------------------------------------------------
            | Room Type
            |--------------------------------------------------------------------------
            */

            'room_type' => [
                'required',
                Rule::in([
                    'Lecture',
                    'Laboratory',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Available Programs (Room Groups)
            |--------------------------------------------------------------------------
            |
            | Multi-select: a room can be General (available to every
            | department), Shared by several departments, or Exclusive to
            | one. Business rules, mirroring Subject::room_groups:
            |
            |   - "General" cannot be combined with anything else — a room
            |     is either open to everyone or restricted to specific
            |     programs, not both.
            |   - "General" is a Lecture-only value — Laboratory rooms must
            |     always belong to one or more specific programs.
            |
            */

            'room_groups' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) use ($request) {

                    $hasGeneral = in_array('General', $value, true);

                    if ($hasGeneral && count($value) > 1) {
                        $fail('General cannot be combined with other programs. Select General on its own, or select one or more specific programs instead.');
                        return;
                    }

                    if ($hasGeneral && $request->input('room_type') === 'Laboratory') {
                        $fail('General is a Lecture-only program. Laboratory rooms must select one or more specific programs.');
                    }

                },
            ],

            // Sourced live from the Programs table (plus "General") via
            // SubjectRoomGroup::options() — a newly added College/Program
            // is a valid room_groups value immediately, no code change
            // required. Mirrors the identical rule on
            // SubjectController::rules().
            'room_groups.*' => [
                Rule::in(SubjectRoomGroup::options()),
            ],

            'building' => [
                'required',
                'string',
                'max:255',
            ],

            'floor' => [
                'nullable',
                'string',
                'max:50',
            ],

            /*
            |--------------------------------------------------------------------------
            | Capacity
            |--------------------------------------------------------------------------
            |
            | Default of 30 is applied client-side (form initial value);
            | enforced here as a hard 20-45 range regardless of what the
            | client sends.
            |
            */

            'capacity' => [
                'required',
                'integer',
                'min:20',
                'max:45',
            ],

            'active' => [
                'required',
                'boolean',
            ],

        ];
    }
}