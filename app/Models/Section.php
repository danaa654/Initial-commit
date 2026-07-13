<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = [
        'curriculum_id',
        'section_code',
        'section_name',
        'year_level',
        'section_letter',
        'capacity',
        'status',

        // Irregular Sections skip curriculum/year-level auto-population
        // of Subject Offerings entirely — see EdpCodeService::generate()
        // and SubjectOfferingController::irregularSubjects()/
        // storeIrregular() for the manual pick-a-subject flow this
        // flag switches on.
        'is_irregular',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_irregular' => 'boolean',
    ];

    // Append these attributes when converting to array/JSON
    protected $appends = ['is_in_use'];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * Subject Offerings generated for this Section. Teaching
     * Assignments no longer carry a direct section_id — a Teaching
     * Assignment's section is only ever reached through
     * subject_offering_id -> subject_offerings.section_id, so this
     * relationship is the path isInUse() below has to go through.
     */
    public function subjectOfferings()
    {
        return $this->hasMany(SubjectOffering::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Attributes
    |--------------------------------------------------------------------------
    */

    public function getIsInUseAttribute()
    {
        return $this->isInUse();
    }

    /**
     * Check if this section is currently in use — i.e. at least one of
     * its Subject Offerings already has a faculty member assigned via
     * Faculty Loading, OR already has a committed Master Grid schedule
     * block (day/time/room). A Section having generated (but still
     * unassigned) Offerings does NOT count as "in use" — those are
     * safe to regenerate/adjust freely; it's an actual Teaching
     * Assignment or a committed Schedule row that makes deleting the
     * Section unsafe. The Schedule check exists as a backstop in case
     * a schedule block ever ends up committed without (or after
     * losing) its Teaching Assignment.
     */
    public function isInUse()
    {
        return $this->subjectOfferings()
            ->where(function ($query) {
                $query->whereHas('teachingAssignment')
                    ->orWhereHas('schedule');
            })
            ->exists();
    }
}