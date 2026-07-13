<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (Academic Term, Department/"College") pair, tracking
 * whether that college's scheduling data is locked (Finalized) for
 * that term. See TermFinalizationService for the single write path —
 * this model is deliberately thin, mirroring SystemSetting/Schedule's
 * pattern of "model holds relationships + scopes, service holds rules".
 */
class TermDepartmentFinalization extends Model
{
    protected $fillable = [
        'academic_term_id',
        'department_id',
        'finalized',
        'finalized_by',
        'finalized_at',
        'unfinalized_by',
        'unfinalized_at',
    ];

    protected $casts = [
        'finalized' => 'boolean',
        'finalized_at' => 'datetime',
        'unfinalized_at' => 'datetime',
    ];

    public function academicTerm()
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function finalizedBy()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function unfinalizedBy()
    {
        return $this->belongsTo(User::class, 'unfinalized_by');
    }

    public function scopeForTerm($query, int $academicTermId)
    {
        return $query->where('academic_term_id', $academicTermId);
    }

    public function scopeFinalized($query)
    {
        return $query->where('finalized', true);
    }
}