<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faculty extends Model
{
    use HasFactory;

    /**
     * The most extra units a faculty member's effective cap may ever
     * carry ON TOP OF their normal max_units, summed across every
     * 'approved' + 'pending' Faculty Load Overload row at once (see
     * FacultyLoadOverloadService::assertWithinCap()). A faculty member
     * with max_units = 24 can therefore never be pushed past 36.
     */
    public const MAX_OVERLOAD_UNITS = 12;

    /**
     * Every overload request must be a multiple of this — one
     * subject's worth of units.
     */
    public const OVERLOAD_INCREMENT_UNITS = 3;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'gender',
        'contact_number',
        'email',
        'department_id',
        'faculty_scope',
        'employment_type',
        'max_units',
        'status',
    ];

    protected $appends = [
        'full_name',
        'approved_overload_units',
        'pending_overload_units',
        'effective_max_units',
        'available_overload_units',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Faculty Subjects
    |--------------------------------------------------------------------------
    |
    | Every subject this faculty member is qualified to teach, via the
    | dedicated faculty_subjects table (added for the Faculty Subject
    | Assignment module).
    |
    */

    public function facultySubjects()
    {
        return $this->hasMany(FacultySubject::class);
    }

    public function teachingAssignments()
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    /**
     * Every persisted timetable block for this faculty member (the
     * Master Grid's actual output — see Schedule::faculty()). Used by
     * FacultyController::destroy() to detect "this faculty member is
     * already scheduled" before allowing a delete, and by index() to
     * surface that same flag to the UI for the double-confirmation
     * prompt.
     */
    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Every Faculty Load Overload request ever made for this faculty
     * member — pending, approved, and declined alike. Eager-load this
     * (`with('loadOverloads')`) wherever a page needs
     * effective_max_units/approved_overload_units/etc. for several
     * faculty at once, to avoid an N+1 query per faculty member — the
     * accessors below prefer the loaded collection when it's already
     * there.
     */
    public function loadOverloads()
    {
        return $this->hasMany(FacultyLoadOverload::class);
    }

    /**
     * Subject Offerings this Faculty member PREFERS to teach, via the
     * faculty_subject_offering pivot (see the Faculty "Manage Subjects"
     * workspace). Direct mirror of Room::preferredSubjectOfferings().
     *
     * This is a preference only — it carries no day/time/room
     * information and is NOT the same thing as an actual Faculty
     * Loading assignment (see teachingAssignments() above). Since every
     * Subject Offering already belongs to one Academic Term, filter by
     * ->where('academic_term_id', ...) wherever only the active term's
     * preferences should count, rather than assuming every row here is
     * current.
     */
    public function preferredSubjectOfferings()
    {
        return $this->belongsToMany(SubjectOffering::class, 'faculty_subject_offering')
            ->withTimestamps();
    }

    public function getFullNameAttribute()
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' ');
    }

    /*
    |--------------------------------------------------------------------------
    | Faculty Load Overload (derived, read-only)
    |--------------------------------------------------------------------------
    |
    | These are all derived from loadOverloads() — there is no stored
    | "overload units" column on this table. Each prefers the
    | already-loaded relationship (avoiding an extra query per faculty
    | member when a page eager-loads loadOverloads for the whole
    | roster) and falls back to a direct query otherwise.
    */

    public function getApprovedOverloadUnitsAttribute(): int
    {
        return (int) ($this->relationLoaded('loadOverloads')
            ? $this->loadOverloads->where('status', FacultyLoadOverload::STATUS_APPROVED)->sum('units')
            : $this->loadOverloads()->approved()->sum('units'));
    }

    public function getPendingOverloadUnitsAttribute(): int
    {
        return (int) ($this->relationLoaded('loadOverloads')
            ? $this->loadOverloads->where('status', FacultyLoadOverload::STATUS_PENDING)->sum('units')
            : $this->loadOverloads()->pending()->sum('units'));
    }

    /**
     * The real teaching cap to check assignments against — max_units
     * plus every APPROVED overload. Pending/declined requests never
     * affect this; only an Admin/Registrar approval does. This is what
     * TeachingAssignmentService::assertWithinMaxUnits() and the Faculty
     * Loading UI's load bar/percent both use instead of the raw
     * max_units column.
     */
    public function getEffectiveMaxUnitsAttribute(): int
    {
        return $this->max_units + $this->approved_overload_units;
    }

    /**
     * How much MORE overload could still be requested for this faculty
     * member before hitting MAX_OVERLOAD_UNITS — counts pending
     * requests too, since an approval could land on them at any time
     * and there's no reason to let the same faculty member be
     * over-requested past the cap in the meantime.
     */
    public function getAvailableOverloadUnitsAttribute(): int
    {
        return max(self::MAX_OVERLOAD_UNITS - $this->approved_overload_units - $this->pending_overload_units, 0);
    }
}