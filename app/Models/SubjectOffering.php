<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubjectOffering extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    |
    | These are the possible values of the DERIVED overall_status
    | accessor below — there is no status column. STATUS_DRAFT is kept
    | only for forward compatibility (e.g. a future "save without
    | generating" step); getOverallStatusAttribute() never returns it
    | today, since every row here was, by definition, generated.
    */

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_GENERATED = 'Generated';
    public const STATUS_FACULTY_ASSIGNED = 'Faculty Assigned';
    public const STATUS_ROOM_ASSIGNED = 'Room Assigned';
    public const STATUS_READY_FOR_SCHEDULING = 'Ready for Scheduling';
    public const STATUS_SCHEDULED = 'Scheduled';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_ARCHIVED = 'Archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_GENERATED,
        self::STATUS_FACULTY_ASSIGNED,
        self::STATUS_ROOM_ASSIGNED,
        self::STATUS_READY_FOR_SCHEDULING,
        self::STATUS_SCHEDULED,
        self::STATUS_COMPLETED,
        self::STATUS_ARCHIVED,
    ];

    public const CLASSIFICATION_MAJOR = 'Major';
    public const CLASSIFICATION_MINOR = 'Minor';

    /**
     * Fallback used wherever meetings_per_week hasn't been explicitly
     * set in Session Settings yet (including every row that existed
     * before that column shipped) — one meeting carrying the full
     * weekly duration, the safest assumption since it never requires
     * splitting a subject across day-combinations that haven't been
     * chosen.
     */
    public const DEFAULT_MEETINGS_PER_WEEK = 1;

    protected $fillable = [
        'academic_term_id',
        'curriculum_id',
        'curriculum_item_id',
        'program_id',
        'subject_id',
        'section_id',
        'year_level',
        'semester',
        'units',
        'hours',
        'meetings_per_week',
        'classification',
        'room_type',
        'edp_code',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'semester' => 'integer',
            'units' => 'integer',
            'hours' => 'integer',
            'meetings_per_week' => 'integer',
        ];
    }

    /**
     * faculty_status / room_status / overall_status are all derived,
     * never stored — this module must never itself carry an
     * assignment, and overall_status must never be able to drift out
     * of sync with the real Faculty/Room/Term data it summarizes.
     */
    protected $appends = [
        'faculty_status',
        'room_status',
        'overall_status',
        'hours_per_meeting',
    ];

    /**
     * Weekly hours for THIS offering, with the Subject master's hours
     * as a fallback ONLY when this row has never had its own value
     * set. This is the Bulk Update Weekly Hours feature's read side:
     * Session Settings, the Greedy Scheduler, and the Master Grid all
     * read ->hours (this accessor) rather than the raw column, so a
     * Registrar's per-Term override (e.g. Programming 1 scheduled at
     * 4 hrs/week instead of the curriculum's 5) is honored everywhere
     * scheduling happens — while the Subject master itself is never
     * written to by that action (see
     * SubjectOfferingController::bulkUpdateWeeklyHours()).
     *
     * Priority: 1) this row's own `hours` column, 2) subject.hours as
     * a fallback for rows generated before this column was always
     * populated. Only triggers a `subject` lookup when the column is
     * actually null, and prefers the already-loaded relation when
     * present — no extra query for the common case where every
     * offering already carries its own hours.
     */
    public function getHoursAttribute($value): ?int
    {
        if ($value !== null) {
            return (int) $value;
        }

        $subject = $this->relationLoaded('subject') ? $this->subject : $this->subject()->first();

        return $subject?->hours !== null ? (int) $subject->hours : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function academicTerm()

    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function curriculumItem()
    {
        return $this->belongsTo(CurriculumItem::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Faculty Loading assignment for this Offering, if one
     * exists — the only place a Faculty assignment actually lives.
     * Read-only from here; this model never writes to it.
     */
    public function teachingAssignment()
    {
        return $this->hasOne(TeachingAssignment::class);
    }

    /**
     * The committed Master Grid schedule block for this Offering, if
     * one has been saved yet. Unlike TeachingAssignment::schedule()
     * (a hasOneThrough, since a Teaching Assignment has no direct FK
     * to Schedule), this is a direct hasOne — `schedules` already
     * carries subject_offering_id itself. A 2x/week subject has two
     * Schedule rows (one per meeting day); hasOne only surfaces the
     * first, but that's all getOverallStatusAttribute()/room_status
     * need — they only ever ask "does at least one exist?", never
     * "give me every meeting day."
     *
     * Eager-load this (`with('schedule')`) anywhere offerings are
     * listed in bulk so the status accessors below can read it
     * in-memory instead of firing a query per offering — see
     * MasterGridDataService, which was doing exactly that before this
     * relation existed.
     */
    public function schedule()
    {
        return $this->hasOne(Schedule::class);
    }

    /**
     * EVERY committed Master Grid schedule block for this Offering —
     * one row per meeting day for a 2x/3x-meeting subject, vs.
     * schedule() above (hasOne) which only ever surfaces the first.
     * Use this anywhere the actual weekly schedule needs to be
     * displayed (Block Schedule, Faculty Schedule); schedule() stays
     * reserved for existence-only checks (getOverallStatusAttribute(),
     * room_status) that never needed more than "does at least one
     * exist?".
     */
    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * The Room(s) that currently prefer this Offering, via the
     * room_subject_offering pivot — the inverse of
     * Room::preferredSubjectOfferings(). subject_offering_id is
     * unique on that pivot table, so in practice this never holds
     * more than one row; use preferred_room below to read it as a
     * single value instead of unwrapping a collection every time.
     *
     * This is a PREFERENCE, not a Room assignment with a day/time —
     * see room_subject_offering's migration docblock. Faculty Loading
     * surfaces it purely so a Dean/Registrar can see "a room has
     * already been earmarked for this class" before the Scheduler
     * ever runs.
     */
    public function preferredByRooms()
    {
        return $this->belongsToMany(Room::class, 'room_subject_offering')->withPivot('is_override')->withTimestamps();
    }

    /**
     * The single preferred Room for this Offering, or null. Safe to
     * call whether or not preferredByRooms was eager-loaded — reads
     * from the already-loaded collection when available to avoid an
     * extra query per offering, and only hits the DB directly as a
     * fallback for one-off access.
     */
    public function getPreferredRoomAttribute()
    {
        return $this->relationLoaded('preferredByRooms')
            ? $this->preferredByRooms->first()
            : $this->preferredByRooms()->first();
    }

    /**
     * The Faculty member who currently prefers this Offering, via the
     * faculty_subject_offering pivot — the inverse of
     * Faculty::preferredSubjectOfferings(). Same "preference, not an
     * assignment" caveat as preferredByRooms() above; the actual
     * Faculty Loading assignment still only ever lives in
     * teachingAssignment().
     */
    public function preferredByFaculty()
    {
        return $this->belongsToMany(Faculty::class, 'faculty_subject_offering')->withTimestamps();
    }

    /**
     * The single Faculty member who prefers this Offering, or null.
     * Same loaded-vs-fallback pattern as getPreferredRoomAttribute().
     */
    public function getPreferredFacultyAttribute()
    {
        return $this->relationLoaded('preferredByFaculty')
            ? $this->preferredByFaculty->first()
            : $this->preferredByFaculty()->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeForTerm($query, int $academicTermId)
    {
        return $query->where('academic_term_id', $academicTermId);
    }

    public function scopeForProgram($query, int $programId)
    {
        return $query->where('program_id', $programId);
    }

    /*
    |--------------------------------------------------------------------------
    | Derived Assignment Status (read-only)
    |--------------------------------------------------------------------------
    |
    | Defensive on purpose, same pattern as
    | AcademicTerm::hasSchedulingData() — Faculty Loading's and the
    | future Preferred Rooms module's schema aren't owned here, so
    | these check for the relevant table/column before trusting it,
    | rather than hard-failing if either hasn't been built out yet.
    */

    /**
     * Auto-computed "hours per meeting" shown (read-only) in Session
     * Settings — total weekly duration divided by however many times
     * a week the Registrar has this subject meeting. Deliberately NOT
     * rounded: a subject that doesn't divide evenly (e.g. 3 hrs ÷ 2
     * meetings) surfaces as 1.5 here, and it's Session Settings' job
     * to warn about that and let the Registrar decide, not this
     * accessor's job to silently round it away.
     */
    public function getHoursPerMeetingAttribute(): float
    {
        $meetings = $this->meetings_per_week ?: self::DEFAULT_MEETINGS_PER_WEEK;

        if ($meetings <= 0 || ! $this->hours) {
            return 0;
        }

        return round(((float) $this->hours) / $meetings, 2);
    }

    public function getFacultyStatusAttribute(): string
    {
        return $this->teachingAssignment?->faculty_id ? 'Assigned' : 'Unassigned';
    }

    public function getRoomStatusAttribute(): string
    {
        if (
            $this->teachingAssignment
            && self::hasColumnCached('teaching_assignments', 'room_id')
            && $this->teachingAssignment->room_id
        ) {
            return 'Assigned';
        }

        // A committed Master Grid schedule (`schedules.room_id`) is the
        // strongest possible signal a room is assigned — it means this
        // offering is not merely preferred for a room, it is ACTUALLY
        // meeting there, on a real day/time. This must be checked
        // BEFORE the Room Preferences pivot below: an offering the
        // Greedy Scheduler auto-picked a room for (rather than one a
        // Registrar manually preferred beforehand via Manage Subjects)
        // never gets a room_subject_offering row at all, so relying on
        // that pivot alone left a fully Scheduled offering's Room
        // column reading "Unassigned" — directly contradicting its own
        // overall_status of "Scheduled" just one column over.
        //
        // Reuses hasScheduleAssigned() (below) rather than repeating
        // its own DB::table() query — that method already prefers the
        // eager-loaded `schedule` relation when present, so this stays
        // a plain in-memory check whenever offerings are listed with
        // `with('schedule')`, instead of firing a query per offering.
        if ($this->hasScheduleAssigned()) {
            return 'Assigned';
        }

        // Room Preferences module (Rooms > Manage Subjects) — a Room
        // preferring this Offering via the room_subject_offering pivot
        // (see Room::preferredSubjectOfferings()) counts as a Room
        // assignment here, the same way a Teaching Assignment counts as
        // a Faculty assignment above. No day/time has been decided yet
        // either way — this is still a preference, not a schedule.
        //
        // Prefers the eager-loaded `preferredByRooms` relation (see
        // getPreferredRoomAttribute() above) over a fresh pivot query,
        // for the same reason as hasScheduleAssigned() below.
        if ($this->relationLoaded('preferredByRooms')) {
            if ($this->preferredByRooms->isNotEmpty()) {
                return 'Assigned';
            }
        } elseif (
            self::hasTableCached('room_subject_offering')
            && DB::table('room_subject_offering')->where('subject_offering_id', $this->id)->exists()
        ) {
            return 'Assigned';
        }

        // Legacy/forward-compatible check for a possible future
        // dedicated room_assignments table, kept in case that ever
        // replaces the pivot above.
        if (
            self::hasTableCached('room_assignments')
            && self::hasColumnCached('room_assignments', 'subject_offering_id')
            && DB::table('room_assignments')->where('subject_offering_id', $this->id)->exists()
        ) {
            return 'Assigned';
        }

        return 'Unassigned';
    }

    /**
     * The single "how far along is this class" summary shown on the
     * Index page — fully derived, checked in this order:
     *
     *   1. Archived   — the Academic Term itself has been Archived
     *                    (set in the Academic Terms module; read-only
     *                    from here).
     *   2. Completed  — the Academic Term's class_end_date has
     *                    already passed.
     *   3. Scheduled  — the future Scheduler has written a schedule
     *                    row for this offering.
     *   4. Ready for Scheduling — Faculty AND Room are both assigned.
     *   5. Room Assigned    — only Room is assigned.
     *   6. Faculty Assigned — only Faculty is assigned.
     *   7. Generated  — none of the above; fresh from generation.
     *
     * Requires academicTerm and teachingAssignment to be loaded (or
     * loadable) — eager-load both wherever offerings are listed to
     * avoid N+1 queries. For bulk listings (Master Grid, Subject
     * Offerings index with a status filter), also eager-load
     * `schedule` and `preferredByRooms` — see hasScheduleAssigned()
     * and getRoomStatusAttribute() above — so this entire accessor
     * resolves purely from already-loaded relations, with zero
     * queries per offering.
     *
     * Cached per-instance after the first call: this is a plain
     * get{Studly}Attribute-style accessor, which Eloquent does NOT
     * memoize on its own (unlike a real column), so calling
     * ->overall_status more than once on the same instance would
     * otherwise re-run this whole method — including every check
     * below it — from scratch each time. Several callers (Master
     * Grid's status partitioning + per-offering row builder, Subject
     * Offerings' status filter) read this more than once per
     * offering in the same request, so caching it here is what makes
     * repeated reads free instead of silently repeating the same
     * work N times.
     */
    private ?string $overallStatusCache = null;

    public function getOverallStatusAttribute(): string
    {
        return $this->overallStatusCache ??= $this->computeOverallStatus();
    }

    private function computeOverallStatus(): string
    {
        $term = $this->academicTerm;

        if ($term?->status === 'Archived') {
            return self::STATUS_ARCHIVED;
        }

        if ($term?->class_end_date && now()->gt($term->class_end_date)) {
            return self::STATUS_COMPLETED;
        }

        if ($this->hasScheduleAssigned()) {
            return self::STATUS_SCHEDULED;
        }

        $facultyAssigned = $this->faculty_status === 'Assigned';
        $roomAssigned = $this->room_status === 'Assigned';

        if ($facultyAssigned && $roomAssigned) {
            return self::STATUS_READY_FOR_SCHEDULING;
        }

        if ($roomAssigned) {
            return self::STATUS_ROOM_ASSIGNED;
        }

        if ($facultyAssigned) {
            return self::STATUS_FACULTY_ASSIGNED;
        }

        return self::STATUS_GENERATED;
    }

    /**
     * Whether a committed Master Grid schedule row exists for this
     * Offering. Prefers the eager-loaded `schedule` relation (see
     * schedule() above) when present — a plain in-memory null-check,
     * zero queries. Falls back to a direct DB::table() query only for
     * call sites that access a single offering's overall_status
     * without eager-loading `schedule` first (unchanged behavior from
     * before this relation existed).
     *
     * The fallback result is cached per-instance: computeOverallStatus()
     * and getRoomStatusAttribute() both call this, so without caching,
     * any call site that forgets to eager-load `schedule` pays for the
     * same DB::table() lookup TWICE per offering instead of once. This
     * is a safety net, not a substitute for eager-loading — a caller
     * listing 200+ offerings without `with('schedule')` still fires one
     * query per offering, just no longer two.
     */
    private ?bool $hasScheduleAssignedCache = null;

    private function hasScheduleAssigned(): bool
    {
        if ($this->relationLoaded('schedule')) {
            return $this->schedule !== null;
        }

        if ($this->hasScheduleAssignedCache !== null) {
            return $this->hasScheduleAssignedCache;
        }

        if (! self::hasTableCached('schedules') || ! self::hasColumnCached('schedules', 'subject_offering_id')) {
            return $this->hasScheduleAssignedCache = false;
        }

        return $this->hasScheduleAssignedCache = DB::table('schedules')->where('subject_offering_id', $this->id)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Schema Check Memoization
    |--------------------------------------------------------------------------
    |
    | Schema::hasTable()/hasColumn() are NOT cached by Laravel — each
    | call re-queries the database's information_schema. The status
    | accessors above call these defensively on every offering (there
    | is no migration-state column to check instead), so without this
    | cache, listing 200+ offerings could fire 200+ *additional*
    | information_schema round-trips on top of the status queries
    | themselves. Schema never changes mid-request (a migration
    | requires a deploy/restart), so a static, process-lifetime cache
    | is always safe.
     */
    private static array $schemaCache = [];

    private static function hasTableCached(string $table): bool
    {
        return self::$schemaCache['table:' . $table] ??= Schema::hasTable($table);
    }

    private static function hasColumnCached(string $table, string $column): bool
    {
        return self::$schemaCache['column:' . $table . '.' . $column] ??= Schema::hasColumn($table, $column);
    }
}