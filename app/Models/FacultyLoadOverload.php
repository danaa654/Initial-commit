<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single request to raise one Faculty member's effective teaching
 * cap above their normal max_units, in a fixed 3-unit increment. See
 * the create_faculty_load_overloads_table migration docblock for the
 * full status/approval model.
 */
class FacultyLoadOverload extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'faculty_id',
        'units',
        'status',
        'requested_by',
        'reviewed_by',
        'reason',
        'decline_reason',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'reviewed_at' => 'datetime',
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

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}