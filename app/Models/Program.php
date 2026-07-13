<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    protected $fillable = [
        'department_id',
        'code',
        'name',
        'years',
        'active',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    // Program belongs to one College
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function specializations()
    {
        return $this->hasMany(Specialization::class);
    }

    // A program can have multiple curriculum versions (e.g. one per
    // effective_year / specialization). Needed by
    // ProgramController::destroy() to block deletion when curricula
    // still reference this program.
    public function curricula()
    {
        return $this->hasMany(Curriculum::class);
    }

}