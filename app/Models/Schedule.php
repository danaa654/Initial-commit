<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The final, persisted schedule block for one Subject Offering — the
 * only durable output of the Master Grid workflow. Everything upstream
 * (Greedy preview, Interactive Review edits) exists purely in memory
 * until Save Schedule succeeds; see MasterGridController::save() and
 * ScheduleValidationService.
 */
class Schedule extends Model
{
    protected $fillable = [
        'academic_term_id',
        'subject_offering_id',
        'faculty_id',
        'room_id',
        'day',
        'start_minutes',
        'end_minutes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_minutes' => 'integer',
            'end_minutes' => 'integer',
        ];
    }

    public function academicTerm()
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function subjectOffering()
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function scopeForTerm($query, int $academicTermId)
    {
        return $query->where('academic_term_id', $academicTermId);
    }
}