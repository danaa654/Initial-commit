<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacultySubject extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Assignment Fields
    |--------------------------------------------------------------------------
    |
    | Indicates which subjects a faculty member is allowed to teach. This is
    | intentionally a dedicated table (not a belongsToMany pivot) since the
    | assignment itself carries meaningful attributes — preferred, active,
    | remarks — that a plain pivot wouldn't comfortably expose.
    |
    */

    protected $fillable = [
        'faculty_id',
        'subject_id',
        'preferred',
        'active',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'preferred' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}