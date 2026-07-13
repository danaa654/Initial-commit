<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single Faculty Loading assignment: one faculty member teaching one
 * Subject Offering. Every other fact about the assignment (academic
 * term, section, curriculum item, subject, year level, semester) is
 * reached through subjectOffering — it is never duplicated here.
 */
class TeachingAssignment extends Model
{
    use HasFactory;

    protected $fillable = [

        'subject_offering_id',

        'faculty_id',

        'remarks',

        'active',

    ];

    protected $casts = [

        'active' => 'boolean',

    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function subjectOffering()
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * The actual committed Master Grid schedule block for this
     * assignment's Subject Offering, if one has been saved yet — the
     * "when/where" half of this record. This is deliberately a
     * hasOneThrough rather than a duplicated foreign key: `schedules`
     * keys off subject_offering_id (a Subject Offering can only ever
     * have one committed block — see MasterGridController::save()'s
     * updateOrCreate(['subject_offering_id' => ...])), and a Teaching
     * Assignment already points at the same offering. Going through
     * SubjectOffering means there is exactly one place
     * (subject_offering_id) that ties an assignment to its schedule,
     * so the two tables can never silently drift apart.
     *
     * Null until the Registrar/Admin runs Generate Schedule and Save
     * Schedule on the Master Grid for this offering — see
     * TeachingAssignmentController::index(), which eager-loads
     * 'schedule.room' so the Faculty Loading workspace can show real
     * room/day/time instead of just the pre-scheduling room
     * preference (subjectOffering.preferredByRooms).
     */
    public function schedule()
    {
        return $this->hasOneThrough(
            Schedule::class,
            SubjectOffering::class,
            'id',                   // subject_offerings.id
            'subject_offering_id',  // schedules.subject_offering_id
            'subject_offering_id',  // teaching_assignments.subject_offering_id
            'id'                    // subject_offerings.id
        );
    }

    /**
     * EVERY committed Master Grid schedule block for this assignment's
     * Subject Offering — not just the first one. A 2x/3x-meeting
     * subject has one `schedules` row per meeting day (see Schedule.php
     * and MasterGridController::save()'s per-day updateOrCreate()), and
     * schedule() above (hasOneThrough) only ever surfaces one of them.
     * Anywhere a Registrar/Dean is meant to actually SEE the weekly
     * schedule — Block Schedule, Faculty Schedule — must read from
     * here, or a 2x/week class silently reads back as if it only met
     * once. schedule() is left in place for callers that only need a
     * single-row existence/"is this scheduled at all" check.
     */
    public function schedules()
    {
        return $this->hasManyThrough(
            Schedule::class,
            SubjectOffering::class,
            'id',                   // subject_offerings.id
            'subject_offering_id',  // schedules.subject_offering_id
            'subject_offering_id',  // teaching_assignments.subject_offering_id
            'id'                    // subject_offerings.id
        );
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
     * Assignments whose Subject Offering belongs to the given academic
     * term. Since Teaching Assignments no longer carry their own
     * academic_term_id, this is the replacement for the old
     * ->where('academic_term_id', $id) filter.
     */
    public function scopeForTerm($query, int $academicTermId)
    {
        return $query->whereHas(
            'subjectOffering',
            fn ($inner) => $inner->where('academic_term_id', $academicTermId)
        );
    }
}