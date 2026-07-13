<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [

        /*
        |--------------------------------------------------------------------------
        | Subject Information
        |--------------------------------------------------------------------------
        */

        'subject_code',
        'descriptive_title',

        /*
        |--------------------------------------------------------------------------
        | Academic Information
        |--------------------------------------------------------------------------
        */

        'units',

        'lecture_hours',
        'laboratory_hours',
        'total_hours',

        'is_major',

        /*
        |--------------------------------------------------------------------------
        | Scheduling
        |--------------------------------------------------------------------------
        |
        | required_room_group is gone — a subject's applicable programs now live
        | in the room_group_subject pivot (see roomGroups() below), independent
        | of is_major. Both Major and Minor subjects support any combination of
        | programs.
        |
        */

        'required_room_type',
        'is_practicum',

        'allow_split_schedule',

        /*
        |--------------------------------------------------------------------------
        | Prerequisite
        |--------------------------------------------------------------------------
        */

        'prerequisite_id',

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        'active',
    ];

    /*
    |--------------------------------------------------------------------------
    | Appends
    |--------------------------------------------------------------------------
    |
    | room_group_codes gives the frontend (Create/Edit forms, Index badges) a
    | plain array of program strings instead of having to unpack the
    | roomGroups relationship's pivot-row shape every time.
    |
    */

    protected $appends = [
        'room_group_codes',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    | Matches the column types defined in the subjects migration so values
    | come back from Eloquent as the correct native PHP types (booleans as
    | booleans, integers as integers) instead of raw strings/ints from MySQL.
    */

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'lecture_hours' => 'integer',
            'laboratory_hours' => 'integer',
            'total_hours' => 'integer',
            'is_major' => 'boolean',
            'allow_split_schedule' => 'boolean',
            'is_practicum' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function prerequisite()
    {
        return $this->belongsTo(
            Subject::class,
            'prerequisite_id'
        );
    }

    public function dependents()
    {
        return $this->hasMany(
            Subject::class,
            'prerequisite_id'
        );
    }

    /**
     * The one-or-more programs (General/BSIT/BSED/BSHM/BSTM/BSCRIM) this
     * subject is applicable to. Replaces the old single required_room_group
     * belongsTo-style column with a proper many-to-many via the
     * room_group_subject pivot table.
     */
    public function roomGroups()
    {
        return $this->hasMany(SubjectRoomGroup::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Curriculum Assignment
    |--------------------------------------------------------------------------
    |
    | A subject is a master-list entry that can be reused across many
    | curriculums (e.g. NSTP1 under BSIT, BSCS, BSHM, etc.) via
    | curriculum_items. curriculum_items also holds non-Subject item types
    | (OJT, and future types) that never reference a subject at all — those
    | rows simply never show up on the other side of these relationships.
    |
    */

    public function curriculumItems()
    {
        return $this->hasMany(CurriculumItem::class);
    }

    public function curriculums()
    {
        return $this->belongsToMany(Curriculum::class, 'curriculum_items')
            ->wherePivot('item_type', CurriculumItem::TYPE_SUBJECT)
            ->withPivot(['id', 'item_type', 'year_level', 'semester', 'sort_order', 'active'])
            ->withTimestamps();
    }

    // Every Subject Offering generated for this subject
    // (subject_offerings.subject_id) — offerings are snapshotted
    // copies taken at generation time, but they still carry a direct
    // FK back to the Subject master row. Needed by
    // SubjectController::destroy() to block deletion once offerings
    // exist, the same way CurriculumItem::subjectOfferings() blocks
    // it one level up.
    public function subjectOfferings()
    {
        return $this->hasMany(SubjectOffering::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Faculty Assignment
    |--------------------------------------------------------------------------
    |
    | Every faculty member qualified to teach this subject, via the
    | dedicated faculty_subjects table (added for the Faculty Subject
    | Assignment module).
    |
    */

    public function facultySubjects()
    {
        return $this->hasMany(FacultySubject::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Subjects eligible for the automatic scheduler
     * (i.e. everything except Practicum/OJT).
     */
    public function scopeSchedulable($query)
    {
        return $query->where('is_practicum', false);
    }

    /**
     * Subjects applicable to a given program — i.e. subjects that have any
     * room_group_subject row matching $roomGroup. This is the many-to-many
     * equivalent of the old `where('required_room_group', $roomGroup)`
     * filter, and is what both the Subjects index filter and the
     * scheduler's room-matching logic should use going forward.
     */
    public function scopeForRoomGroup($query, string $roomGroup)
    {
        return $query->whereHas('roomGroups', function ($query) use ($roomGroup) {
            $query->where('room_group', $roomGroup);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Plain array of this subject's assigned program codes, e.g.
     * ['BSHM', 'BSTM']. Empty array when no programs are assigned (Practicum/
     * OJT subjects, or "None" room-type subjects, typically have none).
     *
     * Relies on roomGroups being eager-loaded (with('roomGroups')) wherever
     * this accessor is read at scale, to avoid N+1 queries.
     */
    public function getRoomGroupCodesAttribute(): array
    {
        return $this->roomGroups->pluck('room_group')->all();
    }

    /**
     * True if this subject is applicable to the given program — i.e. it has
     * been assigned to that program, regardless of how many other programs
     * it's also assigned to.
     */
    public function isApplicableToRoomGroup(string $roomGroup): bool
    {
        return $this->roomGroups->contains('room_group', $roomGroup);
    }
}