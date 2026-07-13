<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    protected $fillable = [
        'program_id',
        'code',
        'name',
        'active',
    ];

    /**
     * A specialization belongs to one program.
     */
    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    // Curricula optionally reference a specialization (specialization_id
    // is nullable on curricula — not every program has specializations).
    // Needed by SpecializationController::destroy() to block deletion
    // when curricula still reference this specialization.
    public function curricula()
    {
        return $this->hasMany(Curriculum::class);
    }
}