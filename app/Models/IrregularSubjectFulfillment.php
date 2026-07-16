<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "This Irregular Section's need for a Subject is already covered by
 * an existing Regular Section's Subject Offering — no new EDP Code
 * needed." See the migration for the full reasoning; see
 * SubjectOfferingController::storeIrregular() for where these get
 * created instead of a new SubjectOffering.
 */
class IrregularSubjectFulfillment extends Model
{
    protected $fillable = [
        'section_id',
        'subject_offering_id',
        'curriculum_item_id',
        'created_by',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * The Regular Section's Subject Offering being reused — this is
     * where the shared EDP Code, faculty, room, and schedule actually
     * live.
     */
    public function subjectOffering()
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function curriculumItem()
    {
        return $this->belongsTo(CurriculumItem::class);
    }
}