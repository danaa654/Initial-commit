<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = [
        'name',
        'abbreviation',
        'description',
        'active',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function programs()
    {
        return $this->hasMany(Program::class);
    }

    public function faculties()
    {
        return $this->hasMany(Faculty::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}