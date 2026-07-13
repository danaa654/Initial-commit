<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AcademicTerm extends Model
{
    use HasFactory;

    protected $fillable = [

        'academic_year',

        'semester',

        'class_start_date',
        'class_end_date',

        'school_start_time',
        'school_end_time',

        'lunch_start_time',
        'lunch_end_time',

        'time_interval',

        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',

        'status',

        'active',

    ];

    /**
     * Attribute casting.
     */
    protected $casts = [

        'class_start_date' => 'date:Y-m-d',
        'class_end_date' => 'date:Y-m-d',

        'school_start_time' => 'datetime:H:i',
        'school_end_time' => 'datetime:H:i',

        'lunch_start_time' => 'datetime:H:i',
        'lunch_end_time' => 'datetime:H:i',

        'time_interval' => 'integer',

        'monday' => 'boolean',
        'tuesday' => 'boolean',
        'wednesday' => 'boolean',
        'thursday' => 'boolean',
        'friday' => 'boolean',
        'saturday' => 'boolean',
        'sunday' => 'boolean',

        'active' => 'boolean',

    ];

    /*
    |--------------------------------------------------------------------------
    | Appended Accessors
    |--------------------------------------------------------------------------
    |
    | semester_label and display_name are computed accessors. They need to
    | be appended so they actually show up in the JSON/Inertia payload —
    | without this, Index/Edit pages and the AcademicTermSelector component
    | would have no way to read them from the props.
    |
    */

    protected $appends = [
        'semester_label',
        'display_name',
        'start_year',
        'is_locked',
        'daily_hours',
        'active_days_count',
        'weekly_capacity_hours',
    ];

    /*
    |--------------------------------------------------------------------------
    | Semester Labels
    |--------------------------------------------------------------------------
    */

    public const SEMESTERS = [
        1 => '1st Semester',
        2 => '2nd Semester',
        3 => 'Summer',
    ];

    public const STATUSES = [
        'Draft',
        'Published',
        'Archived',
    ];

    /**
     * Allowed Time Interval values (minutes) for the scheduling engine.
     * The greedy scheduler will slice the school day into blocks of this
     * size, so arbitrary values aren't safe to allow — 15/30/60 are the
     * only granularities the engine is built to support. This is the
     * authoritative list; AcademicTermRequest validates against it and
     * useAcademicTermForm.js mirrors it for the <select> options.
     */
    public const TIME_INTERVALS = [15, 30, 60];

    /*
    |--------------------------------------------------------------------------
    | Academic Year Date Range (Single Source of Truth)
    |--------------------------------------------------------------------------
    |
    | Class Start / Class End only need to fall inside the selected
    | Academic Year — nothing semester-specific. Institutions change their
    | calendars (some start 1st Semester in June, others in August, some
    | run trimesters, etc.), so we deliberately do NOT hardcode semester
    | months here. This is the authoritative copy used by
    | AcademicTermRequest for server-side validation.
    |
    | A matching (non-authoritative) copy lives client-side in
    | resources/js/Composables/useAcademicTermForm.js purely so the date
    | pickers can restrict themselves in the browser. Keep both in sync if
    | this ever changes.
    |
    |   Academic Year "2026-2027" -> Jan 1, 2026 through Dec 31, 2027
    |
    */

    public static function academicYearDateRange(int $startYear): array
    {
        return [
            'min' => "{$startYear}-01-01",
            'max' => ($startYear + 1) . '-12-31',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * teaching_assignments has no academic_term_id column of its own —
     * it only carries subject_offering_id (see
     * 2026_07_03_120001_create_teaching_assignments_table.php). The
     * academic_term_id lives on subject_offerings instead, so this
     * relationship has to hop through that table rather than joining
     * teaching_assignments directly. Fixes: "Column not found:
     * teaching_assignments.academic_term_id" when deleting an
     * Academic Term (hasSchedulingData() below calls ->exists() on
     * this relationship).
     */
    public function teachingAssignments()
    {
        return $this->hasManyThrough(
            TeachingAssignment::class,
            SubjectOffering::class,
            'academic_term_id',    // FK on subject_offerings pointing to this academic_terms row
            'subject_offering_id', // FK on teaching_assignments pointing to subject_offerings
            'id',                  // local key on academic_terms
            'id'                   // local key on subject_offerings
        );
    }

    /**
     * Subject Offerings generated for this Academic Term (see
     * SubjectOfferingGeneratorService). Added for the "Generate Subject
     * Offerings" feature — SubjectOfferingController::create() needs
     * this to withCount() each term's existing offerings, so the
     * Generate page can show the replace-or-cancel prompt before the
     * user submits.
     */
    public function subjectOfferings()
    {
        return $this->hasMany(SubjectOffering::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Terms that have been committed to (Published or Archived) — i.e.
     * everything except Draft. Used by AcademicTermOverlapService: a
     * Draft is just a tentative sketch and shouldn't block another Draft
     * from tentatively using the same dates, but nothing may ever
     * overlap a term that has actually been published.
     */
    public function scopeNotDraft($query)
    {
        return $query->where('status', '!=', 'Draft');
    }

    /*
    |--------------------------------------------------------------------------
    | Business Rules
    |--------------------------------------------------------------------------
    */

    /**
     * Archived terms are read-only — they represent historical record and
     * should never be edited again once archived.
     */
    public function getIsLockedAttribute(): bool
    {
        return $this->status === 'Archived';
    }

    /**
     * Whether this Academic Term already has real scheduling data hanging
     * off it, in which case it must never be deleted (archive it instead).
     *
     * teachingAssignments (Faculty Loading) is the only scheduling-linked
     * table that exists today. subject_offerings is deliberately NOT
     * included here — offerings are pre-scheduling records (no room/time
     * assigned yet), not scheduling data, so they shouldn't by themselves
     * block an Academic Term from being deleted. Add 'subject_offerings'
     * to $futureScheduleTables below if that should change later.
     *
     * The other modules referenced in the spec — Schedules, Room
     * Assignments, Curriculum Schedules, Generated Schedules — aren't
     * built yet. Each check below is a no-op until its table exists, so
     * this method is safe to call right now and will automatically start
     * protecting those tables the moment they ship with an
     * academic_term_id column — no need to remember to come back and
     * touch this file again for each one.
     */
    public function hasSchedulingData(): bool
    {
        if ($this->teachingAssignments()->exists()) {
            return true;
        }

        $futureScheduleTables = [
            'schedules',
            'room_assignments',
            'curriculum_schedules',
            'generated_schedules',
        ];

        foreach ($futureScheduleTables as $table) {
            if (
                Schema::hasTable($table)
                && Schema::hasColumn($table, 'academic_term_id')
                && DB::table($table)->where('academic_term_id', $this->id)->exists()
            ) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getSemesterLabelAttribute()
    {
        return self::SEMESTERS[$this->semester] ?? null;
    }

    public function getDisplayNameAttribute()
    {
        return "AY {$this->academic_year} \u{2022} {$this->semester_label}";
    }

    /**
     * Derives the Start Year (e.g. 2026) from the stored "2026-2027"
     * academic_year string. Used to pre-fill the Start Year input on the
     * Edit form — the user never has to re-type or see the raw string.
     */
    public function getStartYearAttribute(): ?int
    {
        if (! $this->academic_year) {
            return null;
        }

        return (int) substr($this->academic_year, 0, 4);
    }

    /*
    |--------------------------------------------------------------------------
    | Weekly Room Capacity (derived from School Hours + Working Days)
    |--------------------------------------------------------------------------
    |
    | This term now IS the source of truth for "how many hours can a room
    | realistically host per week" — Room::WEEKLY_CAPACITY_HOURS used to
    | be a flat placeholder constant (see its docblock); RoomCapacityService
    | reads these accessors instead so Rooms/Index, the Manage Subjects
    | modal, and the over-capacity save guard all agree on one number.
    |
    | These are intentionally forgiving: a term missing school hours (or
    | a malformed lunch pair, or zero working days) just resolves to 0
    | rather than throwing, since RoomCapacityService already has to
    | fall back to Room::WEEKLY_CAPACITY_HOURS whenever a term can't
    | produce a usable number at all (e.g. no Active/Planning term yet).
    |
    */

    /**
     * Minutes of actual class time per day, i.e. School Hours minus the
     * Lunch Break (if one is set). school_start_time/school_end_time are
     * cast to `datetime:H:i`, so these come back as real Carbon instances
     * that ->diffInMinutes() can compare directly — the underlying date
     * component is irrelevant since both sides share it.
     */
    public function dailyMinutes(): int
    {
        if (! $this->school_start_time || ! $this->school_end_time) {
            return 0;
        }

        $minutes = $this->school_start_time->diffInMinutes($this->school_end_time);

        if ($this->lunch_start_time && $this->lunch_end_time) {
            $minutes -= $this->lunch_start_time->diffInMinutes($this->lunch_end_time);
        }

        return max(0, $minutes);
    }

    public function getDailyHoursAttribute(): float
    {
        return round($this->dailyMinutes() / 60, 2);
    }

    /**
     * How many of the seven day-booleans are actually working days for
     * this term (e.g. Mon-Fri = 5, Mon-Sat = 6).
     */
    public function getActiveDaysCountAttribute(): int
    {
        return collect([
            'monday', 'tuesday', 'wednesday', 'thursday',
            'friday', 'saturday', 'sunday',
        ])->filter(fn ($day) => (bool) $this->{$day})->count();
    }

    /**
     * Daily Hours x Active Days — the real weekly ceiling a Room's
     * preferred/scheduled hours should be measured against for this
     * term, replacing the old flat Room::WEEKLY_CAPACITY_HOURS
     * placeholder. See RoomCapacityService, which is the only intended
     * caller of this outside AcademicTerms' own Create/Edit preview.
     */
    public function getWeeklyCapacityHoursAttribute(): float
    {
        return round($this->daily_hours * $this->active_days_count, 2);
    }
}